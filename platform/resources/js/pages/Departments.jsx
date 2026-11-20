import { useEffect, useState } from 'react';
import { get, send } from '../lib/api';
import { errorText } from '../lib/util';
import { Table } from '../components/ui/Layout';
import { Button } from '../components/ui/Button';
import { TextField } from '../components/ui/Form';
import { Alert, EmptyState, ErrorState, Skeleton, useToast } from '../components/ui/Feedback';
import { ConfirmDialog, Dialog, Menu } from '../components/ui/Overlay';

function DepartmentForm({ dept, onClose, onSaved }) {
    const toast = useToast();
    const [form, setForm] = useState({ name: dept?.name ?? '', description: dept?.description ?? '' });
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [busy, setBusy] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setFormError(null);
        try {
            await (dept ? send('PATCH', `/departments/${dept.id}`, form) : send('POST', '/departments', form));
            toast(dept ? 'Department updated' : 'Department added');
            onSaved();
        } catch (err) {
            const d = err.details ?? {};
            if (Object.keys(d).length) setErrors(Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v[0]])));
            else setFormError(err.message);
            setBusy(false);
        }
    };

    return (
        <Dialog open title={dept ? 'Edit department' : 'Add department'} onClose={onClose}
            actions={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" type="submit" form="dept-form" disabled={busy || !form.name.trim()}>{busy ? 'Saving...' : 'Save'}</Button></>}>
            <form id="dept-form" onSubmit={submit} className="grid gap-4" noValidate>
                {formError && <Alert tone="danger" title="Could not save">{formError}</Alert>}
                <TextField label="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={errors.name} autoFocus />
                <TextField label="Description (optional)" value={form.description ?? ''} onChange={(e) => setForm({ ...form, description: e.target.value })} error={errors.description} />
            </form>
        </Dialog>
    );
}

export default function Departments() {
    const toast = useToast();
    const [rows, setRows] = useState(null);
    const [error, setError] = useState(null);
    const [editing, setEditing] = useState(null); // null | 'new' | dept
    const [deleting, setDeleting] = useState(null);
    const [actionError, setActionError] = useState(null);

    const load = () => { setError(null); get('/departments').then(setRows).catch(setError); };
    useEffect(load, []);

    const remove = async () => {
        const d = deleting;
        setDeleting(null);
        setActionError(null);
        try { await send('DELETE', `/departments/${d.id}`); toast('Department deleted'); load(); } catch (err) { setActionError(errorText(err)); }
    };

    return (
        <>
            <div className="flex flex-wrap items-center gap-3">
                <p style={{ margin: 0 }}>Departments group people and control access. They do not set token limits.</p>
                <Button variant="primary" icon="plus" className="ml-auto" onClick={() => setEditing('new')}>Add department</Button>
            </div>
            {actionError && <Alert tone="danger" title="Could not delete">{actionError}</Alert>}
            {error ? <ErrorState onRetry={load}>{error.message}</ErrorState> : !rows ? <Skeleton lines={4} /> : rows.length === 0 ? (
                <EmptyState icon="layers" title="No departments yet" action={<Button variant="soft" size="sm" onClick={() => setEditing('new')}>Add the first one</Button>}>Create departments, then assign people to them.</EmptyState>
            ) : (
                <Table caption="Departments" columns={[
                    { key: 'name', label: 'Name' }, { key: 'description', label: 'Description' }, { key: 'members', label: 'Members', num: true }, { key: 'actions', label: '' },
                ]} rows={rows.map((d) => ({
                    name: d.name, description: d.description ?? '', members: d.members,
                    actions: <Menu label={`Actions for ${d.name}`} items={[
                        { label: 'Edit', icon: 'edit', onSelect: () => setEditing(d) },
                        { label: 'Delete', icon: 'trash', danger: true, onSelect: () => setDeleting(d) },
                    ]} />,
                }))} />
            )}
            {editing && <DepartmentForm dept={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load(); }} />}
            <ConfirmDialog open={!!deleting} onCancel={() => setDeleting(null)} onConfirm={remove} title={`Delete ${deleting?.name ?? 'department'}?`}>
                A department with members cannot be deleted. This cannot be undone.
            </ConfirmDialog>
        </>
    );
}
