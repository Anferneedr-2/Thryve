import { useCallback, useEffect, useState } from 'react';
import { api, get, send } from '../lib/api';
import { useAuth } from '../lib/auth';
import { errorText, formatDateTime, generatePassword, qs } from '../lib/util';
import { Table } from '../components/ui/Layout';
import { Button } from '../components/ui/Button';
import { Checkbox, SelectField, TextField } from '../components/ui/Form';
import { Alert, EmptyState, ErrorState, Pill, Skeleton, useToast } from '../components/ui/Feedback';
import { ConfirmDialog, Dialog, Menu } from '../components/ui/Overlay';
import { ROLE_LABEL } from '../components/AppLayout';

const fieldErrors = (err) => Object.fromEntries(Object.entries(err.details ?? {}).map(([k, v]) => [k, v[0]]));

// Convenience only: the server decides. Super Admin manages everyone; Admin manages Employees.
const canManage = (actor, target) => actor.role === 'super_admin' || (actor.role === 'admin' && target.role === 'employee');
const assignableRoles = (actor) => (actor.role === 'super_admin' ? ['employee', 'admin', 'super_admin'] : ['employee']);

function PasswordField({ label, value, onChange, error }) {
    const [shown, setShown] = useState(false);
    return (
        <div className="grid gap-2">
            <TextField label={label} type={shown ? 'text' : 'password'} value={value} onChange={onChange} error={error} help="At least 12 characters." autoComplete="new-password" />
            <div className="sl-btn-row">
                <Button size="sm" onClick={() => { onChange({ target: { value: generatePassword() } }); setShown(true); }}>Generate one</Button>
                <Button size="sm" variant="ghost" onClick={() => setShown(!shown)}>{shown ? 'Hide' : 'Show'}</Button>
            </div>
        </div>
    );
}

function PersonForm({ person, departments, onClose, onSaved }) {
    const { user: me } = useAuth();
    const toast = useToast();
    const editing = !!person;
    const [form, setForm] = useState({
        username: '', name: person?.name ?? '', email: person?.email ?? '', password: '', role: person?.role ?? 'employee',
        department_ids: person?.departments.map((d) => d.id) ?? [], daily_token_limit: person?.daily_token_limit ?? '',
    });
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [busy, setBusy] = useState(false);
    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
    const toggleDept = (id) => setForm((f) => ({ ...f, department_ids: f.department_ids.includes(id) ? f.department_ids.filter((x) => x !== id) : [...f.department_ids, id] }));

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setFormError(null);
        const body = {
            name: form.name, email: form.email || null, role: form.role, department_ids: form.department_ids,
            daily_token_limit: form.daily_token_limit === '' ? null : Number(form.daily_token_limit),
        };
        try {
            if (editing) await send('PATCH', `/users/${person.id}`, body);
            else await send('POST', '/users', { ...body, username: form.username, password: form.password });
            toast(editing ? 'Changes saved' : 'Person added');
            onSaved();
        } catch (err) {
            if (Object.keys(err.details ?? {}).length) setErrors(fieldErrors(err));
            else setFormError(err.message);
            setBusy(false);
        }
    };

    const isSelf = editing && person.id === me.id;
    return (
        <Dialog open title={editing ? `Edit ${person.name}` : 'Add person'} onClose={onClose}
            actions={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" type="submit" form="person-form" disabled={busy || !form.name.trim() || (!editing && (!form.username || !form.password))}>{busy ? 'Saving...' : 'Save'}</Button></>}>
            <form id="person-form" onSubmit={submit} className="grid gap-4" noValidate>
                {formError && <Alert tone="danger" title="Could not save">{formError}</Alert>}
                {!editing && <TextField label="Username" help="Letters, numbers, dots, dashes, underscores. Cannot be changed later." value={form.username} onChange={set('username')} error={errors.username} autoComplete="off" autoCapitalize="none" />}
                <TextField label="Full name" value={form.name} onChange={set('name')} error={errors.name} />
                <TextField label="Email (optional)" type="email" value={form.email ?? ''} onChange={set('email')} error={errors.email} />
                {!editing && <PasswordField label="Temporary password" value={form.password} onChange={set('password')} error={errors.password} />}
                <SelectField label="Role" value={form.role} onChange={set('role')} error={errors.role} disabled={isSelf || assignableRoles(me).length === 1}>
                    {assignableRoles(me).concat(editing && !assignableRoles(me).includes(form.role) ? [form.role] : []).map((r) => <option key={r} value={r}>{ROLE_LABEL[r]}</option>)}
                </SelectField>
                <TextField label="Daily token limit (optional)" type="number" min="0" inputMode="numeric" help="Leave empty to use the default." value={form.daily_token_limit ?? ''} onChange={set('daily_token_limit')} error={errors.daily_token_limit} />
                <fieldset className="grid gap-2" style={{ border: 0, padding: 0, margin: 0 }}>
                    <legend className="sl-field" style={{ fontSize: 13, fontWeight: 600, color: 'var(--sl-heading)', marginBottom: 6 }}>Departments</legend>
                    {departments.length === 0 ? <span className="sl-help">No departments yet. Add some on the Departments page.</span> : departments.map((d) => (
                        <Checkbox key={d.id} label={d.name} checked={form.department_ids.includes(d.id)} onChange={() => toggleDept(d.id)} />
                    ))}
                </fieldset>
            </form>
        </Dialog>
    );
}

