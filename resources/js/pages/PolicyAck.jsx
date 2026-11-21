import { useEffect, useState } from 'react';
import { useAuth } from '../lib/auth';
import { get } from '../lib/api';
import { Checkbox } from '../components/ui/Form';
import { ErrorState, Skeleton } from '../components/ui/Feedback';
import GuestFrame from '../components/GuestFrame';
import Icon from '../components/Icon';

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
        <GuestFrame width={520} label="Usage policy">
            <h1 id="policy-title">{policy?.title ?? 'Before you start'}</h1>
            <p className="lg-lead">Welcome, {user.name}. Please read this and confirm before you continue.</p>
            {loadError ? <div className="mt-4"><ErrorState onRetry={load}>{loadError.message}</ErrorState></div> : !policy ? <div className="mt-4"><Skeleton lines={4} /></div> : (
                <>
                    <div className="lg-policy">{policy.body.map((t, i) => <p key={i}>{t}</p>)}</div>
                    <div className="mt-4"><Checkbox label="I have read and understand this policy." checked={agreed} onChange={(e) => setAgreed(e.target.checked)} /></div>
                </>
            )}
            {error && <div className="lg-msg bad" role="alert"><Icon name="alert" /><div><b>Could not save</b>{error}</div></div>}
            <div className="lg-actions">
                <button type="button" className="lg-btn ghost" onClick={logout}>Sign out</button>
                <button type="button" className="lg-btn" disabled={!agreed || busy || !policy} onClick={submit}>{busy ? 'Saving...' : 'Continue'}</button>
            </div>
        </GuestFrame>
    );
}
