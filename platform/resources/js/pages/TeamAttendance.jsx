import { useCallback, useEffect, useState } from 'react';
import { api, get, send } from '../lib/api';
import { useAuth } from '../lib/auth';
import { addDays, errorText, formatDuration, formatManilaDate, formatManilaTime, manilaToday, qs } from '../lib/util';
import { Table } from '../components/ui/Layout';
import { Button } from '../components/ui/Button';
import { SelectField, TextField, TextareaField } from '../components/ui/Form';
import { Alert, EmptyState, ErrorState, Pill, Skeleton, useToast } from '../components/ui/Feedback';
import { Dialog, Menu } from '../components/ui/Overlay';

const manilaIso = (date, time) => (time ? `${date}T${time}:00+08:00` : null); // Manila is a fixed UTC+8 offset
const manilaHm = (iso) => (iso ? new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Manila' }) : '');

function CorrectionForm({ people, row, onClose, onSaved }) {
    const toast = useToast();
    const editing = !!row;
    const [form, setForm] = useState({
        user_id: row?.user.id ?? '', work_date: row?.work_date ?? addDays(manilaToday(), -1),
        hours: row ? String(Math.floor(row.active_seconds / 3600)) : '', minutes: row ? String(Math.floor((row.active_seconds % 3600) / 60)) : '',
        first_in: manilaHm(row?.first_in_at), last_out: manilaHm(row?.last_out_at), note: '',
    });
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [busy, setBusy] = useState(false);
    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setFormError(null);
        const hasDuration = form.hours !== '' || form.minutes !== '';
        const body = {
            user_id: Number(form.user_id), work_date: form.work_date, note: form.note || null,
            first_in: manilaIso(form.work_date, form.first_in), last_out: manilaIso(form.work_date, form.last_out),
            active_seconds: hasDuration ? Number(form.hours || 0) * 3600 + Number(form.minutes || 0) * 60 : null,
        };
        try {
            await send('POST', '/attendance/corrections', body);
            toast(editing ? 'Attendance corrected' : 'Attendance added');
            onSaved();
        } catch (err) {
            const d = err.details ?? {};
            if (Object.keys(d).length) setErrors(Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v[0]])));
            else setFormError(err.message);
            setBusy(false);
        }
    };

    return (
        <Dialog open title={editing ? `Correct ${row.user.name}'s day` : 'Add a missed day'} onClose={onClose}
            actions={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" type="submit" form="correction-form" disabled={busy || !form.user_id || !form.work_date}>{busy ? 'Saving...' : 'Save'}</Button></>}>
            <form id="correction-form" onSubmit={submit} className="grid gap-4" noValidate>
                <p>Times are Philippine time. Only the values you fill in are changed, and the change is recorded with your name. The employee is not notified.</p>
                {formError && <Alert tone="danger" title="Could not save">{formError}</Alert>}
                <SelectField label="Person" value={form.user_id} onChange={set('user_id')} error={errors.user_id} disabled={editing}>
                    <option value="">Choose a person</option>{people.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </SelectField>
                <TextField label="Date" type="date" max={addDays(manilaToday(), 0)} value={form.work_date} onChange={set('work_date')} error={errors.work_date} disabled={editing} />
                <div className="grid grid-cols-2 gap-4">
                    <TextField label="Time in" type="time" value={form.first_in} onChange={set('first_in')} error={errors.first_in} />
                    <TextField label="Time out" type="time" value={form.last_out} onChange={set('last_out')} error={errors.last_out} />
                </div>
                <div className="grid grid-cols-2 gap-4">
                    <TextField label="Active hours" type="number" min="0" max="24" inputMode="numeric" value={form.hours} onChange={set('hours')} error={errors.active_seconds} />
                    <TextField label="Active minutes" type="number" min="0" max="59" inputMode="numeric" value={form.minutes} onChange={set('minutes')} />
                </div>
                <TextareaField label="Note (optional)" value={form.note} onChange={set('note')} error={errors.note} />
            </form>
        </Dialog>
    );
}

function HistoryDialog({ row, onClose }) {
    const [items, setItems] = useState(null);
    const [error, setError] = useState(null);
    useEffect(() => { get(`/attendance/days/${row.id}/corrections`).then(setItems).catch(setError); }, [row.id]);
    return (
        <Dialog open title={`Corrections for ${row.user.name}, ${row.work_date}`} onClose={onClose} actions={<Button variant="primary" onClick={onClose}>Close</Button>}>
            {error ? <Alert tone="danger" title="Could not load">{errorText(error)}</Alert> : !items ? <Skeleton lines={3} /> : items.length === 0 ? <p>No corrections. This is the recorded time.</p> : (
                <ul className="grid gap-3" style={{ listStyle: 'none', padding: 0, margin: 0 }}>
                    {items.map((c) => (
                        <li key={c.id} className="grid gap-1">
                            <strong>{c.kind === 'create' ? 'Added' : 'Changed'} by {c.actor.name}</strong>
                            <span className="sl-help tnum">{new Date(c.at).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' })}</span>
                            <span>Active time {c.before ? `${formatDuration(c.before.active_seconds)} to ` : ''}{formatDuration(c.after.active_seconds)}</span>
                            {c.note && <span className="sl-help">Note: {c.note}</span>}
                        </li>
                    ))}
                </ul>
            )}
        </Dialog>
    );
}

export default function TeamAttendance() {
    const { user: me } = useAuth();
    const [filters, setFilters] = useState({ from: addDays(manilaToday(), -6), to: manilaToday(), user_id: '', department_id: '', page: 1 });
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const [people, setPeople] = useState([]);
    const [departments, setDepartments] = useState([]);
    const [dialog, setDialog] = useState(null);

    const load = useCallback(() => {
        setError(null);
        api(`/attendance${qs({ ...filters, per_page: 20 })}`).then(setResult).catch(setError);
    }, [filters]);
    useEffect(load, [load]);
    useEffect(() => {
        get('/departments').then(setDepartments).catch(() => {});
        api('/users?per_page=100&active=1').then((r) => setPeople(r.data.filter((p) => p.id !== me.id))).catch(() => {});
    }, [me.id]);

    const set = (k) => (e) => setFilters((f) => ({ ...f, [k]: e.target.value, page: 1 }));
    const meta = result?.meta;
    const rows = result?.data ?? [];
    return (
        <>
            <div className="flex flex-wrap items-center gap-3">
                <h1 className="text-4xl">Team attendance</h1>
                <Button variant="primary" icon="plus" className="ml-auto" onClick={() => setDialog({ type: 'new' })}>Add a missed day</Button>
            </div>
            <p>Official records in Philippine time. Corrections are recorded with your name and the employee is not notified.</p>

            <div className="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <TextField label="From" type="date" value={filters.from} max={filters.to} onChange={set('from')} />
                <TextField label="To" type="date" value={filters.to} min={filters.from} onChange={set('to')} />
                <SelectField label="Person" value={filters.user_id} onChange={set('user_id')}>
                    <option value="">Everyone</option>{people.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </SelectField>
                <SelectField label="Department" value={filters.department_id} onChange={set('department_id')}>
                    <option value="">All departments</option>{departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                </SelectField>
            </div>

            {error ? <ErrorState onRetry={load}>{error.code === 'validation_failed' ? 'Check the dates and try again.' : error.message}</ErrorState> : !result ? <Skeleton lines={6} /> : rows.length === 0 ? (
                <EmptyState icon="clock" title="No attendance found">Try a wider date range or another person.</EmptyState>
            ) : (
                <>
                    <Table caption="Team attendance" columns={[
                        { key: 'person', label: 'Person' }, { key: 'date', label: 'Date' }, { key: 'in', label: 'In' }, { key: 'out', label: 'Out' },
                        { key: 'active', label: 'Active', num: true }, { key: 'idle', label: 'Idle', num: true }, { key: 'note', label: '' }, { key: 'actions', label: '' },
                    ]} rows={rows.map((d) => ({
                        person: <span><strong>{d.user.name}</strong><br /><span className="sl-help">{d.user.username}</span></span>,
                        date: formatManilaDate(d.work_date), in: formatManilaTime(d.first_in_at), out: formatManilaTime(d.last_out_at),
                        active: formatDuration(d.active_seconds), idle: formatDuration(d.idle_seconds),
                        note: d.is_corrected ? <Pill tone="info">Corrected</Pill> : null,
                        actions: <Menu label={`Actions for ${d.user.name} on ${d.work_date}`} items={[
                            { label: 'Correct', icon: 'edit', onSelect: () => setDialog({ type: 'edit', row: d }) },
                            { label: 'Correction history', icon: 'clock', onSelect: () => setDialog({ type: 'history', row: d }) },
                        ]} />,
                    }))} />
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="sl-help tnum">{meta.total} records · page {meta.page} of {meta.last_page}</span>
                        <div className="sl-btn-row ml-auto">
                            <Button size="sm" disabled={meta.page <= 1} onClick={() => setFilters((f) => ({ ...f, page: f.page - 1 }))}>Previous</Button>
                            <Button size="sm" disabled={meta.page >= meta.last_page} onClick={() => setFilters((f) => ({ ...f, page: f.page + 1 }))}>Next</Button>
                        </div>
                    </div>
                </>
            )}

            {(dialog?.type === 'new' || dialog?.type === 'edit') && (
                <CorrectionForm people={people} row={dialog.row} onClose={() => setDialog(null)} onSaved={() => { setDialog(null); load(); }} />
            )}
            {dialog?.type === 'history' && <HistoryDialog row={dialog.row} onClose={() => setDialog(null)} />}
        </>
    );
}
