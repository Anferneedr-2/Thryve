import { useState } from 'react';
import { useAuth } from '../lib/auth';
import { Checkbox } from '../components/ui/Form';
import GuestFrame from '../components/GuestFrame';
import Icon from '../components/Icon';

const EMPTY = { current_password: '', password: '', password_confirmation: '' };

// Shown before anything else while the person is still using the password an administrator gave them.
export default function ChangePassword() {
    const { user, changeFirstPassword, logout } = useAuth();
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [shown, setShown] = useState(false);
    const [busy, setBusy] = useState(false);
    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

    const submit = async (e) => {
        e.preventDefault();
        setErrors({});
        setFormError(null);
        const local = {};
        if (!form.current_password) local.current_password = 'Enter the password you were given.';
        if (form.password.length < 12) local.password = 'Use at least 12 characters.';
        else if (form.password === form.current_password) local.password = 'Choose a password different from the one you were given.';
        if (form.password !== form.password_confirmation) local.password_confirmation = 'The passwords do not match.';
        if (Object.keys(local).length) return setErrors(local);

        setBusy(true);
        try {
            await changeFirstPassword(form);
        } catch (err) {
            const d = err.details ?? {};
            if (Object.keys(d).length) setErrors(Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v[0]])));
            else setFormError(err.message);
            setBusy(false);
        }
    };

    const field = (id, label, key, autoComplete, help) => (
        <label htmlFor={id}>
            {label}
            <span className={`lg-field ${errors[key] ? 'err' : ''}`}>
                <input id={id} type={shown ? 'text' : 'password'} value={form[key]} onChange={set(key)} autoComplete={autoComplete} aria-invalid={!!errors[key]} />
            </span>
            {errors[key] ? <span className="lg-hint" role="alert"><Icon name="alert" />{errors[key]}</span> : help && <span className="lg-hint">{help}</span>}
        </label>
    );

    return (
        <GuestFrame label="Choose your password">
            <h1 id="password-title">Choose your own password</h1>
            <p className="lg-lead">Welcome, {user.name}. The password you were given is temporary. Choose one only you know before you continue.</p>
            {formError && <div className="lg-msg bad" role="alert"><Icon name="alert" /><div><b>Could not change password</b>{formError}</div></div>}
            <form className="lg-login" onSubmit={submit} noValidate aria-busy={busy}>
                {field('pw-current', 'Password you were given', 'current_password', 'current-password')}
                {field('pw-new', 'New password', 'password', 'new-password', 'At least 12 characters.')}
                {field('pw-confirm', 'Confirm new password', 'password_confirmation', 'new-password')}
                <Checkbox label="Show passwords" checked={shown} onChange={(e) => setShown(e.target.checked)} />
                <div className="lg-actions">
                    <button type="button" className="lg-btn ghost" onClick={logout}>Sign out</button>
                    <button type="submit" className={`lg-btn ${busy ? 'busy' : ''}`} disabled={busy}>
                        {busy ? <><span className="lg-spin" aria-hidden="true" />Saving...</> : 'Save and continue'}
                    </button>
                </div>
            </form>
        </GuestFrame>
    );
}
