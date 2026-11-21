import { useCallback, useEffect, useState } from 'react';
import { api, get, send } from '../lib/api';
import { addDays, formatDateTime, manilaToday } from '../lib/util';
import { Table } from '../components/ui/Layout';
import { Button } from '../components/ui/Button';
import { TextField } from '../components/ui/Form';
import { EmptyState, ErrorState, Pill, Skeleton, useToast } from '../components/ui/Feedback';

const fieldErrors = (err) => Object.fromEntries(Object.entries(err.details ?? {}).map(([k, v]) => [k, v[0]]));
const num = (n) => Number(n ?? 0).toLocaleString();

export function Prices({ canManage }) {
    const toast = useToast();
    const [rows, setRows] = useState(null);
    const [error, setError] = useState(null);
    const [form, setForm] = useState({ model: '', input_per_million: '', output_per_million: '', currency: 'USD' });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const load = useCallback(() => { setError(null); get('/prices').then(setRows).catch(setError); }, []);
    useEffect(load, [load]);
    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
    const add = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        try { await send('POST', '/prices', form); toast('Price added'); setForm({ ...form, model: '', input_per_million: '', output_per_million: '' }); load(); } catch (err) { setErrors(fieldErrors(err)); }
        setBusy(false);
    };
    if (error) return <ErrorState onRetry={load}>{error.message}</ErrorState>;
    if (!rows) return <Skeleton lines={4} />;
    return (
        <>
            <p>Prices per million tokens, matched by the agent's profile name (the model name in the usage table). A price is never edited: adding a new one applies from its start time, and earlier requests keep the price they were charged at.</p>
            {canManage && (
                <form onSubmit={add} className="grid items-start gap-3 sm:grid-cols-2 lg:grid-cols-5" noValidate>
                    <TextField label="Model (profile name)" value={form.model} onChange={set('model')} error={errors.model} />
                    <TextField label="Input price" type="number" step="0.0001" min="0" value={form.input_per_million} onChange={set('input_per_million')} error={errors.input_per_million} />
                    <TextField label="Output price" type="number" step="0.0001" min="0" value={form.output_per_million} onChange={set('output_per_million')} error={errors.output_per_million} />
                    <TextField label="Currency" value={form.currency} onChange={set('currency')} error={errors.currency} maxLength={3} />
                    <div className="self-end"><Button variant="primary" type="submit" disabled={busy || !form.model || form.input_per_million === '' || form.output_per_million === ''}>{busy ? 'Adding...' : 'Add price'}</Button></div>
                </form>
            )}
            {rows.length === 0 ? <EmptyState icon="file" title="No prices yet">Without a price, usage is counted in tokens but has no cost.</EmptyState> : (
                <Table caption="Price list" columns={[{ key: 'm', label: 'Model' }, { key: 'i', label: 'Input', num: true }, { key: 'o', label: 'Output', num: true }, { key: 'f', label: 'From' }]}
                    rows={rows.map((p) => ({ m: p.model, i: `${p.input_per_million} ${p.currency}`, o: `${p.output_per_million} ${p.currency}`, f: <span className="tnum">{formatDateTime(p.effective_from)} {p.in_force ? '' : <Pill tone="info">Scheduled</Pill>}</span> }))} />
            )}
        </>
    );
}

export function Usage() {
    const [range, setRange] = useState({ from: addDays(manilaToday(), -6), to: manilaToday() });
    const [rows, setRows] = useState(null);
    const [error, setError] = useState(null);
    const load = useCallback(() => { setError(null); api(`/usage/summary?from=${range.from}&to=${range.to}`).then((r) => setRows(r.data)).catch(setError); }, [range]);
    useEffect(load, [load]);
    return (
        <>
            <div className="grid items-end gap-3 sm:grid-cols-3">
                <TextField label="From" type="date" value={range.from} max={range.to} onChange={(e) => setRange({ ...range, from: e.target.value })} />
                <TextField label="To" type="date" value={range.to} min={range.from} onChange={(e) => setRange({ ...range, to: e.target.value })} />
            </div>
            {error ? <ErrorState onRetry={load}>{error.code === 'validation_failed' ? 'Check the dates.' : error.message}</ErrorState> : !rows ? <Skeleton lines={4} /> : rows.length === 0 ? <EmptyState icon="chip" title="No usage in this period">Usage appears here once people use the chatbots.</EmptyState> : (
                <Table caption="Usage by agent" columns={[{ key: 'n', label: 'Agent' }, { key: 'r', label: 'Requests', num: true }, { key: 'f', label: 'Failed', num: true }, { key: 't', label: 'Tokens', num: true }, { key: 'c', label: 'Cost', num: true }, { key: 'l', label: 'Avg speed', num: true }]}
                    rows={rows.map((r) => ({
                        n: <strong>{r.name}</strong>, r: num(r.requests), f: num(r.failures),
                        t: <span>{num(r.input_tokens + r.output_tokens)}{r.estimated_requests > 0 && <><br /><span className="sl-help">{r.estimated_requests} estimated</span></>}</span>,
                        c: r.cost === null ? <span className="sl-help">No price</span> : <span>{r.cost} {r.currency}{r.unpriced_requests > 0 && <><br /><span className="sl-help">{r.unpriced_requests} unpriced</span></>}</span>,
                        l: r.avg_latency_ms === null ? '-' : `${num(r.avg_latency_ms)} ms`,
                    }))} />
            )}
        </>
    );
}
