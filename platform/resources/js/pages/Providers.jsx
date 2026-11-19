import { useCallback, useEffect, useState } from 'react';
import { api, get, send } from '../lib/api';
import { useAuth } from '../lib/auth';
import { addDays, errorText, formatDateTime, manilaToday } from '../lib/util';
import { Table, Tabs } from '../components/ui/Layout';
import { Button } from '../components/ui/Button';
import { SelectField, TextField } from '../components/ui/Form';
import { Alert, EmptyState, ErrorState, Pill, Skeleton, useToast } from '../components/ui/Feedback';
import { ConfirmDialog, Dialog, Menu } from '../components/ui/Overlay';

const STATUS = {
    available: ['success', 'Working'], unknown: ['muted', 'Not checked'], unavailable: ['warning', 'Not responding'],
    reauth_required: ['danger', 'Needs reconnecting'], quota_exhausted: ['danger', 'Quota used up'],
};
const KIND = { claude: 'Claude', chatgpt: 'ChatGPT' };
const fieldErrors = (err) => Object.fromEntries(Object.entries(err.details ?? {}).map(([k, v]) => [k, v[0]]));
const num = (n) => Number(n ?? 0).toLocaleString();

function QuotaCell({ q }) {
    if (!q.known) return <span className="sl-help">Unknown. The provider gives no figure.</span>;
    return (
        <span className="grid gap-1" style={{ minWidth: 120 }}>
            <span className="tnum">{num(q.used)} of {num(q.limit)} ({q.percent}%)</span>
            <span style={{ height: 4, borderRadius: 99, background: 'var(--slate-200)', overflow: 'hidden' }} role="progressbar" aria-valuenow={q.percent} aria-valuemin={0} aria-valuemax={100} aria-label="Quota used">
                <i style={{ display: 'block', height: '100%', width: `${q.percent}%`, background: q.percent >= 90 ? 'var(--red-600)' : q.percent >= 80 ? 'var(--amber-700)' : 'var(--brand)' }} />
            </span>
        </span>
    );
}

function ConnectionForm({ onClose, onSaved }) {
    const toast = useToast();
    const [form, setForm] = useState({ name: '', kind: 'claude', credentials: '' });
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [busy, setBusy] = useState(false);
    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setFormError(null);
        try { await send('POST', '/providers', form); toast('Connection added'); onSaved(); } catch (err) {
            if (Object.keys(err.details ?? {}).length) setErrors(fieldErrors(err)); else setFormError(err.message);
            setBusy(false);
        }
    };
    return (
        <Dialog open title="Add a provider connection" onClose={onClose}
            actions={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" type="submit" form="conn-form" disabled={busy || !form.name || !form.credentials}>{busy ? 'Saving...' : 'Add'}</Button></>}>
            <form id="conn-form" onSubmit={submit} className="grid gap-4" noValidate>
                {formError && <Alert tone="danger" title="Could not save">{formError}</Alert>}
                <TextField label="Name" value={form.name} onChange={set('name')} error={errors.name} placeholder="Company Claude account" />
                <SelectField label="Provider" value={form.kind} onChange={set('kind')} error={errors.kind}><option value="claude">Claude</option><option value="chatgpt">ChatGPT</option></SelectField>
                <TextField label="Access key or token" type="password" value={form.credentials} onChange={set('credentials')} error={errors.credentials} autoComplete="off" help="Stored encrypted. It is never shown again, to anyone." />
            </form>
        </Dialog>
    );
}

function OneFieldDialog({ title, label, type = 'password', help, action, onClose, onSubmit }) {
    const [value, setValue] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try { await onSubmit(value); } catch (err) { setError(errorText(err)); setBusy(false); }
    };
    return (
        <Dialog open title={title} onClose={onClose} actions={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" type="submit" form="one-form" disabled={busy || !value}>{busy ? 'Saving...' : action}</Button></>}>
            <form id="one-form" onSubmit={submit} className="grid gap-4" noValidate>
                {error && <Alert tone="danger" title="Could not save">{error}</Alert>}
                <TextField label={label} type={type} value={value} onChange={(e) => setValue(e.target.value)} help={help} autoComplete="off" autoFocus />
            </form>
        </Dialog>
    );
}

function QuotaDialog({ provider, onClose, onSaved }) {
    const [form, setForm] = useState({ limit: provider.quota.limit ?? '', used: provider.quota.used ?? '', resets_at: '' });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        try { await send('POST', `/providers/${provider.id}/quota`, { limit: Number(form.limit), used: Number(form.used), resets_at: form.resets_at || null }); onSaved(); } catch (err) { setErrors(fieldErrors(err)); setBusy(false); }
    };
    return (
        <Dialog open title={`Record quota for ${provider.name}`} onClose={onClose} actions={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" type="submit" form="quota-form" disabled={busy || !form.limit || form.used === ''}>{busy ? 'Saving...' : 'Save'}</Button></>}>
            <form id="quota-form" onSubmit={submit} className="grid gap-4" noValidate>
                <p>Use this when the provider shows you its usage but offers no automatic way to read it. Admins are alerted at 80%, 90% and 100%.</p>
                <TextField label="Quota limit (tokens)" type="number" min="1" value={form.limit} onChange={set('limit')} error={errors.limit} />
                <TextField label="Used so far (tokens)" type="number" min="0" value={form.used} onChange={set('used')} error={errors.used} />
                <TextField label="Resets at (optional)" type="datetime-local" value={form.resets_at} onChange={set('resets_at')} error={errors.resets_at} />
            </form>
        </Dialog>
    );
}

