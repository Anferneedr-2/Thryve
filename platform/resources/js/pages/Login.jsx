import { useState } from 'react';
import { useAuth } from '../lib/auth';
import { useRouter } from '../lib/router';
import GuestFrame from '../components/GuestFrame';
import Icon from '../components/Icon';

// Sign-in: the approved split-screen layout (design-proposals/login-options.html, option B), drawn by the shared guest frame.
// How signing in works is unchanged: same call, same messages for a wrong password or a session that ended elsewhere.

export default function Login() {
    const { login, notice } = useAuth();
    const { navigate } = useRouter();
    const [form, setForm] = useState({ username: '', password: '' });
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [shown, setShown] = useState(false);
    const [caps, setCaps] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        // The button stays ready; an empty field is explained here instead of the button being greyed out.
        if (!form.username.trim() || !form.password) {
            setError('Enter your username and password.');
            document.getElementById(form.username.trim() ? 'login-password' : 'login-username')?.focus();
            return;
        }
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
    const watchCaps = (e) => setCaps(!!e.getModifierState?.('CapsLock'));

    return (
        <GuestFrame label="Sign in">
            <h1 id="login-title">Welcome back</h1>
            <p className="lg-lead">Sign in with the username and password your administrator gave you.</p>
            {notice && !error && (
                <div className="lg-msg warn" role="status"><Icon name="alert" /><div><b>Signed out</b>{notice}</div></div>
            )}
            {error && (
                <div className="lg-msg bad" role="alert"><Icon name="alert" /><div><b>Could not sign in</b>{error}</div></div>
            )}
            <form className="lg-login" onSubmit={submit} noValidate aria-busy={busy}>
                <label htmlFor="login-username">
                    Username
                    <span className="lg-field">
                        <input id="login-username" type="text" value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })}
                            autoComplete="username" autoCapitalize="none" spellCheck={false} autoFocus placeholder="e.g. maria.santos" />
                    </span>
                </label>
                <label htmlFor="login-password">
                    Password
                    <span className={`lg-field pw ${error ? 'err' : ''}`}>
                        <input id="login-password" type={shown ? 'text' : 'password'} value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })}
                            onKeyDown={watchCaps} onKeyUp={watchCaps} onBlur={() => setCaps(false)} autoComplete="current-password" placeholder="Your password" />
                        <button type="button" className="lg-eye" aria-label={shown ? 'Hide password' : 'Show password'} aria-pressed={shown} onClick={() => setShown((v) => !v)}>
                            <Icon name="eye" />
                        </button>
                    </span>
                </label>
                {caps && <div className="lg-hint" role="status"><Icon name="alert" className="" />Caps Lock is on</div>}
                <button type="submit" className={`lg-btn ${busy ? 'busy' : ''}`} disabled={busy}>
                    {busy ? <><span className="lg-spin" aria-hidden="true" />Signing in...</> : 'Sign in'}
                </button>
            </form>
            <p className="lg-help">Forgot your password? Ask an administrator to reset it.</p>
        </GuestFrame>
    );
}
