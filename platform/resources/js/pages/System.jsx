import { useCallback, useEffect, useState } from 'react';
import { get } from '../lib/api';
import { formatDateTime } from '../lib/util';
import { Table, Tabs } from '../components/ui/Layout';
import { Button } from '../components/ui/Button';
import { ErrorState, Pill, Skeleton } from '../components/ui/Feedback';
import Updates from './Updates';

const TONE = { ok: ['success', 'Working'], warn: ['warning', 'Needs attention'], fail: ['danger', 'Not working'], unknown: ['muted', 'Not set up'] };

function Health() {
    const [checks, setChecks] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        setBusy(true);
        setError(null);
        get('/system/health').then(setChecks).catch(setError).finally(() => setBusy(false));
    }, []);
    useEffect(load, [load]);

    return (
        <>
            <div className="flex flex-wrap items-center gap-3">
                <p style={{ margin: 0 }}>Live checks of what the platform depends on. No credentials are shown here.</p>
                <Button className="ml-auto" icon="refresh" disabled={busy} onClick={load}>{busy ? 'Checking...' : 'Check again'}</Button>
            </div>
            {error ? <ErrorState onRetry={load}>{error.message}</ErrorState> : !checks ? <Skeleton lines={6} /> : (
                <Table caption="System health" columns={[{ key: 'what', label: 'Service' }, { key: 'status', label: 'Status' }, { key: 'detail', label: 'Details' }, { key: 'at', label: 'Checked' }]}
                    rows={checks.map((c) => ({
                        what: <strong>{c.label}</strong>,
                        status: <Pill tone={TONE[c.status][0]}>{TONE[c.status][1]}</Pill>,
                        detail: c.detail, at: <span className="tnum">{formatDateTime(c.checked_at)}</span>,
                    }))} />
            )}
        </>
    );
}

// Super Admin: dependency health and (next) updates and backups.
export default function System() {
    const [tab, setTab] = useState('health');
    return (
        <>
            <h1 className="text-4xl">System</h1>
            <Tabs tabs={[{ id: 'health', label: 'Health' }, { id: 'updates', label: 'Updates' }]} value={tab} onChange={setTab} />
            {tab === 'health' ? <Health /> : <Updates />}
        </>
    );
}