function Connections({ canManage }) {
    const toast = useToast();
    const [rows, setRows] = useState(null);
    const [error, setError] = useState(null);
    const [notice, setNotice] = useState(null);
    const [dialog, setDialog] = useState(null);

    const load = useCallback(() => { setError(null); get('/providers').then(setRows).catch(setError); }, []);
    useEffect(load, [load]);

    const act = async (fn, ok) => { setNotice(null); try { const r = await fn(); if (ok) toast(typeof ok === 'function' ? ok(r) : ok); load(); } catch (e) { setNotice(errorText(e)); } };
    const move = (i, d) => {
        const ids = rows.map((r) => r.id);
        [ids[i], ids[i + d]] = [ids[i + d], ids[i]];
        act(() => send('POST', '/providers/reorder', { ids }), 'Order saved');
    };

    if (error) return <ErrorState onRetry={load}>{error.message}</ErrorState>;
    if (!rows) return <Skeleton lines={5} />;
    return (
        <>
            <div className="flex flex-wrap items-center gap-3">
                <p style={{ margin: 0 }}>Requests use the first working connection in this order. If one fails, the next takes over, and traffic returns to the first as soon as it recovers. Employees never see any of this.</p>
                {canManage && <Button variant="primary" icon="plus" className="ml-auto" onClick={() => setDialog({ type: 'add' })}>Add connection</Button>}
            </div>
            {notice && <Alert tone="danger" title="That did not work">{notice}</Alert>}
            {rows.length === 0 ? <EmptyState icon="layers" title="No provider connections yet">{canManage ? 'Add the first connection to let people use the assistant.' : 'A Super Admin needs to add one.'}</EmptyState> : (
                <Table caption="Provider connections" columns={[
                    { key: 'order', label: '#', num: true }, { key: 'name', label: 'Connection' }, { key: 'status', label: 'Status' }, { key: 'quota', label: 'Quota' },
                    { key: 'today', label: 'Today', num: true }, ...(canManage ? [{ key: 'actions', label: '' }] : []),
                ]} rows={rows.map((p, i) => ({
                    order: i + 1,
                    name: <span><strong>{p.name}</strong><br /><span className="sl-help">{KIND[p.kind]}{p.is_enabled ? '' : ' · disabled'}</span></span>,
                    status: <span className="grid gap-1"><Pill tone={STATUS[p.status]?.[0] ?? 'muted'}>{STATUS[p.status]?.[1] ?? p.status}</Pill>{p.status_checked_at && <span className="sl-help tnum">Checked {formatDateTime(p.status_checked_at)}</span>}</span>,
                    quota: <QuotaCell q={p.quota} />,
                    today: <span className="tnum">{num(p.usage_today.tokens)} tokens<br /><span className="sl-help">{p.usage_today.requests} requests, {p.usage_today.failures} failed</span></span>,
                    actions: <Menu label={`Actions for ${p.name}`} items={[
                        ...(i > 0 ? [{ label: 'Move up', icon: 'up', onSelect: () => move(i, -1) }] : []),
                        ...(i < rows.length - 1 ? [{ label: 'Move down', icon: 'chev-down', onSelect: () => move(i, 1) }] : []),
                        { label: p.is_enabled ? 'Disable' : 'Enable', icon: 'eye', onSelect: () => act(() => send('PATCH', `/providers/${p.id}`, { is_enabled: !p.is_enabled }), p.is_enabled ? 'Disabled' : 'Enabled') },
                        { label: 'Check now', icon: 'refresh', onSelect: () => act(() => send('POST', `/providers/${p.id}/check`), (r) => r.message) },
                        { label: 'Reconnect', icon: 'sliders', onSelect: () => setDialog({ type: 'reauth', p }) },
                        { label: 'Record quota', icon: 'chip', onSelect: () => setDialog({ type: 'quota', p }) },
                        '-',
                        { label: 'Remove', icon: 'trash', danger: true, onSelect: () => setDialog({ type: 'remove', p }) },
                    ]} />,
                }))} />
            )}
            {dialog?.type === 'add' && <ConnectionForm onClose={() => setDialog(null)} onSaved={() => { setDialog(null); load(); }} />}
            {dialog?.type === 'reauth' && <OneFieldDialog title={`Reconnect ${dialog.p.name}`} label="New access key or token" help="Replaces the saved one. Stored encrypted and never shown again." action="Reconnect" onClose={() => setDialog(null)}
                onSubmit={async (v) => { await send('POST', `/providers/${dialog.p.id}/reauthenticate`, { credentials: v }); toast('Reconnected'); setDialog(null); load(); }} />}
            {dialog?.type === 'quota' && <QuotaDialog provider={dialog.p} onClose={() => setDialog(null)} onSaved={() => { setDialog(null); toast('Quota recorded'); load(); }} />}
            <ConfirmDialog open={dialog?.type === 'remove'} onCancel={() => setDialog(null)} confirmLabel="Remove" title={`Remove ${dialog?.p?.name ?? 'connection'}?`}
                onConfirm={() => { const p = dialog.p; setDialog(null); act(() => send('DELETE', `/providers/${p.id}`), 'Connection removed'); }}>
                Its saved key is deleted and it stops taking requests. Past usage records are kept.
            </ConfirmDialog>
        </>
    );
}

