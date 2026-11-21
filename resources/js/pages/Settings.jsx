import { useEffect, useRef, useState } from 'react';
import { get, send, upload } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useBranding } from '../lib/branding';
import { errorText } from '../lib/util';
import { Card } from '../components/ui/Layout';
import { Button } from '../components/ui/Button';
import { SelectField, Switch, TextField } from '../components/ui/Form';
import { Alert, ErrorState, Skeleton, useToast } from '../components/ui/Feedback';

const num = (v) => (v === '' || v === null ? '' : Number(v));

// Super Admin only. One form, one Save: only the fields that changed are sent. Secrets are write-only.
export default function Settings({ section = 'general' }) {
    const { user } = useAuth();
    const toast = useToast();
    const brand = useBranding();
    const [loaded, setLoaded] = useState(null); // settings as saved on the server
    const [values, setValues] = useState({});
    const [password, setPassword] = useState('');
    const [clearPassword, setClearPassword] = useState(false);
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [loadError, setLoadError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [testTo, setTestTo] = useState(user.email ?? '');
    const [testResult, setTestResult] = useState(null);
    const [testing, setTesting] = useState(false);

    const apply = (settings) => {
        setLoaded(settings);
        setValues(Object.fromEntries(Object.entries(settings).filter(([, s]) => !s.secret).map(([k, s]) => [k, s.value ?? ''])));
        setPassword('');
        setClearPassword(false);
    };
    const load = () => { setLoadError(null); get('/settings').then(apply).catch(setLoadError); };
    useEffect(load, []);
    // Each section saves on its own, so unsaved edits are dropped when another section is opened.
    useEffect(() => { if (loaded) { apply(loaded); setErrors({}); setFormError(null); } }, [section]); // eslint-disable-line react-hooks/exhaustive-deps

    const set = (k, cast) => (e) => setValues((v) => ({ ...v, [k]: cast ? cast(e.target.value) : e.target.value }));
    const err = (k) => errors[k];

    const save = async (e, keys) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setFormError(null);
        const body = {};
        for (const [k, s] of Object.entries(loaded)) {
            if (s.secret || !keys.includes(k)) continue;
            const current = values[k] === '' ? null : values[k];
            if (current !== (s.value ?? null)) body[k] = current;
        }
        if (keys.includes('smtp_password')) {
            if (clearPassword) body.smtp_password = null;
            else if (password) body.smtp_password = password;
        }
        if (Object.keys(body).length === 0) { setBusy(false); return toast('Nothing to save'); }

        try {
            const data = await send('PATCH', '/settings', body);
            apply(data.settings);
            brand.refresh({ company_name: data.settings.company_name.value, default_theme: data.settings.default_theme.value, has_logo: brand.has_logo, has_logo_dark: brand.has_logo_dark, has_favicon: brand.has_favicon });
            toast('Settings saved');
        } catch (e2) {
            const d = e2.details ?? {};
            if (Object.keys(d).length) {
                setErrors(Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v[0]])));
                setFormError('Some settings need attention (marked below). Nothing was saved.');
                document.querySelector(".sx-body")?.scrollTo({ top: 0, behavior: "smooth" }); // the message is at the top of the page
            } else setFormError(e2.message);
        }
        setBusy(false);
    };

    const sendTest = async () => {
        setTesting(true);
        setTestResult(null);
        try { setTestResult(await send('POST', '/settings/smtp-test', { to: testTo || null })); } catch (e) { setTestResult({ ok: false, message: errorText(e) }); }
        setTesting(false);
    };

    if (loadError) return <ErrorState onRetry={load}>{loadError.message}</ErrorState>;
    if (!loaded) return <Skeleton lines={6} />;

    const L = (k) => loaded[k].label;
    const smtpSaved = !!loaded.smtp_host.value && !!loaded.smtp_from_address.value;
    const KEYS = {
        general: ['company_name', 'default_theme'],
        work: ['idle_minutes', 'analysis_idle_minutes'],
        files: ['file_max_count', 'file_max_mb'],
        tokens: ['default_daily_token_limit', 'system_daily_token_budget'],
        security: ['sensitive_data_mode'],
        email: ['smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'smtp_from_address', 'smtp_from_name'],
    };
    // A plain function, not a component: a component defined here would remount (and drop focus) on every keystroke.
    const form = (children) => (
        <form onSubmit={(e) => save(e, KEYS[section])} className="grid gap-4" noValidate>
            {formError && <Alert tone="danger" title="Could not save">{formError}</Alert>}
            <Card>{children}</Card>
            <div className="sl-btn-row justify-end">
                <Button variant="primary" type="submit" disabled={busy}>{busy ? 'Saving...' : 'Save changes'}</Button>
            </div>
        </form>
    );

    if (section === 'branding') return <BrandingCard />;
    if (section === 'general') return form(<>
        <TextField label={L('company_name')} value={values.company_name} onChange={set('company_name')} error={err('company_name')} />
        <SelectField label={`${L('default_theme')} (when a person has not chosen one)`} value={values.default_theme} onChange={set('default_theme')} error={err('default_theme')}>
            <option value="system">Follow the device</option><option value="light">Light</option><option value="dark">Dark</option>
        </SelectField>
    </>);
    if (section === 'work') return form(<>
        <TextField label={L('idle_minutes')} type="number" min="1" value={values.idle_minutes} onChange={set('idle_minutes', num)} error={err('idle_minutes')} help="After this long without activity, time counts as idle. Nobody is signed out." />
        <TextField label={L('analysis_idle_minutes')} type="number" min="1" value={values.analysis_idle_minutes} onChange={set('analysis_idle_minutes', num)} error={err('analysis_idle_minutes')} />
    </>);
    if (section === 'files') return form(<>
        <TextField label={L('file_max_count')} type="number" min="1" value={values.file_max_count} onChange={set('file_max_count', num)} error={err('file_max_count')} />
        <TextField label={L('file_max_mb')} type="number" min="1" value={values.file_max_mb} onChange={set('file_max_mb', num)} error={err('file_max_mb')} />
    </>);
    if (section === 'tokens') return form(<>
        <TextField label={L('default_daily_token_limit')} type="number" min="0" value={values.default_daily_token_limit} onChange={set('default_daily_token_limit', num)} error={err('default_daily_token_limit')}
            help="For anyone without their own limit. One allowance covers every chatbot and resets at midnight Philippine time." />
        <TextField label={L('system_daily_token_budget')} type="number" min="0" value={values.system_daily_token_budget} onChange={set('system_daily_token_budget', num)} error={err('system_daily_token_budget')}
            help="Separate from people's allowances. Leave empty for no limit." />
    </>);
    if (section === 'security') return form(<>
        <SelectField label={L('sensitive_data_mode')} value={values.sensitive_data_mode} onChange={set('sensitive_data_mode')} error={err('sensitive_data_mode')}>
            <option value="warn">Warn the person and let them continue</option><option value="block">Block the message</option>
        </SelectField>
    </>);
    // email
    return form(<>
        <p>Used for alerts. In-app notifications keep working if email is off or fails.</p>
        <Switch label={L('smtp_enabled')} checked={!!values.smtp_enabled} onChange={(e) => setValues((v) => ({ ...v, smtp_enabled: e.target.checked }))} />
        <div className="grid gap-4 sm:grid-cols-2">
            <TextField label={L('smtp_host')} value={values.smtp_host} onChange={set('smtp_host')} error={err('smtp_host')} placeholder="smtp.example.com" autoComplete="off" />
            <TextField label={L('smtp_port')} type="number" value={values.smtp_port} onChange={set('smtp_port', num)} error={err('smtp_port')} />
            <SelectField label={L('smtp_encryption')} value={values.smtp_encryption} onChange={set('smtp_encryption')} error={err('smtp_encryption')}>
                <option value="tls">TLS (STARTTLS, usually port 587)</option><option value="ssl">SSL (usually port 465)</option><option value="none">None</option>
            </SelectField>
            <TextField label={L('smtp_username')} value={values.smtp_username} onChange={set('smtp_username')} error={err('smtp_username')} autoComplete="off" />
            <div className="grid gap-2">
                <TextField label={L('smtp_password')} type="password" value={password} onChange={(e) => { setPassword(e.target.value); setClearPassword(false); }} error={err('smtp_password')} autoComplete="new-password"
                    placeholder={loaded.smtp_password.is_set ? 'Saved. Leave empty to keep it.' : ''} help={clearPassword ? 'The saved password will be removed when you save.' : 'Stored encrypted and never shown again.'} />
                {loaded.smtp_password.is_set && !clearPassword && <div><Button size="sm" variant="ghost" onClick={() => { setClearPassword(true); setPassword(''); }}>Remove saved password</Button></div>}
            </div>
            <TextField label={L('smtp_from_address')} type="email" value={values.smtp_from_address} onChange={set('smtp_from_address')} error={err('smtp_from_address')} placeholder="noreply@your-company.com" />
            <TextField label={L('smtp_from_name')} value={values.smtp_from_name} onChange={set('smtp_from_name')} error={err('smtp_from_name')} />
        </div>
        <div className="grid gap-3" style={{ borderTop: '1px solid var(--slate-200)', paddingTop: 16 }}>
            <strong className="font-display" style={{ color: 'var(--sl-heading)' }}>Send a test email</strong>
            {!smtpSaved && <span className="sl-help">Save the server name and From address first, then test.</span>}
            <div className="grid items-end gap-3 sm:grid-cols-[1fr_auto]">
                <TextField label="Send to" type="email" value={testTo} onChange={(e) => setTestTo(e.target.value)} />
                <Button disabled={!smtpSaved || testing} onClick={sendTest}>{testing ? 'Sending...' : 'Send test email'}</Button>
            </div>
            {testResult && <Alert tone={testResult.ok ? 'success' : 'danger'} title={testResult.ok ? 'Test email sent' : 'Test email failed'}>{testResult.message}</Alert>}
        </div>
    </>);
}

