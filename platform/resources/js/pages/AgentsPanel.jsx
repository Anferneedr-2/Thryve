import { useCallback, useEffect, useState } from 'react';
import { get, send } from '../lib/api';
import { errorText, formatDateTime } from '../lib/util';
import { Table } from '../components/ui/Layout';
import { Button } from '../components/ui/Button';
import { TextField } from '../components/ui/Form';
import { Alert, EmptyState, ErrorState, Pill, Skeleton, useToast } from '../components/ui/Feedback';
import { ConfirmDialog, Dialog, Menu } from '../components/ui/Overlay';

const STATUS = {
    never_tested: ['muted', 'Not tested yet'], working: ['success', 'Working'], slow: ['warning', 'Working, but slow'],
    key_rejected: ['danger', 'Key rejected'], profile_missing: ['danger', 'Profile not found'], unreachable: ['warning', 'Not reachable'], wrong_address: ['danger', 'Not the API address'], revoked: ['danger', 'Revoked'],
};
const CATEGORY = {
    ok: 'Working', unauthorized: 'The server rejected the key', profile_missing: 'The profile was not found on the server', unavailable: 'The server could not be reached',
    timeout: 'The server took too long to answer', rate_limited: 'The server is rate limiting', bad_response: 'The server gave an unexpected answer',
    login_page: 'The address answered with a web login page, not the Hermes API. The API needs its own address.',
    no_key: 'The agent has no key', no_provider: 'No provider connection is available to run a message', budget_used: 'The background token budget is used up',
};
const KIND = { ping: 'Connection test', chat: 'Message test', verify: 'New key check' };
const fieldErrors = (err) => Object.fromEntries(Object.entries(err.details ?? {}).map(([k, v]) => [k, v[0]]));

function CopyBox({ label, value }) {
    const toast = useToast();
    const copy = async () => { try { await navigator.clipboard.writeText(value); toast('Copied'); } catch { toast('Copy was blocked. Select the text and copy it by hand.'); } };
    return (
        <div className="grid gap-1">
            <span className="sl-help">{label}</span>
            <div className="flex items-center gap-2">
                <code className="tnum" style={{ flex: 1, overflowWrap: 'anywhere', padding: '8px 10px', borderRadius: 10, background: 'var(--slate-100)', fontSize: 13 }} data-testid="secret-value">{value}</code>
                <Button size="sm" icon="copy" onClick={copy}>Copy</Button>
            </div>
        </div>
    );
}

// Shown once, straight after a key is created. Closing it forgets the keys: they are not stored in the browser.
function KeyReveal({ reveal, onClose }) {
    const { agent, keys, title } = reveal;
    return (
        <Dialog open title={title} onClose={onClose} actions={<Button variant="primary" onClick={onClose}>I have saved the keys</Button>}>
            <div className="grid gap-4">
                <Alert tone="warning" title="Copy these now">They are shown only once and cannot be viewed again. If you lose one, create a new one.</Alert>
                {keys.outbound && (
                    <>
                        <CopyBox label="1. Access key for the agent. On the VPS, put this in the Hermes .env (main and profile) and restart the gateway:" value={`API_SERVER_KEY=${keys.outbound}`} />
                        <p className="sl-help">Then come back here and press Test{reveal.rotating ? ' (or "Verify and activate" for a rotation)' : ''}.</p>
                    </>
                )}
                {keys.inbound && (
                    <CopyBox label={`${keys.outbound ? '2. ' : ''}Key for the agent to call this system. Send it as a Bearer token to ${window.location.origin}/api/agent/ping:`} value={keys.inbound} />
                )}
                <p className="sl-help">Agent: {agent.name}</p>
            </div>
        </Dialog>
    );
}