function Prices({ canManage }) {
    const toast = useToast();
    const [rows, setRows] = useState(null);
    const [error, setError] = useState(null);
    const [form, setForm] = useState({ kind: 'claude', model: '', input_per_million: '', output_per_million: '', currency: 'USD' });
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
            <p>Prices per million tokens. A price is never edited: adding a new one applies from its start time, and earlier requests keep the price they were charged at.</p>
            {canManage && (
                <form onSubmit={add} className="grid items-start gap-3 sm:grid-cols-2 lg:grid-cols-6" noValidate>
                    <SelectField label="Provider" value={form.kind} onChange={set('kind')}><option value="claude">Claude</option><option value="chatgpt">ChatGPT</option></SelectField>
                    <TextField label="Model" value={form.model} onChange={set('model')} error={errors.model} />
                    <TextField label="Input price" type="number" step="0.0001" min="0" value={form.input_per_million} onChange={set('input_per_million')} error={errors.input_per_million} />
                    <TextField label="Output price" type="number" step="0.0001" min="0" value={form.output_per_million} onChange={set('output_per_million')} error={errors.output_per_million} />
                    <TextField label="Currency" value={form.currency} onChange={set('currency')} error={errors.currency} maxLength={3} />
                    <div className="self-end"><Button variant="primary" type="submit" disabled={busy || !form.model || form.input_per_million === '' || form.output_per_million === ''}>{busy ? 'Adding...' : 'Add price'}</Button></div>
                </form>
            )}
            {rows.length === 0 ? <EmptyState icon="file" title="No prices yet">Without a price, usage is counted in tokens but has no cost.</EmptyState> : (
                <Table caption="Price list" columns={[{ key: 'k', label: 'Provider' }, { key: 'm', label: 'Model' }, { key: 'i', label: 'Input', num: true }, { key: 'o', label: 'Output', num: true }, { key: 'f', label: 'From' }]}
                    rows={rows.map((p) => ({ k: KIND[p.kind], m: p.model, i: `${p.input_per_million} ${p.currency}`, o: `${p.output_per_million} ${p.currency}`, f: <span className="tnum">{formatDateTime(p.effective_from)} {p.in_force ? '' : <Pill tone="info">Scheduled</Pill>}</span> }))} />
            )}
        </>
    );
}

function Usage() {
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
            {error ? <ErrorState onRetry={load}>{error.code === 'validation_failed' ? 'Check the dates.' : error.message}</ErrorState> : !rows ? <Skeleton lines={4} /> : rows.length === 0 ? <EmptyState icon="chip" title="No usage in this period">Usage appears here once people use the assistant.</EmptyState> : (
                <Table caption="Usage by provider" columns={[{ key: 'n', label: 'Connection' }, { key: 'r', label: 'Requests', num: true }, { key: 'f', label: 'Failed', num: true }, { key: 't', label: 'Tokens', num: true }, { key: 'c', label: 'Cost', num: true }, { key: 'l', label: 'Avg speed', num: true }]}
                    rows={rows.map((r) => ({
                        n: <span><strong>{r.name}</strong><br /><span className="sl-help">{KIND[r.kind]}</span></span>, r: num(r.requests), f: num(r.failures),
                        t: <span>{num(r.input_tokens + r.output_tokens)}{r.estimated_requests > 0 && <><br /><span className="sl-help">{r.estimated_requests} estimated</span></>}</span>,
                        c: r.cost === null ? <span className="sl-help">No price</span> : <span>{r.cost} {r.currency}{r.unpriced_requests > 0 && <><br /><span className="sl-help">{r.unpriced_requests} unpriced</span></>}</span>,
                        l: r.avg_latency_ms === null ? '-' : `${num(r.avg_latency_ms)} ms`,
                    }))} />
            )}
        </>
    );
}

// Admins monitor (read-only); only a Super Admin can change connections or prices. The server enforces this.
export default function Providers() {
    const { user } = useAuth();
    const canManage = user.role === 'super_admin';
    const [tab, setTab] = useState('connections');
    return (
        <>
            <h1 className="text-4xl">AI providers</h1>
            {!canManage && <Alert tone="info" title="View only">Only a Super Admin can change connections or prices.</Alert>}
            <Tabs tabs={[{ id: 'connections', label: 'Connections' }, { id: 'usage', label: 'Usage' }, { id: 'prices', label: 'Prices' }]} value={tab} onChange={setTab} />
            {tab === 'connections' ? <Connections canManage={canManage} /> : tab === 'usage' ? <Usage /> : <Prices canManage={canManage} />}
        </>
    );
}
