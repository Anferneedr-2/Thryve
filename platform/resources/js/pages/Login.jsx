import { useState } from 'react';
import { useAuth } from '../lib/auth';
import { useRouter } from '../lib/router';
import { Button } from '../components/ui/Button';
import { TextField } from '../components/ui/Form';
import { Alert } from '../components/ui/Feedback';
import { Brand } from '../lib/branding';

export default function Login() {
    const { login, notice } = useAuth();
    const { navigate } = useRouter();
    const [form, setForm] = useState({ username: '', password: '' });
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await login(form.username.trim(), form.password);
            navigate('/', { replace: true });
        } catch (err) {
            setError(err.code === 'validation_failed' ? 'Enter your username and password.' : err.message);
            setForm((f) => ({ ...f, password: '' }));
        }
        setBusy(false);
    };

    return (
        <main className="mx-auto flex min-h-screen w-full max-w-[440px] flex-col justify-center gap-6 px-6 py-10">
            <Brand className="font-display text-3xl font-extrabold text-navy" />
            <section className="sl-card" aria-labelledby="login-title">
                <h1 id="login-title" className="text-3xl">Sign in</h1>
                <p>Use the username and password your administrator gave you.</p>
                {notice && <Alert tone="warning" title="Signed out">{notice}</Alert>}
                {error && <Alert tone="danger" title="Could not sign in">{error}</Alert>}
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <TextField label="Username" value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} autoComplete="username" autoCapitalize="none" spellCheck={false} autoFocus />
                    <TextField label="Password" type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} autoComplete="current-password" />
                    <Button variant="primary" type="submit" disabled={busy || !form.username || !form.password}>{busy ? 'Signing in...' : 'Sign in'}</Button>
                </form>
                <p className="sl-help">Forgot your password? Ask an administrator to reset it.</p>
            </section>
        </main>
    );
}
