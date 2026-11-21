import { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api';
import { useAttendanceStatus } from '../lib/heartbeat';
import { addDays, formatDuration, formatManilaDate, formatManilaTime, manilaToday } from '../lib/util';
import { Card, Stat, Table } from '../components/ui/Layout';
import { Button } from '../components/ui/Button';
import { TextField } from '../components/ui/Form';
import { Alert, EmptyState, ErrorState, Pill, Skeleton } from '../components/ui/Feedback';

const STATE = { active: ['success', 'Active'], idle: ['warning', 'Idle'], offline: ['muted', 'Offline'] };

// The employee's own record: today at a glance and the last 30 days. Official time is Philippine time.
export default function Attendance() {
    const live = useAttendanceStatus();
    const [range, setRange] = useState(() => ({ from: addDays(manilaToday(), -29), to: manilaToday() }));
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        setError(null);
        api(`/attendance/me?from=${range.from}&to=${range.to}`).then(setResult).catch(setError);
    }, [range]);
    useEffect(load, [load]);
    // Keep today's numbers fresh as heartbeats arrive.
    useEffect(() => { if (live.today && range.to === manilaToday()) load(); }, [live.state]); // eslint-disable-line react-hooks/exhaustive-deps

    const [tone, label] = STATE[live.state] ?? STATE.offline;
    const today = live.today ?? result?.data.today;
    return (
        <>
            <div className="flex flex-wrap items-center gap-3">
                <p style={{ margin: 0 }}>Time is recorded in Philippine time (Asia/Manila). Idle time is shown separately and is not counted as work.</p>
                <Pill tone={tone}>{label} now</Pill>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
                <Card><Stat value={formatDuration(today?.active_seconds)} label="Active today" /></Card>
                <Card><Stat value={formatDuration(today?.idle_seconds)} label="Idle today" /></Card>
                <Card><Stat value={today?.work_date ?? manilaToday()} label="Business day" /></Card>
            </div>

            <div className="grid items-end gap-3 sm:grid-cols-3">
                <TextField label="From" type="date" value={range.from} max={range.to} onChange={(e) => setRange({ ...range, from: e.target.value })} />
                <TextField label="To" type="date" value={range.to} min={range.from} onChange={(e) => setRange({ ...range, to: e.target.value })} />
                <div><Button onClick={() => setRange({ from: addDays(manilaToday(), -29), to: manilaToday() })}>Last 30 days</Button></div>
            </div>

            {error ? <ErrorState onRetry={load}>{error.code === 'validation_failed' ? 'Check the dates and try again.' : error.message}</ErrorState> : !result ? <Skeleton lines={5} /> : result.data.days.length === 0 ? (
                <EmptyState icon="clock" title="No attendance in this period">Your time is recorded automatically while you are signed in.</EmptyState>
            ) : (
                <Table caption="My attendance" columns={[
                    { key: 'date', label: 'Date' }, { key: 'in', label: 'Time in' }, { key: 'out', label: 'Time out' },
                    { key: 'active', label: 'Active', num: true }, { key: 'idle', label: 'Idle', num: true }, { key: 'note', label: '' },
                ]} rows={result.data.days.map((d) => ({
                    date: formatManilaDate(d.work_date), in: formatManilaTime(d.first_in_at), out: formatManilaTime(d.last_out_at),
                    active: formatDuration(d.active_seconds), idle: formatDuration(d.idle_seconds),
                    note: d.is_corrected ? <Pill tone="info">Corrected</Pill> : null,
                }))} />
            )}
            {live.state === 'offline' && live.today === null && <Alert tone="info" title="Connecting">Waiting for the first check-in from this page.</Alert>}
        </>
    );
}