function ResetPasswordDialog({ person, onClose }) {
    const toast = useToast();
    const [password, setPassword] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try { await send('POST', `/users/${person.id}/reset-password`, { password }); toast(`Password reset for ${person.name}`); onClose(); } catch (err) { setError(errorText(err)); setBusy(false); }
    };

    return (
        <Dialog open title={`Reset password for ${person.name}`} onClose={onClose}
            actions={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" type="submit" form="reset-form" disabled={busy || password.length < 12}>{busy ? 'Saving...' : 'Reset password'}</Button></>}>
            <form id="reset-form" onSubmit={submit} className="grid gap-4" noValidate>
                <p>They will be signed out and must use the new password. Share it with them securely.</p>
                {error && <Alert tone="danger" title="Could not reset">{error}</Alert>}
                <PasswordField label="New password" value={password} onChange={(e) => setPassword(e.target.value)} />
            </form>
        </Dialog>
    );
}

// Which extra chatbots an employee may use. The default chatbot is always on; Admins and Super Admins use every enabled one.
function ChatbotAccessDialog({ person, onClose }) {
    const toast = useToast();
    const [data, setData] = useState(null);
    const [picked, setPicked] = useState([]);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        get(`/users/${person.id}/agents`).then((d) => { setData(d); setPicked(d.agents.filter((a) => !a.is_default && a.assigned).map((a) => a.id)); }).catch((e) => setError(errorText(e)));
    }, [person.id]);
    const toggle = (id) => setPicked((l) => (l.includes(id) ? l.filter((x) => x !== id) : [...l, id]));
    const save = async () => {
        setBusy(true); setError(null);
        try { await send('PUT', `/users/${person.id}/agents`, { agent_ids: picked }); toast(`Chatbot access saved for ${person.name}`); onClose(); } catch (err) { setError(errorText(err)); setBusy(false); }
    };
    const extra = data?.agents.filter((a) => !a.is_default) ?? [];
    return (
        <Dialog open title={`Chatbot access for ${person.name}`} onClose={onClose}
            actions={<><Button onClick={onClose}>Cancel</Button>{!data?.all_access && <Button variant="primary" disabled={busy || !data} onClick={save}>{busy ? 'Saving...' : 'Save'}</Button>}</>}>
            <div className="grid gap-3">
                {error && <Alert tone="danger" title="Could not load or save">{error}</Alert>}
                {!data && !error && <Skeleton lines={3} />}
                {data?.all_access && <p>Admins and Super Admins can use every enabled chatbot.</p>}
                {data && !data.all_access && (
                    <>
                        {data.agents.filter((a) => a.is_default).map((a) => (
                            <label key={a.id} className="sl-opt"><input type="checkbox" className="sl-cb" checked disabled readOnly /><span>{a.name} <Pill tone="info">Everyone</Pill>{a.description && <><br /><span className="sl-help">{a.description}</span></>}</span></label>
                        ))}
                        {extra.length === 0 && <p className="sl-help">No other chatbots yet. Add agents under AI agents.</p>}
                        {extra.map((a) => (
                            <label key={a.id} className="sl-opt"><input type="checkbox" className="sl-cb" checked={picked.includes(a.id)} onChange={() => toggle(a.id)} /><span>{a.name}{!a.is_enabled && <> <Pill>Disabled</Pill></>}{a.description && <><br /><span className="sl-help">{a.description}</span></>}</span></label>
                        ))}
                    </>
                )}
            </div>
        </Dialog>
    );
}