function AgentForm({ agent, onClose, onSaved }) {
    const [form, setForm] = useState({ name: agent?.name ?? '', base_url: agent?.base_url ?? '', profile: agent?.profile ?? '', description: agent?.description ?? '' });
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [busy, setBusy] = useState(false);
    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
    const submit = async (e) => {
        e.preventDefault();
        setBusy(true); setErrors({}); setFormError(null);
        try {
            const body = { name: form.name.trim(), base_url: form.base_url.trim(), profile: form.profile.trim() || null, description: form.description.trim() || null };
            const saved = await send(agent ? 'PATCH' : 'POST', agent ? `/agents/${agent.id}` : '/agents', body);
            onSaved(saved, !agent);
        } catch (err) {
            if (Object.keys(err.details ?? {}).length) setErrors(fieldErrors(err)); else setFormError(err.message);
            setBusy(false);
        }
    };
    return (
        <Dialog open title={agent ? 'Edit agent' : 'Add an agent'} onClose={onClose}
            actions={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" type="submit" form="agent-form" disabled={busy || !form.name || !form.base_url}>{busy ? 'Saving...' : agent ? 'Save' : 'Add and create keys'}</Button></>}>
            <form id="agent-form" onSubmit={submit} className="grid gap-4" noValidate>
                {formError && <Alert tone="danger" title="Could not save">{formError}</Alert>}
                <TextField label="Name" value={form.name} onChange={set('name')} error={errors.name} placeholder="General Assistant" />
                <TextField label="Server address" value={form.base_url} onChange={set('base_url')} error={errors.base_url} placeholder="https://ai.yourcompany.com" help="Where Hermes runs. Use https:// (http:// is allowed only on this computer)." />
                <TextField label="Profile name" value={form.profile} onChange={set('profile')} error={errors.profile} placeholder="thryve-bot" help="The Hermes profile this agent uses. Leave empty if the server serves one agent at its address." />
                <TextField label="Description for employees (optional)" value={form.description} onChange={set('description')} error={errors.description} maxLength={200} placeholder="Helps with everyday writing and questions" help="Shown when someone picks this chatbot." />
                {agent && <p className="sl-help">Changing the address or profile resets the status until you test again.</p>}
            </form>
        </Dialog>
    );
}

function History({ agent, onClose }) {
    const [rows, setRows] = useState(null);
    useEffect(() => { get(`/agents/${agent.id}/checks`).then(setRows).catch(() => setRows([])); }, [agent.id]);
    return (
        <Dialog open title={`Test history: ${agent.name}`} onClose={onClose} actions={<Button onClick={onClose}>Close</Button>}>
            {rows === null ? <Skeleton lines={4} /> : rows.length === 0 ? <p>No tests yet.</p> : (
                <Table caption="Test history" columns={[{ key: 'when', label: 'When' }, { key: 'what', label: 'Test' }, { key: 'result', label: 'Result' }, { key: 'ms', label: 'Time', num: true }, { key: 'by', label: 'By' }]}
                    rows={rows.map((r) => ({
                        when: formatDateTime(r.at), what: KIND[r.kind] ?? r.kind,
                        result: <Pill tone={r.ok ? 'success' : 'danger'}>{CATEGORY[r.category] ?? r.category}</Pill>,
                        ms: r.latency_ms === null ? '-' : `${r.latency_ms} ms${r.tokens ? ` · ${r.tokens} tokens` : ''}`, by: r.by ?? '-',
                    }))} />
            )}
        </Dialog>
    );
}

function AgentCard({ a, canManage, busyId, run, ask, edit, history }) {
    const [tone, label] = STATUS[a.status] ?? ['muted', a.status];
    const busy = busyId === a.id;
    return (
        <section className="sl-card" aria-label={a.name}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid gap-1">
                    <h5 className="flex flex-wrap items-center gap-2">{a.name}{a.is_default && <Pill tone="info">Default</Pill>}{!a.is_enabled && <Pill>Disabled</Pill>}</h5>
                    <span className="sl-help" style={{ overflowWrap: 'anywhere' }}>{a.url}</span>
                    {a.description && <span className="sl-help">{a.description}</span>}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Pill tone={tone}>{label}</Pill>
                    {canManage && <Button size="sm" variant="soft" icon="refresh" disabled={busy || !a.has_key} onClick={() => run(a, 'test')}>{busy ? 'Testing...' : 'Test connection'}</Button>}
                    <Button size="sm" onClick={() => history(a)}>History</Button>
                    {canManage && (
                        <Menu label={`Actions for ${a.name}`} items={[
                            { label: 'Test with a message (uses tokens)', icon: 'chat', onSelect: () => ask({ kind: 'message', a }) },
                            { label: 'Rotate key', icon: 'refresh', onSelect: () => run(a, 'rotate') },
                            { label: 'New key for the agent to call us', icon: 'share', onSelect: () => ask({ kind: 'inbound', a }) },
                            ...(a.is_default ? [] : [{ label: 'Make default', icon: 'star', onSelect: () => run(a, 'default') }]),
                            ...(a.is_default ? [] : [{ label: a.is_enabled ? 'Disable' : 'Enable', icon: 'eye', onSelect: () => run(a, 'toggle') }]),
                            { label: 'Edit', icon: 'edit', onSelect: () => edit(a) },
                            { label: 'Revoke keys', icon: 'x', onSelect: () => ask({ kind: 'revoke', a }) },
                            { label: 'Remove', icon: 'trash', onSelect: () => ask({ kind: 'remove', a }) },
                        ]} />
                    )}
                </div>
            </div>
            {a.status === 'wrong_address' && (
                <Alert tone="warning" title="This address shows a web login page">
                    <p>The server answered, but with its web login instead of the Hermes API, so Thryve cannot use it. Set up a separate HTTPS address that goes straight to the Hermes API (port 8642), turn the API on with the key below, then edit this agent and use that address.</p>
                </Alert>
            )}
            <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2" style={{ margin: 0 }}>
                <div><dt className="sl-help">Access key</dt><dd className="tnum">{a.has_key ? `Ends in ${a.key_fingerprint}` : 'None (revoked)'}</dd></div>
                <div><dt className="sl-help">Last tested</dt><dd>{a.last_checked_at ? `${formatDateTime(a.last_checked_at)}${a.last_latency_ms !== null ? ` · ${a.last_latency_ms} ms` : ''}` : 'Never'}</dd></div>
                <div><dt className="sl-help">Agent calling this system</dt><dd>{a.inbound_last_seen_at ? formatDateTime(a.inbound_last_seen_at) : a.inbound_fingerprint ? 'Not heard from yet' : 'No key issued'}</dd></div>
                <div><dt className="sl-help">Key to call this system</dt><dd className="tnum">{a.inbound_fingerprint ? `Ends in ${a.inbound_fingerprint}` : 'None'}</dd></div>
            </dl>
            {a.pending_fingerprint && (
                <Alert tone="warning" title="A new key is waiting">
                    <p>Key ending in {a.pending_fingerprint} is not in use yet. Put it on the VPS, then verify. Until then the current key keeps working.</p>
                    {canManage && (
                        <div className="flex flex-wrap gap-2">
                            <Button size="sm" variant="primary" disabled={busy} onClick={() => run(a, 'activate')}>{busy ? 'Checking...' : 'Verify and activate'}</Button>
                            <Button size="sm" disabled={busy} onClick={() => run(a, 'cancel')}>Cancel rotation</Button>
                        </div>
                    )}
                </Alert>
            )}
        </section>
    );
}

// Admins can see status and history; only a Super Admin can change anything. The server enforces this.
export default function AgentsPanel({ canManage }) {
    const toast = useToast();
    const [agents, setAgents] = useState(null);
    const [error, setError] = useState(null);
    const [busyId, setBusyId] = useState(null);
    const [form, setForm] = useState(null); // {agent?}
    const [reveal, setReveal] = useState(null);
    const [confirm, setConfirm] = useState(null);
    const [hist, setHist] = useState(null);
    const [result, setResult] = useState(null); // last test outcome, announced to screen readers

    const load = useCallback(() => get('/agents').then((r) => { setAgents(r); setError(null); }).catch((e) => setError(errorText(e))), []);
    useEffect(() => { load(); }, [load]);

    const verdict = (a, check) => setResult({ ok: check.ok, text: `${a.name}: ${CATEGORY[check.category] ?? check.category}${check.latency_ms !== null ? ` (${check.latency_ms} ms)` : ''}` });

    const run = async (a, what) => {
        setBusyId(a.id);
        try {
            if (what === 'test') { const r = await send('POST', `/agents/${a.id}/test`, {}); verdict(a, r.check); }
            else if (what === 'rotate') { const r = await send('POST', `/agents/${a.id}/rotate`, {}); setReveal({ agent: r, keys: r.keys, title: 'New key created', rotating: true }); }
            else if (what === 'activate') {
                const r = await send('POST', `/agents/${a.id}/rotate/activate`, {});
                if (r.activated) toast('New key is now in use'); else setResult({ ok: false, text: `The new key did not work (${CATEGORY[r.check.category] ?? r.check.category}). The old key is still in use.` });
            }
            else if (what === 'cancel') await send('DELETE', `/agents/${a.id}/rotate`);
            else if (what === 'default') await send('PATCH', `/agents/${a.id}`, { is_default: true });
            else if (what === 'toggle') await send('PATCH', `/agents/${a.id}`, { is_enabled: !a.is_enabled });
            await load();
        } catch (e) { toast(errorText(e)); } finally { setBusyId(null); }
    };

    const doConfirm = async () => {
        const { kind, a } = confirm;
        setConfirm(null); setBusyId(a.id);
        try {
            if (kind === 'message') { const r = await send('POST', `/agents/${a.id}/test`, { with_message: true }); verdict(a, r.check); }
            else if (kind === 'inbound') { const r = await send('POST', `/agents/${a.id}/inbound-key`, {}); setReveal({ agent: r, keys: { inbound: r.keys.inbound }, title: 'New key for the agent' }); }
            else if (kind === 'revoke') { await send('POST', `/agents/${a.id}/revoke`, {}); toast('Keys revoked'); }
            else if (kind === 'remove') { await send('DELETE', `/agents/${a.id}`); toast('Agent removed'); }
            await load();
        } catch (e) { toast(errorText(e)); } finally { setBusyId(null); }
    };

    const CONFIRM = {
        message: ['Send a test message?', 'This sends one short message to the agent and uses a few thousand tokens from the background budget.', 'Send test'],
        inbound: ['Create a new key for the agent?', 'The agent\'s current key for calling this system stops working immediately.', 'Create key'],
        revoke: ['Revoke this agent\'s keys?', 'Chat cannot use the agent and the agent cannot call this system until you create new keys. If it is the default agent, chat stops working.', 'Revoke'],
        remove: ['Remove this agent?', 'Its keys are wiped and it disappears from the list. Past usage records are kept.', 'Remove'],
    };

    if (error) return <ErrorState onRetry={load}>{error}</ErrorState>;
    if (!agents) return <Skeleton lines={5} />;
    return (
        <>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="sl-help" style={{ maxWidth: 640 }}>An agent is an AI profile running on your Hermes server. This system creates its access key; you install the key on the server, then press Test. Nothing is tested automatically.</p>
                {canManage && <Button variant="primary" icon="plus" onClick={() => setForm({})}>Add agent</Button>}
            </div>
            {result && <Alert tone={result.ok ? 'success' : 'danger'} title={result.ok ? 'Test passed' : 'Test failed'}><p role="status">{result.text}</p></Alert>}
            {agents.length === 0 ? (
                <EmptyState icon="chip" title="No agents yet" action={canManage && <Button variant="primary" onClick={() => setForm({})}>Add the first agent</Button>}>
                    <p>Until an agent is added, chat uses the server address from the install settings. Every agent you add becomes a chatbot: the default one is open to everyone, and you choose who gets the others on each person's page under People.</p>
                </EmptyState>
            ) : (
                <div className="grid gap-4">
                    {agents.map((a) => <AgentCard key={a.id} a={a} canManage={canManage} busyId={busyId} run={run} ask={setConfirm} edit={(x) => setForm({ agent: x })} history={setHist} />)}
                </div>
            )}
            {form && <AgentForm agent={form.agent} onClose={() => setForm(null)} onSaved={(saved, created) => {
                setForm(null);
                if (created) setReveal({ agent: saved, keys: saved.keys, title: 'Agent created: save its keys' }); else toast('Saved');
                load();
            }} />}
            {reveal && <KeyReveal reveal={reveal} onClose={() => { setReveal(null); load(); }} />}
            {hist && <History agent={hist} onClose={() => setHist(null)} />}
            <ConfirmDialog open={!!confirm} onCancel={() => setConfirm(null)} onConfirm={doConfirm} title={confirm ? CONFIRM[confirm.kind][0] : ''} confirmLabel={confirm ? CONFIRM[confirm.kind][2] : 'OK'}>
                {confirm ? CONFIRM[confirm.kind][1] : ''}
            </ConfirmDialog>
        </>
    );
}
