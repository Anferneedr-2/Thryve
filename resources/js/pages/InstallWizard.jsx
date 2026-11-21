import { useEffect, useState } from 'react';
import { get, send } from '../lib/api';
import { Button } from '../components/ui/Button';
import { TextField } from '../components/ui/Form';
import { Alert, Pill, Skeleton, Spinner, ErrorState } from '../components/ui/Feedback';
import Icon from '../components/Icon';
import GuestFrame from '../components/GuestFrame';

const STEPS = ['Welcome', 'Server check', 'Database', 'Hermes', 'Super Admin', 'Install'];
const SETUP_POINTS = [['check', 'Check the server'], ['layers', 'Connect the database'], ['users', 'Create the Super Admin']];

function Shell({ step, title, intro, children, footer }) {
    return (
        <GuestFrame width={520} label="Installation" tagline={'Let\u2019s set up your workspace'} points={SETUP_POINTS}>
            <div className="mb-3"><Pill tone="info">Step {step + 1} of {STEPS.length}</Pill></div>
            <div className="grid gap-4" aria-labelledby="wiz-title">
                <h1 id="wiz-title">{title}</h1>
                {intro && <p className="lg-lead" style={{ margin: 0 }}>{intro}</p>}
                {children}
                {footer && <div key={step} className="sl-btn-row mt-2 justify-end">{footer}</div>}
            </div>
        </GuestFrame>
    );
}

function CheckList({ items }) {
    return (
        <ul className="grid gap-2" style={{ listStyle: 'none', padding: 0, margin: 0 }}>
            {items.map((c) => (
                <li key={c.key} className="flex items-start gap-2">
                    <span style={{ color: c.ok ? 'var(--green-700)' : 'var(--red-600)', marginTop: 3 }}><Icon name={c.ok ? 'check' : 'alert'} label={c.ok ? 'Passed' : 'Failed'} /></span>
                    <span><strong>{c.label}</strong><br /><span className="sl-help">{c.detail}</span></span>
                </li>
            ))}
        </ul>
    );
}