export default function People() {
    const { user: me } = useAuth();
    const toast = useToast();
    const [filters, setFilters] = useState({ search: '', role: '', active: '', department_id: '', page: 1 });
    const [search, setSearch] = useState('');
    const [result, setResult] = useState(null); // {data, meta}
    const [error, setError] = useState(null);
    const [departments, setDepartments] = useState([]);
    const [dialog, setDialog] = useState(null); // {type, person?}
    const [notice, setNotice] = useState(null);

    // Debounce the search box so the list is not reloaded on every keystroke.
    useEffect(() => {
        const t = setTimeout(() => setFilters((f) => (f.search === search ? f : { ...f, search, page: 1 })), 300);
        return () => clearTimeout(t);
    }, [search]);

    const load = useCallback(() => {
        setError(null);
        api(`/users${qs({ ...filters, per_page: 15 })}`).then(setResult).catch(setError);
    }, [filters]);
    useEffect(load, [load]);
    useEffect(() => { get('/departments').then(setDepartments).catch(() => {}); }, []);

    const act = async (fn, okMessage) => {
        setNotice(null);
        try { await fn(); toast(okMessage); load(); } catch (err) { setNotice(errorText(err)); }
    };
    const setFilter = (k) => (e) => setFilters((f) => ({ ...f, [k]: e.target.value, page: 1 }));

    const rows = (result?.data ?? []).map((p) => {
        const items = [];
        if (canManage(me, p)) {
            items.push({ label: 'Edit', icon: 'edit', onSelect: () => setDialog({ type: 'edit', person: p }) });
            items.push({ label: 'Chatbot access', icon: 'chat', onSelect: () => setDialog({ type: 'access', person: p }) });
            items.push({ label: 'Reset password', icon: 'sliders', onSelect: () => setDialog({ type: 'reset', person: p }) });
            if (me.role === 'super_admin' && p.is_active && p.id !== me.id) items.push({ label: 'End session', icon: 'x', onSelect: () => setDialog({ type: 'terminate', person: p }) });
            if (p.id !== me.id) {
                items.push('-');
                items.push(p.is_active
                    ? { label: 'Deactivate', icon: 'trash', danger: true, onSelect: () => setDialog({ type: 'deactivate', person: p }) }
                    : { label: 'Reactivate', icon: 'refresh', onSelect: () => act(() => send('POST', `/users/${p.id}/reactivate`), `${p.name} reactivated`) });
            }
        }
        return {
            name: <span><strong>{p.name}</strong><br /><span className="sl-help">{p.username}</span></span>,
            role: <Pill tone={p.role === 'employee' ? 'muted' : 'info'}>{ROLE_LABEL[p.role]}</Pill>,
            departments: p.departments.length ? <span className="flex flex-wrap gap-1">{p.departments.map((d) => <Pill key={d.id}>{d.name}</Pill>)}</span> : <span className="sl-help">None</span>,
            status: <Pill tone={p.is_active ? 'success' : 'danger'}>{p.is_active ? 'Active' : 'Deactivated'}</Pill>,
            last: <span className="tnum">{formatDateTime(p.last_login_at)}</span>,
            actions: items.length ? <Menu label={`Actions for ${p.name}`} items={items} /> : null,
        };
    });

    const meta = result?.meta;
    const filtered = filters.search || filters.role || filters.active || filters.department_id;
    return (
        <>
            <div className="flex flex-wrap items-center gap-3">
                <h1 className="text-4xl">People</h1>
                <Button variant="primary" icon="plus" className="ml-auto" onClick={() => setDialog({ type: 'create' })}>Add person</Button>
            </div>

            <div className="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <TextField label="Search" placeholder="Name, username or email" value={search} onChange={(e) => setSearch(e.target.value)} />
                <SelectField label="Role" value={filters.role} onChange={setFilter('role')}>
                    <option value="">All roles</option>{Object.entries(ROLE_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </SelectField>
                <SelectField label="Status" value={filters.active} onChange={setFilter('active')}>
                    <option value="">All</option><option value="1">Active</option><option value="0">Deactivated</option>
                </SelectField>
                <SelectField label="Department" value={filters.department_id} onChange={setFilter('department_id')}>
                    <option value="">All departments</option>{departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                </SelectField>
            </div>

            {notice && <Alert tone="danger" title="That did not work">{notice}</Alert>}
            {error ? <ErrorState onRetry={load}>{error.message}</ErrorState> : !result ? <Skeleton lines={6} /> : rows.length === 0 ? (
                <EmptyState icon="users" title={filtered ? 'No one matches those filters' : 'No people yet'}>{filtered ? 'Try clearing a filter.' : 'Add the first person to get started.'}</EmptyState>
            ) : (
                <>
                    <Table caption="People" columns={[
                        { key: 'name', label: 'Person' }, { key: 'role', label: 'Role' }, { key: 'departments', label: 'Departments' },
                        { key: 'status', label: 'Status' }, { key: 'last', label: 'Last sign-in' }, { key: 'actions', label: '' },
                    ]} rows={rows} />
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="sl-help tnum">{meta.total} {meta.total === 1 ? 'person' : 'people'} · page {meta.page} of {meta.last_page}</span>
                        <div className="sl-btn-row ml-auto">
                            <Button size="sm" disabled={meta.page <= 1} onClick={() => setFilters((f) => ({ ...f, page: f.page - 1 }))}>Previous</Button>
                            <Button size="sm" disabled={meta.page >= meta.last_page} onClick={() => setFilters((f) => ({ ...f, page: f.page + 1 }))}>Next</Button>
                        </div>
                    </div>
                </>
            )}

            {(dialog?.type === 'create' || dialog?.type === 'edit') && (
                <PersonForm person={dialog.person} departments={departments} onClose={() => setDialog(null)} onSaved={() => { setDialog(null); load(); }} />
            )}
            {dialog?.type === 'reset' && <ResetPasswordDialog person={dialog.person} onClose={() => setDialog(null)} />}
            {dialog?.type === 'access' && <ChatbotAccessDialog person={dialog.person} onClose={() => setDialog(null)} />}
            <ConfirmDialog open={dialog?.type === 'deactivate'} onCancel={() => setDialog(null)} confirmLabel="Deactivate" title={`Deactivate ${dialog?.person?.name ?? ''}?`}
                onConfirm={() => { const p = dialog.person; setDialog(null); act(() => send('POST', `/users/${p.id}/deactivate`), `${p.name} deactivated`); }}>
                They are signed out and cannot sign in. Their history is kept and you can reactivate them later.
            </ConfirmDialog>
            <ConfirmDialog open={dialog?.type === 'terminate'} onCancel={() => setDialog(null)} confirmLabel="End session" title={`End ${dialog?.person?.name ?? ''}'s session?`}
                onConfirm={() => { const p = dialog.person; setDialog(null); act(() => send('POST', `/users/${p.id}/terminate-session`), `${p.name}'s session ended`); }}>
                They are signed out immediately and can sign in again.
            </ConfirmDialog>
        </>
    );
}
