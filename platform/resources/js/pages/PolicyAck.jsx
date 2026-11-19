import { useEffect, useState } from 'react';
import { useAuth } from '../lib/auth';
import { get } from '../lib/api';
import { Button } from '../components/ui/Button';
import { Checkbox } from '../components/ui/Form';
import { Alert, ErrorState, Skeleton } from '../components/ui/Feedback';
import { Brand } from '../lib/branding';

// Shown once per policy version, before anything else in the app.
export default function PolicyAck() {
    const { user, acknowledgePolicy, logout } = useAuth();
    const [policy, setPolicy] = useState(null);
    const [loadError, setLoadError] = useState(null);
    const [agreed, setAgreed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const load = () => { setLoadError(null); get('/policy').then(setPolicy).catch(setLoadError); };
    useEffect(load, []);

    const submit = async () => {
        setBusy(true);
        setError(null);
        try { await acknowledgePolicy(); } catch (e) { setError(e.message); setBusy(false); }
    };

    return (
        <main className="mx-auto flex min-h-screen w-full max-w-[640px] flex-col justify-center gap-6 px-6 py-10">
            <Brand className="font-display text-3xl font-extrabold text-navy" />
            <section className="sl-card" aria-labelledby="policy-title">
                <h1 id="policy-title" className="text-3xl">{policy?.title ?? 'Before you start'}</h1>
                <p>Welcome, {user.name}. Please read this and confirm before you continue.</p>
                {loadError ? <ErrorState onRetry={load}>{loadError.message}</ErrorState> : !policy ? <Skeleton lines={4} /> : (
                    <>
                        <div className="grid gap-3" style={{ color: 'var(--sl-text)' }}>{policy.body.map((t, i) => <p key={i}>{t}</p>)}</div>
                        <Checkbox label="I have read and understand this policy." checked={agreed} onChange={(e) => setAgreed(e.target.checked)} />
                    </>
                )}
                {error && <Alert tone="danger" title="Could not save">{error}</Alert>}
                <div className="sl-btn-row mt-2 justify-end">
                    <Button onClick={logout}>Sign out</Button>
                    <Button variant="primary" disabled={!agreed || busy || !policy} onClick={submit}>{busy ? 'Saving...' : 'Continue'}</Button>
                </div>
            </section>
        </main>
    );
}
