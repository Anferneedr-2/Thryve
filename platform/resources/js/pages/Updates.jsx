import { useCallback, useEffect, useRef, useState } from 'react';
import { get, send, upload } from '../lib/api';
import { errorText, formatDateTime } from '../lib/util';
import { Card, Table } from '../components/ui/Layout';
import { Button } from '../components/ui/Button';
import { TextField } from '../components/ui/Form';
import { Alert, EmptyState, ErrorState, Pill, Skeleton, Spinner, useToast } from '../components/ui/Feedback';
import { Dialog } from '../components/ui/Overlay';

const STEPS = [
    ['backup', 'Backing up the database and files'],
    ['verify', 'Checking the backup'],
    ['files', 'Installing the new files'],
    ['migrate', 'Updating the database'],
    ['seed', 'Final data checks'],
    ['finalize', 'Finishing up'],
];
const RESULT = { applied: ['success', 'Applied'], rolled_back: ['info', 'Rolled back'], failed: ['danger', 'Failed'], cancelled: ['warning', 'Cancelled'] };
const bytes = (n) => (n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);

// A confirmation that needs the exact word typed, for actions that cannot be undone casually.
function TypedConfirm({ open, title, word, action, tone = 'primary', onCancel, onConfirm, busy, children }) {
    const [typed, setTyped] = useState('');
    useEffect(() => { if (open) setTyped(''); }, [open]);
    return (
        <Dialog open={open} onClose={onCancel} title={title}
            actions={<><Button onClick={onCancel}>Cancel</Button><Button variant={tone} disabled={typed !== word || busy} onClick={onConfirm}>{busy ? 'Working...' : action}</Button></>}>
            <div className="grid gap-3">
                {children}
                <TextField label={`Type ${word} to confirm`} value={typed} onChange={(e) => setTyped(e.target.value)} autoComplete="off" />
            </div>
        </Dialog>
    );
}

