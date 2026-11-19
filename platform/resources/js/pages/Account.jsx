import { useState } from 'react';
import { useAuth } from '../lib/auth';
import { send } from '../lib/api';
import { Card } from '../components/ui/Layout';
import { Button } from '../components/ui/Button';
import { TextField } from '../components/ui/Form';
import { Alert, Pill, useToast } from '../components/ui/Feedback';
import { ROLE_LABEL } from '../components/AppLayout';

const EMPTY = { current_password: '', password: '', password_confirmation: '' };

export default function Account() {
    const { user } = useAuth();
    const toast = useToast();
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [busy, setBusy] = useState(false);
    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

    const submit = async (e) => {
        e.preventDefault();
        setErrors({});
        setFormError(null);
        const local = {};
        if (form.password.length < 12) local.password = 'Use at least 12 characters.';
        if (form.password !== form.password_confirmation) local.password_confirmation = 'The passwords do not match.';
        if (form.password && form.password === form.current_password) local.password = 'Choose a password different from your current one.';
        if (Object.keys(local).length) return setErrors(local);

        setBusy(true);
        try {
            await send('POST', '/account/password', form);
            setForm(EMPTY);
            toast('Password changed');
        } catch (err) {
            const d = err.details ?? {};
            if (Object.keys(d).length) setErrors(Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v[0]])));
            else setFormError(err.message);
        }
        setBusy(false);
    };

    return (
        <>
            <h1 className="text-4xl">Account</h1>
            <div className="grid gap-4 lg:grid-cols-2">
                <Card title="Profile">
                    <dl className="grid gap-3" style={{ margin: 0 }}>
                        <div><dt className="sl-help">Name</dt><dd style={{ margin: 0 }}>{user.name}</dd></div>
                        <div><dt className="sl-help">Username</dt><dd style={{ margin: 0 }}>{user.username}</dd></div>
                        <div><dt className="sl-help">Email</dt><dd style={{ margin: 0 }}>{user.email ?? 'Not set'}</dd></div>
                        <div><dt className="sl-help">Role</dt><dd style={{ margin: 0 }}><Pill tone="info">{ROLE_LABEL[user.role]}</Pill></dd></div>
                        <div><dt className="sl-help">Departments</dt><dd style={{ margin: 0 }} className="flex flex-wrap gap-2">{user.departments.length ? user.departments.map((d) => <Pill key={d.id}>{d.name}</Pill>) : 'None'}</dd></div>
                    </dl>
                    <p className="sl-help">Ask an administrator to change your name, email or departments.</p>
                </Card>
                <Card title="Change password">
                    {formError && <Alert tone="danger" title="Could not change password">{formError}</Alert>}
                    <form onSubmit={submit} className="grid gap-4" noValidate>
                        <TextField label="Current password" type="password" value={form.current_password} onChange={set('current_password')} error={errors.current_password} autoComplete="current-password" />
                        <TextField label="New password" type="password" help="At least 12 characters." value={form.password} onChange={set('password')} error={errors.password} autoComplete="new-password" />
                        <TextField label="Confirm new password" type="password" value={form.password_confirmation} onChange={set('password_confirmation')} error={errors.password_confirmation} autoComplete="new-password" />
                        <div><Button variant="primary" type="submit" disabled={busy || !form.current_password || !form.password}>{busy ? 'Saving...' : 'Update password'}</Button></div>
                    </form>
                </Card>
            </div>
        </>
    );
}