const NAMES = { logo: 'Logo', logo_dark: 'Dark mode logo', logo_login: 'Login page logo', favicon: 'Favicon' };

function BrandingCard() {
    const brand = useBranding();
    const toast = useToast();
    const logo = useRef(null);
    const logoDark = useRef(null);
    const logoLogin = useRef(null);
    const fav = useRef(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const run = async (fn, message) => {
        setBusy(true);
        setError(null);
        try { brand.refresh(await fn()); toast(message); } catch (e) { setError(errorText(e)); }
        setBusy(false);
    };
    const submit = (kind, ref) => {
        const file = ref.current?.files?.[0];
        if (!file) return setError(`Choose a ${kind} file first.`);
        const fd = new FormData();
        fd.append(kind, file);
        run(() => upload('/settings/branding', fd), `${NAMES[kind]} updated`).then(() => { ref.current.value = ''; });
    };
    const remove = (kind) => run(() => send('DELETE', `/settings/branding/${kind}`), `${NAMES[kind]} removed`);

    return (
        <Card>
            <p>PNG, JPEG or WebP logo up to 1 MB and 2000 px. Favicon: PNG or ICO up to 256 KB.</p>
            {error && <Alert tone="danger" title="Could not update">{error}</Alert>}
            <div className="grid gap-6 sm:grid-cols-2">
                {[
                    ['logo', logo, brand.has_logo, 'Logo', 'image/png,image/jpeg,image/webp', 'Shown on light backgrounds.', '#fff'],
                    ['logo_dark', logoDark, brand.has_logo_dark, 'Logo for dark mode', 'image/png,image/jpeg,image/webp', 'Shown when someone switches to the dark theme. Without one, the normal logo is shown on a white background.', '#0b1220'],
                    ['logo_login', logoLogin, brand.has_logo_login, 'Logo for login page', 'image/png,image/jpeg,image/webp', 'Shown large on the blue panel of the sign-in page, with no white box. Use a white or light logo on a transparent background. Without one, the dark mode logo is used, then the normal logo on a white box.', 'linear-gradient(150deg,#315eef,#5b82f5)'],
                    ['favicon', fav, brand.has_favicon, 'Favicon', 'image/png,image/x-icon', 'The icon in the browser tab. A square PNG, 512 px or larger, also gives the home-screen and app icons for phones.', '#fff'],
                ].map(([kind, ref, has, label, accept, hint, previewBg]) => (
                    <div key={kind} className="grid gap-3 content-start">
                        <strong className="font-display" style={{ color: 'var(--sl-heading)' }}>{label}</strong>
                        <span className="sl-help" style={{ margin: 0 }}>{hint}</span>
                        {has ? <img src={`/branding/${kind}?v=${brand.version}`} alt={`Current ${label.toLowerCase()}`} style={{ maxHeight: 56, maxWidth: 200, objectFit: 'contain', background: previewBg, borderRadius: 10, padding: 6, border: '1px solid var(--slate-200)', justifySelf: 'start' }} /> : <span className="sl-help">None uploaded.</span>}
                        <input ref={ref} type="file" accept={accept} aria-label={`${label} file`} className="sl-input" style={{ padding: 8 }} />
                        <div className="sl-btn-row">
                            <Button size="sm" disabled={busy} onClick={() => submit(kind, ref)}>Upload</Button>
                            {has && <Button size="sm" variant="ghost" disabled={busy} onClick={() => remove(kind)}>Remove</Button>}
                        </div>
                    </div>
                ))}
            </div>
        </Card>
    );
}