export default function Updates() {
    const toast = useToast();
    const [status, setStatus] = useState(null);
    const [backups, setBackups] = useState(null);
    const [backupsError, setBackupsError] = useState(null); // the backups list failing must not hide the update controls
    const [error, setError] = useState(null);
    const [notice, setNotice] = useState(null);
    const [busy, setBusy] = useState(false);
    const [dialog, setDialog] = useState(null); // 'approve' | 'rollback'
    const pkg = useRef(null);
    const sig = useRef(null);

    const load = useCallback(async () => {
        try {
            setStatus(await get('/system/updates'));
            setError(null);
        } catch (e) { setError(e); return; }
        get('/system/backups').then((b) => { setBackups(b); setBackupsError(null); }).catch((e) => setBackupsError(e));
    }, []);
    useEffect(() => { load(); }, [load]);

    const run = async (fn, okMessage) => {
        setBusy(true);
        setNotice(null);
        try { const next = await fn(); if (next) setStatus(next); if (okMessage) toast(okMessage); } catch (e) { setNotice(errorText(e)); }
        setBusy(false);
    };

    // While an update is running the page keeps asking for the next step until it stops running.
    const running = status?.update?.status === 'running';
    useEffect(() => {
        if (!running) return undefined;
        let cancelled = false;
        const tick = async () => {
            try { const next = await send('POST', '/system/updates/advance'); if (!cancelled) setStatus(next); } catch (e) { if (!cancelled) setNotice(errorText(e)); return; }
            if (!cancelled) setTimeout(tick, 400);
        };
        const t = setTimeout(tick, 300);
        return () => { cancelled = true; clearTimeout(t); };
    }, [running]);
    useEffect(() => { if (status && !running) get('/system/backups').then((b) => { setBackups(b); setBackupsError(null); }).catch((e) => setBackupsError(e)); }, [status?.update?.status]); // eslint-disable-line react-hooks/exhaustive-deps

    const stage = () => {
        const p = pkg.current?.files?.[0];
        const s = sig.current?.files?.[0];
        if (!p || !s) return setNotice('Choose both the release file (.zip) and its signature file (.sig).');
        const fd = new FormData();
        fd.append('package', p);
        fd.append('signature', s);
        run(() => upload('/system/updates/stage', fd).then(() => get('/system/updates')), 'Package checked and ready to review');
    };

    if (error) return <ErrorState onRetry={load}>{error.message}</ErrorState>;
    if (!status) return <Skeleton lines={6} />;

    const u = status.update;
    const isDb = u.kind === 'database';
    const steps = isDb ? STEPS.filter(([k]) => k !== 'files') : STEPS;
    const stepIndex = steps.findIndex(([k]) => k === u.step);
    const dbNeeded = status.database_behind && u.status !== 'running' && u.status !== 'failed';
    return (
        <>
            {notice && <Alert tone="danger" title="That did not work">{notice}</Alert>}

            <Card title="Installed version">
                <div className="flex flex-wrap items-center gap-3">
                    <span className="font-display text-3xl font-extrabold tnum" style={{ color: 'var(--sl-heading)' }}>{status.version}</span>
                    <Pill tone={status.maintenance ? 'warning' : 'success'}>{status.maintenance ? 'In maintenance' : 'Running normally'}</Pill>
                    <span className="sl-help">PHP {status.php}</span>
                </div>
                {status.pending_migrations.length > 0 && !dbNeeded && <Alert tone="warning" title="The database is behind the code">{status.pending_migrations.length} database change(s) have not been applied yet: {status.pending_migrations.join(', ')}.</Alert>}
            </Card>

            {u.status === 'done' && isDb && (
                <Alert tone="success" title="The database was updated">The platform is ready. Reload to carry on.<div className="mt-2"><Button size="sm" variant="primary" onClick={() => window.location.reload()}>Continue</Button></div></Alert>
            )}
            {u.status === 'idle' && u.error && isDb && <Alert tone="warning" title="The database update was cancelled">{u.error.message}</Alert>}
            {dbNeeded && (
                <Card title="The database needs an update">
                    <p>New code is on this server, and it needs {status.pending_migrations.length} database change(s) that have not been applied yet. Until you apply them, everyone else sees a maintenance notice. A backup is taken first, and you can roll back if anything fails.</p>
                    <ul className="sl-help" style={{ margin: 0, paddingLeft: 18 }}>{status.pending_migrations.map((m) => <li key={m} className="tnum">{m}</li>)}</ul>
                    <div className="sl-btn-row">
                        <Button variant="primary" disabled={busy || u.status === 'staged'} onClick={() => setDialog('database')}>Update the database</Button>
                    </div>
                    {u.status === 'staged' && <p className="sl-help">Discard the staged package below first.</p>}
                </Card>
            )}

            {(u.status === 'idle' || u.status === 'rolled_back' || u.status === 'done') && (
                <Card title="Install an update">
                    {u.status === 'done' && !isDb && <Alert tone="success" title={`Updated to ${u.version}`}>The update finished and the site is running normally.</Alert>}
                    {u.status === 'rolled_back' && <Alert tone="info" title={isDb ? 'The database was restored' : `Rolled back to ${u.from_version}`}>{isDb ? 'The database was restored from the backup taken before the update. The new code is still on the server, so the database update will be asked for again.' : 'The previous version was restored from the backup.'}</Alert>}
                    <p>Only needed if you install releases by uploading a signed package. If you deploy with Git, the database wizard above is all you need.</p>
                    {!status.key_configured && <Alert tone="info" title="Package uploads are off">No release public key is installed on this server (storage/app/update-public.pem), so no package can be accepted. Deploying code with Git and updating the database here still works.</Alert>}
                    <p>Upload the release file and its signature file. Nothing changes on the live site until you approve it.</p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className="sl-field"><span style={{ fontSize: 13, fontWeight: 600, color: 'var(--sl-heading)' }}>Release file (.zip)</span><input ref={pkg} type="file" accept=".zip,application/zip" className="sl-input" style={{ padding: 8 }} disabled={!status.key_configured} /></label>
                        <label className="sl-field"><span style={{ fontSize: 13, fontWeight: 600, color: 'var(--sl-heading)' }}>Signature file (.sig)</span><input ref={sig} type="file" accept=".sig,text/plain" className="sl-input" style={{ padding: 8 }} disabled={!status.key_configured} /></label>
                    </div>
                    <div className="sl-btn-row">
                        <Button variant="primary" disabled={busy || !status.key_configured} onClick={stage}>{busy ? 'Checking...' : 'Check package'}</Button>
                        {u.status === 'done' && <Button variant="ghost" disabled={busy} onClick={() => setDialog('rollback')}>{isDb ? 'Restore the database from the backup' : 'Roll back this update'}</Button>}
                    </div>
                </Card>
            )}

            {u.status === 'staged' && (
                <Card title={`Ready to install ${u.version}`}>
                    {u.error && <Alert tone="warning" title="The last attempt was cancelled">{u.error.message}</Alert>}
                    <dl className="grid gap-3 sm:grid-cols-3" style={{ margin: 0 }}>
                        <div><dt className="sl-help">From</dt><dd style={{ margin: 0 }} className="tnum">{u.from_version}</dd></div>
                        <div><dt className="sl-help">To</dt><dd style={{ margin: 0 }} className="tnum">{u.version}</dd></div>
                        <div><dt className="sl-help">Files</dt><dd style={{ margin: 0 }} className="tnum">{u.file_count}</dd></div>
                    </dl>
                    {u.notes && <p>{u.notes}</p>}
                    {u.pending_migrations.length > 0 && <Alert tone="info" title="Database changes in this release">{u.pending_migrations.join(', ')}</Alert>}
                    <Alert tone="warning" title="While it runs">Everyone else sees a maintenance page for a few minutes. A backup is taken first, and if anything fails you can roll back.</Alert>
                    <div className="sl-btn-row">
                        <Button variant="primary" disabled={busy} onClick={() => setDialog('approve')}>Approve and start update</Button>
                        <Button disabled={busy} onClick={() => run(() => send('DELETE', '/system/updates/staged'), 'Package discarded')}>Discard</Button>
                    </div>
                </Card>
            )}

            {running && (
                <Card title={isDb ? 'Updating the database' : `Installing ${u.version}`}>
                    <div role="status" className="grid gap-2">
                        {steps.map(([k, label], i) => (
                            <div key={k} className="flex items-center gap-2" style={{ opacity: i > stepIndex ? 0.55 : 1 }}>
                                {i < stepIndex ? <Pill tone="success">Done</Pill> : i === stepIndex ? <Pill tone="info"><Spinner label={label} />Working</Pill> : <Pill>Waiting</Pill>}
                                <span>{label}</span>
                            </div>
                        ))}
                    </div>
                    <p className="sl-help">Keep this page open. It carries the update through step by step.</p>
                </Card>
            )}

            {u.status === 'failed' && (
                <Card title="The update did not finish">
                    <Alert tone="danger" title={`Stopped at: ${STEPS.find(([k]) => k === u.failed_step)?.[1] ?? u.failed_step}`}>{u.error?.message} The site is still in maintenance.</Alert>
                    <p>Nothing is lost: restore the previous version from the backup taken before this update. Anything written to the database since the backup will be lost.</p>
                    <div><Button variant="danger" disabled={busy} onClick={() => setDialog('rollback')}>Roll back to {u.from_version}</Button></div>
                </Card>
            )}

            <Card title="Update history">
                {status.history.length === 0 ? <EmptyState icon="layers" title="No updates yet">Installed updates are listed here.</EmptyState> : (
                    <Table caption="Update history" columns={[{ key: 'v', label: 'Version' }, { key: 'r', label: 'Result' }, { key: 'at', label: 'When' }, { key: 'n', label: 'Notes' }]}
                        rows={status.history.map((h) => ({ v: h.kind === 'database' ? <span className="tnum">Database update ({h.version})</span> : <span className="tnum">{h.from} to {h.version}</span>, r: <Pill tone={(RESULT[h.result] ?? ['muted'])[0]}>{(RESULT[h.result] ?? [0, h.result])[1]}</Pill>, at: <span className="tnum">{formatDateTime(h.at)}</span>, n: h.notes || '-' }))} />
                )}
            </Card>

            <Card title="Backups">
                <div className="flex flex-wrap items-center gap-3">
                    <p style={{ margin: 0 }}>The system backs up daily at 2:00 AM Philippine time. Keep a copy off this server.</p>
                    <Button className="ml-auto" icon="download" disabled={busy || running || status.maintenance} onClick={() => run(async () => { await send('POST', '/system/backups'); setBackups(await get('/system/backups')); return null; }, 'Backup created')}>Back up now</Button>
                </div>
                {backupsError ? <Alert tone="danger" title="The backups list could not be read">{backupsError.message} Check that the backup folder exists and can be written to. Updates will not start without a working backup.</Alert> : !backups ? <Skeleton lines={3} /> : backups.length === 0 ? <EmptyState icon="file" title="No backups yet">The first one is created tonight, or press Back up now.</EmptyState> : (
                    <Table caption="Backups" columns={[{ key: 'at', label: 'Created' }, { key: 'k', label: 'Kind' }, { key: 'v', label: 'Version' }, { key: 'sz', label: 'Size', num: true }, { key: 'o', label: 'Off-server copy' }]}
                        rows={backups.map((b) => ({
                            at: <span className="tnum">{formatDateTime(b.created_at)}</span>, k: b.kind === 'update' ? 'Before an update' : 'Scheduled', v: b.app_version,
                            sz: bytes(b.db_bytes + (b.files_bytes || 0)), o: <Pill tone={b.offsite === 'ok' ? 'success' : b.offsite === 'failed' ? 'danger' : 'muted'}>{b.offsite === 'ok' ? 'Copied' : b.offsite === 'failed' ? 'Failed' : 'None'}</Pill>,
                        }))} />
                )}
            </Card>

            <Dialog open={dialog === 'database'} onClose={() => setDialog(null)} title="Update the database?"
                actions={<><Button onClick={() => setDialog(null)}>Cancel</Button><Button variant="primary" disabled={busy} onClick={() => { setDialog(null); run(() => send('POST', '/system/updates/database', { confirm: 'update' }).then(() => get('/system/updates'))); }}>Start update</Button></>}>
                <p>The site goes into maintenance, a backup is taken and checked, the database changes are applied, and the site comes back. This usually takes a few minutes. Keep this page open.</p>
            </Dialog>
            <TypedConfirm open={dialog === 'approve'} title={`Install version ${u.version}?`} word={u.version ?? ''} action="Start update" busy={busy} onCancel={() => setDialog(null)}
                onConfirm={() => { setDialog(null); run(() => send('POST', '/system/updates/approve', { confirm: u.version }).then(() => get('/system/updates'))); }}>
                <p>The site goes into maintenance, a backup is taken, and the update is installed. This usually takes a few minutes.</p>
            </TypedConfirm>
            <TypedConfirm open={dialog === 'rollback'} title={isDb ? 'Restore the database from the backup?' : 'Roll back to the previous version?'} word="rollback" action="Roll back" tone="danger" busy={busy} onCancel={() => setDialog(null)}
                onConfirm={() => { setDialog(null); run(() => send('POST', '/system/updates/rollback', { confirm: 'rollback' }).then(() => get('/system/updates')), 'Previous version restored'); }}>
                <p>{isDb ? 'The database is restored from the backup taken before this update. The new code stays on the server, so the update will be asked for again until you deploy the previous code or run it again.' : 'The files and the database are restored from the backup taken before the update.'} Anything people saved since then is lost.</p>
            </TypedConfirm>
        </>
    );
}