export default function InstallWizard() {
    const [step, setStep] = useState(0);
    const [checks, setChecks] = useState(null);
    const [checksError, setChecksError] = useState(null);
    const [db, setDb] = useState({ host: '127.0.0.1', port: '3306', database: '', username: '', password: '' });
    const [dbResult, setDbResult] = useState(null);
    const [hermes, setHermes] = useState({ url: '', key: '' });
    const [hermesResult, setHermesResult] = useState(null);
    const [admin, setAdmin] = useState({ username: '', name: '', email: '', password: '', password_confirmation: '' });
    const [adminErrors, setAdminErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const [run, setRun] = useState({ state: 'idle', steps: [], error: null });

    const loadChecks = () => {
        setChecks(null);
        setChecksError(null);
        get('/install/checks').then(setChecks).catch(setChecksError);
    };
    useEffect(loadChecks, []);

    const editDb = (k) => (e) => { setDb({ ...db, [k]: e.target.value }); setDbResult(null); };
    const minPw = checks?.min_password_length ?? 12;

    const testDb = async () => {
        setBusy(true);
        setDbResult(null);
        try {
            setDbResult(await send('POST', '/install/test-database', { database: { ...db, port: Number(db.port) } }));
        } catch (e) {
            setDbResult({ ok: false, message: Object.values(e.details ?? {})[0]?.[0] ?? e.message });
        }
        setBusy(false);
    };

    const testHermes = async () => {
        setBusy(true);
        setHermesResult(null);
        try {
            setHermesResult(await send('POST', '/install/test-hermes', { hermes }));
        } catch (e) {
            setHermesResult({ ok: false, message: Object.values(e.details ?? {})[0]?.[0] ?? e.message });
        }
        setBusy(false);
    };

    const checkAdmin = () => {
        const e = {};
        if (!/^[A-Za-z0-9._-]{3,64}$/.test(admin.username)) e.username = 'Use 3 to 64 letters, numbers, dots, dashes or underscores.';
        if (!admin.name.trim()) e.name = 'Enter a name.';
        if (admin.email && !/^\S+@\S+\.\S+$/.test(admin.email)) e.email = 'Enter a valid email address or leave it empty.';
        if (admin.password.length < minPw) e.password = `Use at least ${minPw} characters.`;
        if (admin.password !== admin.password_confirmation) e.password_confirmation = 'The passwords do not match.';
        setAdminErrors(e);
        return Object.keys(e).length === 0;
    };

    const install = async () => {
        setRun({ state: 'running', steps: [], error: null });
        try {
            const data = await send('POST', '/install/run', { database: { ...db, port: Number(db.port) }, hermes, admin });
            setRun({ state: 'done', steps: data.steps, error: null });
        } catch (e) {
            setRun({ state: 'failed', steps: [], error: e });
        }
    };

    if (step === 0) {
        return (
            <Shell step={0} title="Set up Thryve" intro="This wizard connects the database, saves your settings and creates the first Super Admin account. It takes a few minutes. You will not need to edit any files."
                footer={<Button variant="primary" onClick={() => setStep(1)}>Get started</Button>} />
        );
    }

    if (step === 1) {
        return (
            <Shell step={1} title="Check this server" intro="We check that the server has what Thryve needs."
                footer={<><Button onClick={() => setStep(0)}>Back</Button><Button onClick={loadChecks} icon="refresh">Check again</Button><Button variant="primary" disabled={!checks?.ready} onClick={() => setStep(2)}>Continue</Button></>}>
                {checksError ? <ErrorState onRetry={loadChecks}>{checksError.message}</ErrorState> : !checks ? <Skeleton lines={5} /> : (
                    <>
                        {!checks.ready && <Alert tone="danger" title="This server is not ready">Fix the items marked below, then choose Check again.</Alert>}
                        <h2 className="text-lg">Requirements</h2>
                        <CheckList items={checks.requirements} />
                        <h2 className="text-lg">File permissions</h2>
                        <CheckList items={checks.permissions} />
                    </>
                )}
            </Shell>
        );
    }

    if (step === 2) {
        const filled = db.host && db.port && db.database && db.username;
        return (
            <Shell step={2} title="Connect the database" intro="Create an empty MariaDB database and a user for it first (in cPanel: MySQL Databases), then enter the details."
                footer={<><Button onClick={() => setStep(1)}>Back</Button><Button variant="primary" disabled={!dbResult?.ok} onClick={() => setStep(3)}>Continue</Button></>}>
                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField label="Host" value={db.host} onChange={editDb('host')} />
                    <TextField label="Port" inputMode="numeric" value={db.port} onChange={editDb('port')} />
                </div>
                <TextField label="Database name" value={db.database} onChange={editDb('database')} autoComplete="off" />
                <TextField label="Username" value={db.username} onChange={editDb('username')} autoComplete="off" />
                <TextField label="Password" type="password" value={db.password} onChange={editDb('password')} autoComplete="new-password" />
                <div className="sl-btn-row">
                    <Button disabled={!filled || busy} onClick={testDb} icon={busy ? undefined : 'refresh'}>{busy ? <><Spinner label="Testing" />Testing...</> : 'Test connection'}</Button>
                </div>
                {dbResult && <Alert tone={dbResult.ok ? 'success' : 'danger'} title={dbResult.ok ? 'Connected' : 'Could not connect'}>{dbResult.message}{dbResult.version ? ` Server version ${dbResult.version}.` : ''}</Alert>}
            </Shell>
        );
    }

    if (step === 3) {
        return (
            <Shell step={3} title="Connect Hermes" intro="Hermes is the AI service on your company server. You can skip this now and connect it later."
                footer={<><Button onClick={() => setStep(2)}>Back</Button><Button variant="primary" onClick={() => setStep(4)}>{hermes.url ? 'Continue' : 'Skip for now'}</Button></>}>
                <TextField label="Hermes address" placeholder="https://hermes.your-company.com" value={hermes.url} onChange={(e) => { setHermes({ ...hermes, url: e.target.value }); setHermesResult(null); }} />
                <TextField label="Access key" type="password" help="Stored on the server only. Never shown again." value={hermes.key} onChange={(e) => { setHermes({ ...hermes, key: e.target.value }); setHermesResult(null); }} autoComplete="off" />
                <div className="sl-btn-row">
                    <Button disabled={!hermes.url || busy} onClick={testHermes}>{busy ? <><Spinner label="Testing" />Testing...</> : 'Test address'}</Button>
                </div>
                {hermesResult && <Alert tone={hermesResult.ok ? 'success' : 'danger'} title={hermesResult.ok ? 'Server reachable' : 'Could not reach Hermes'}>{hermesResult.message}</Alert>}
            </Shell>
        );
    }

    if (step === 4) {
        const set = (k) => (e) => setAdmin({ ...admin, [k]: e.target.value });
        return (
            <Shell step={4} title="Create the Super Admin" intro="This account manages everything. Choose a strong password you do not use anywhere else."
                footer={<><Button onClick={() => setStep(3)}>Back</Button><Button variant="primary" onClick={() => checkAdmin() && setStep(5)}>Continue</Button></>}>
                <TextField label="Username" value={admin.username} onChange={set('username')} error={adminErrors.username} autoComplete="off" />
                <TextField label="Full name" value={admin.name} onChange={set('name')} error={adminErrors.name} />
                <TextField label="Email (optional)" type="email" value={admin.email} onChange={set('email')} error={adminErrors.email} />
                <TextField label="Password" type="password" help={`At least ${minPw} characters.`} value={admin.password} onChange={set('password')} error={adminErrors.password} autoComplete="new-password" />
                <TextField label="Confirm password" type="password" value={admin.password_confirmation} onChange={set('password_confirmation')} error={adminErrors.password_confirmation} autoComplete="new-password" />
            </Shell>
        );
    }

    const failedStep = run.error?.details?.step;
    const fieldErrs = run.error?.code === 'validation_failed' ? Object.entries(run.error.details).map(([k, v]) => `${k.split('.').pop()}: ${v[0]}`) : [];
    return (
        <Shell step={5} title={run.state === 'done' ? 'Installation complete' : 'Ready to install'}
            intro={run.state === 'done' ? 'Thryve is installed. Sign in with the Super Admin account you just created.' : 'Review the details, then install. This creates the database tables and your account.'}
            footer={run.state === 'done'
                ? <Button variant="primary" onClick={() => { window.location.href = '/'; }}>Continue to Thryve</Button>
                : <><Button disabled={run.state === 'running'} onClick={() => setStep(4)}>Back</Button><Button variant="primary" disabled={run.state === 'running'} onClick={install}>{run.state === 'running' ? 'Installing...' : run.state === 'failed' ? 'Try again' : 'Install now'}</Button></>}>
            {run.state !== 'done' && (
                <dl className="grid gap-2" style={{ margin: 0 }}>
                    <div><dt className="sl-help">Database</dt><dd style={{ margin: 0 }}>{db.database} on {db.host}:{db.port} as {db.username}</dd></div>
                    <div><dt className="sl-help">Hermes</dt><dd style={{ margin: 0 }}>{hermes.url || 'Not connected yet'}</dd></div>
                    <div><dt className="sl-help">Super Admin</dt><dd style={{ margin: 0 }}>{admin.username}</dd></div>
                </dl>
            )}
            {run.state === 'running' && <div className="flex items-center gap-2"><Spinner label="Installing" />Installing, please wait...</div>}
            {run.state === 'failed' && (
                <Alert tone="danger" title={failedStep ? `Stopped at: ${failedStep}` : 'Installation failed'}>
                    {run.error.message}{fieldErrs.length ? ` ${fieldErrs.join(' ')}` : ''} Nothing is lost: fix the problem and try again.
                </Alert>
            )}
            {run.state === 'done' && <CheckList items={run.steps.map((s) => ({ key: s.key, label: s.label, ok: true, detail: s.status === 'skipped' ? 'Kept from an earlier attempt' : 'Done' }))} />}
        </Shell>
    );
}
