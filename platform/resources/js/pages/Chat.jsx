import { useCallback, useEffect, useRef, useState } from 'react';
import { get, send, upload } from '../lib/api';
import { errorText } from '../lib/util';
import { Markdown } from '../lib/markdown';
import { useToast } from '../components/ui/Feedback';
import { Alert, EmptyState, Pill, Skeleton, Spinner } from '../components/ui/Feedback';
import { Button, IconButton } from '../components/ui/Button';
import { Dialog, ConfirmDialog, Menu } from '../components/ui/Overlay';
import { TextField } from '../components/ui/Form';
import Icon from '../components/Icon';

const SUGGESTIONS = [
    'Draft a polite follow-up email to a client',
    'Summarize these meeting notes into action items',
    'Explain this spreadsheet formula in plain words',
    'Help me outline a one-page project update',
];

const fmt = (n) => Number(n).toLocaleString();
const sizeText = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
const extOf = (name) => (name.split('.').pop() || '').toLowerCase();

function FileChip({ name, size, tone, children }) {
    return (
        <span className={`chat-chip ${tone ?? ''}`}>
            <Icon name="file" />
            <span className="n">{name}</span>
            {size ? <span className="s">{sizeText(size)}</span> : null}
            {children}
        </span>
    );
}

function UsageMeter({ usage }) {
    if (!usage) return null;
    const tone = usage.percent >= 100 ? 'bad' : usage.percent >= 80 ? 'warn' : '';
    return (
        <div className={`chat-usage ${tone}`} role="status">
            <div className="bar" aria-hidden="true"><i style={{ width: `${usage.percent}%` }} /></div>
            <span>
                {usage.remaining > 0
                    ? `${fmt(usage.remaining)} of ${fmt(usage.total)} tokens left today`
                    : 'Today’s tokens are used up. They reset at midnight (Philippine time).'}
                {usage.includes_estimates ? ' · some counts are estimates' : ''}
            </span>
        </div>
    );
}

export default function Chat() {
    const toast = useToast();
    const [list, setList] = useState(null);
    const [showArchived, setShowArchived] = useState(false);
    const [query, setQuery] = useState('');
    const [current, setCurrent] = useState(null); // {id, title, messages}
    const [loadingConv, setLoadingConv] = useState(false);
    const [usage, setUsage] = useState(null);
    const [draft, setDraft] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null); // {message, code}
    const [pendingText, setPendingText] = useState(null); // shown while waiting for an answer
    const [sensitive, setSensitive] = useState(null); // {message, retry}
    const [rename, setRename] = useState(null);
    const [removing, setRemoving] = useState(null);
    const [editing, setEditing] = useState(null); // {id, text}
    const [sidebar, setSidebar] = useState(false);
    const [files, setFiles] = useState([]); // {key, id?, name, size, status: uploading|ready|error, error?}
    const [limits, setLimits] = useState({ max_count: 6, max_mb: 15, types: ['txt', 'md', 'csv', 'docx', 'xlsx'] });
    const [agents, setAgents] = useState(null); // chatbots this person may use
    const [pickedAgent, setPickedAgent] = useState(null);
    const picker = useRef(null);
    const abort = useRef(null);
    const endRef = useRef(null);
    const box = useRef(null);
    const stick = useRef(true);

    const loadUsage = useCallback(() => get('/usage/today').then(setUsage).catch(() => {}), []);
    const loadList = useCallback(() => get(`/conversations?archived=${showArchived ? 1 : 0}${query ? `&q=${encodeURIComponent(query)}` : ''}`).then(setList).catch(() => setList([])), [showArchived, query]);

    useEffect(() => {
        loadUsage();
        get('/attachments/limits').then(setLimits).catch(() => {});
        get('/chat/agents').then((l) => { setAgents(l); setPickedAgent((l.find((a) => a.is_default) ?? l[0])?.id ?? null); }).catch(() => setAgents([]));
    }, [loadUsage]);

    const attach = async (picked) => {
        let slots = limits.max_count - files.length;
        for (const f of picked) {
            const key = `${Date.now()}-${Math.random()}`;
            const bad = slots <= 0 ? `Up to ${limits.max_count} files per message.`
                : !limits.types.includes(extOf(f.name)) ? `Not supported. Use ${limits.types.map((t) => `.${t}`).join(', ')}.`
                : f.size > limits.max_mb * 1048576 ? `Larger than ${limits.max_mb} MB.` : null;
            if (bad) { setFiles((l) => [...l, { key, name: f.name, size: f.size, status: 'error', error: bad }]); continue; }
            slots -= 1;
            setFiles((l) => [...l, { key, name: f.name, size: f.size, status: 'uploading' }]);
            const form = new FormData(); form.append('file', f);
            try {
                const a = await upload('/attachments', form);
                setFiles((l) => l.map((x) => (x.key === key ? { ...x, id: a.id, status: 'ready', truncated: a.truncated } : x)));
            } catch (e) {
                setFiles((l) => l.map((x) => (x.key === key ? { ...x, status: 'error', error: errorText(e) } : x)));
            }
        }
    };
    const dropFile = async (f) => {
        setFiles((l) => l.filter((x) => x.key !== f.key));
        if (f.id) { try { await send('DELETE', `/attachments/${f.id}`); } catch { /* it will simply stay unsent */ } }
    };
    useEffect(() => { const t = setTimeout(loadList, query ? 250 : 0); return () => clearTimeout(t); }, [loadList, query]);
    useEffect(() => { if (stick.current) endRef.current?.scrollIntoView({ block: 'end' }); }, [current?.messages?.length, busy]);

    const open = async (id) => {
        setSidebar(false); setError(null); setLoadingConv(true);
        try { setCurrent(await get(`/conversations/${id}`)); stick.current = true; }
        catch (e) { toast(errorText(e)); } finally { setLoadingConv(false); }
    };
    const fresh = () => { setCurrent(null); setError(null); setSidebar(false); setDraft(''); box.current?.focus(); };

    const onScroll = (e) => { const el = e.currentTarget; stick.current = el.scrollHeight - el.scrollTop - el.clientHeight < 80; };

    // Every send, edit and retry goes through here. A failed send puts the text back so nothing typed is lost.
    const run = async (fn, restore) => {
        setBusy(true); setError(null);
        const ctl = new AbortController(); abort.current = ctl;
        try {
            const r = await fn(ctl.signal);
            const conv = r.data.conversation;
            setCurrent(await get(`/conversations/${conv.id}`));
            loadList(); loadUsage(); stick.current = true;
            return true;
        } catch (e) {
            if (e.name === 'AbortError') {
                setError({ message: 'Stopped. The assistant may still finish in the background: reopen this conversation in a minute to see it.', code: 'stopped' });
                restore?.();
            } else if (e.code === 'sensitive_warning') {
                setSensitive({ message: e.message, retry: () => run((s) => fn(s, true), restore) });
                restore?.();
            } else {
                setError({ message: errorText(e), code: e.code });
                const cid = e.details?.conversation?.id;
                if (cid) { try { setCurrent(await get(`/conversations/${cid}`)); loadList(); } catch { /* keep going */ } }
                if (['sensitive_blocked', 'validation_failed'].includes(e.code)) restore?.();
                loadUsage();
            }
        } finally { setBusy(false); setPendingText(null); abort.current = null; }
    };

    const post = (path, method, body, signal) => fetchJson(path, method, body, signal);

    const uploading = files.some((f) => f.status === 'uploading');
    const submit = async (text = draft) => {
        const t = text.trim();
        if (!t || busy || uploading) return;
        const ids = files.filter((f) => f.status === 'ready').map((f) => f.id);
        const restore = () => setDraft(t);
        setDraft(''); setPendingText(t);
        // Files are cleared only after a successful send, so a failed send keeps both the text and the files.
        const ok = await run((signal, ack) => post('/chat', 'POST', { message: t, conversation_id: current?.id, agent_id: current ? undefined : (pickedAgent ?? undefined), acknowledge_sensitive: ack || undefined, attachment_ids: ids }, signal), restore);
        if (ok) setFiles((l) => l.filter((f) => !ids.includes(f.id)));
    };

    const stop = () => abort.current?.abort();
    const retry = () => { setPendingText(null); run((signal) => post(`/conversations/${current.id}/regenerate`, 'POST', {}, signal)); };
    const regenerate = retry;
    const saveEdit = () => {
        const { id, text } = editing; setEditing(null); setPendingText(text);
        run((signal, ack) => post(`/conversations/${current.id}/messages/${id}`, 'PATCH', { message: text, acknowledge_sensitive: ack || undefined }, signal));
    };

    const onKey = (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) { e.preventDefault(); submit(); } };
    const copy = async (text) => { try { await navigator.clipboard.writeText(text); toast('Copied'); } catch { toast('Copy was blocked by the browser.'); } };
    const rate = async (m, value) => {
        const next = m.rating === value ? null : value;
        try {
            await send('POST', `/conversations/${current.id}/messages/${m.id}/rating`, { rating: next });
            setCurrent((c) => ({ ...c, messages: c.messages.map((x) => (x.id === m.id ? { ...x, rating: next } : x)) }));
        } catch (e) { toast(errorText(e)); }
    };

    const patchConv = async (c, body, done) => {
        try { await send('PATCH', `/conversations/${c.id}`, body); done?.(); loadList(); if (current?.id === c.id) setCurrent((x) => ({ ...x, ...body })); }
        catch (e) { toast(errorText(e)); }
    };
    const doRename = async () => {
        const title = rename.title.trim();
        if (!title) { setRename({ ...rename, error: 'Give it a name.' }); return; }
        await patchConv(rename.c, { title }, () => setRename(null));
    };
    const doDelete = async () => {
        const c = removing; setRemoving(null);
        try { await send('DELETE', `/conversations/${c.id}`); if (current?.id === c.id) fresh(); loadList(); }
        catch (e) { toast(errorText(e)); }
    };

    const messages = current?.messages ?? [];
    const lastIsUser = messages.length > 0 && messages[messages.length - 1].role === 'user';
    const exhausted = usage && usage.remaining <= 0;
    const several = (agents?.length ?? 0) > 1;
    // A conversation whose chatbot the person can no longer use stays readable but cannot continue.
    const locked = !!(current?.agent && agents && !agents.some((a) => a.id === current.agent.id));
    const blocked = exhausted || locked;

    return (
        <div className="chat-shell">
            <aside className={`chat-side ${sidebar ? 'open' : ''}`} aria-label="Conversations">
                <Button variant="primary" size="sm" icon="plus" onClick={fresh}>New chat</Button>
                <label className="chat-search">
                    <Icon name="search" /><span className="sr-only">Search conversations</span>
                    <input type="search" placeholder="Search" value={query} onChange={(e) => setQuery(e.target.value)} />
                </label>
                <div className="chat-tabs" role="group" aria-label="Show">
                    <button type="button" className={!showArchived ? 'on' : ''} onClick={() => setShowArchived(false)}>Chats</button>
                    <button type="button" className={showArchived ? 'on' : ''} onClick={() => setShowArchived(true)}>Archived</button>
                </div>
                <nav className="chat-list">
                    {list === null && <Skeleton lines={4} />}
                    {list?.length === 0 && <p className="chat-none">{query ? 'No conversations match.' : showArchived ? 'Nothing archived.' : 'No conversations yet.'}</p>}
                    {list?.map((c) => (
                        <div key={c.id} className={`chat-item ${current?.id === c.id ? 'on' : ''}`}>
                            <button type="button" className="title" aria-current={current?.id === c.id ? 'true' : undefined} onClick={() => open(c.id)}>{c.title}{several && c.agent && <span className="agent">{c.agent.name}</span>}</button>
                            <Menu label={`Actions for ${c.title}`} items={[
                                { label: 'Rename', icon: 'edit', onSelect: () => setRename({ c, title: c.title }) },
                                { label: c.archived ? 'Unarchive' : 'Archive', icon: 'layers', onSelect: () => patchConv(c, { archived: !c.archived }, () => { if (current?.id === c.id && !c.archived) fresh(); }) },
                                { label: 'Delete', icon: 'trash', onSelect: () => setRemoving(c) },
                            ]} />
                        </div>
                    ))}
                </nav>
            </aside>

            <section className="chat-main" aria-label="Conversation">
                <div className="chat-bar">
                    <IconButton icon="panel" label="Conversations" className="chat-toggle" on={sidebar} onClick={() => setSidebar(!sidebar)} />
                    <h1 className="chat-title">{current?.title ?? 'New chat'}</h1>
                    {current?.agent && <Pill tone="info">{current.agent.name}</Pill>}
                </div>

                <div className="chat-scroll" onScroll={onScroll} tabIndex={-1}>
                    {loadingConv && <Spinner label="Opening conversation" />}
                    {!loadingConv && !current && !pendingText && (
                        <EmptyState icon="sparkle" title={agents && agents.length === 0 ? 'No chatbot is available yet' : 'How can I help today?'}>
                            {several && (
                                <div className="chat-agents" role="radiogroup" aria-label="Choose a chatbot">
                                    {agents.map((a) => (
                                        <button key={a.id} type="button" role="radio" aria-checked={pickedAgent === a.id} className={pickedAgent === a.id ? 'on' : ''} onClick={() => setPickedAgent(a.id)}>
                                            <strong>{a.name}</strong>{a.description && <span>{a.description}</span>}
                                        </button>
                                    ))}
                                </div>
                            )}
                            <p>Ask for help with everyday office work: writing, summarizing, planning and explaining.</p>
                            <div className="chat-suggest">
                                {SUGGESTIONS.map((s) => <button key={s} type="button" disabled={busy || blocked} onClick={() => submit(s)}>{s}</button>)}
                            </div>
                        </EmptyState>
                    )}
                    <ol className="chat-thread" aria-live="polite">
                        {messages.map((m, i) => (
                            <li key={m.id} className={`msg ${m.role}`}>
                                <div className="who">{m.role === 'user' ? 'You' : 'Assistant'}</div>
                                {editing?.id === m.id ? (
                                    <div className="space-y-2">
                                        <textarea className="sl-textarea" aria-label="Edit your message" value={editing.text} onChange={(e) => setEditing({ ...editing, text: e.target.value })} />
                                        <div className="chat-actions">
                                            <Button size="sm" variant="primary" disabled={!editing.text.trim() || busy} onClick={saveEdit}>Save and resend</Button>
                                            <Button size="sm" onClick={() => setEditing(null)}>Cancel</Button>
                                        </div>
                                        <p className="chat-note">Messages after this one are replaced, and the new answer uses tokens again.</p>
                                    </div>
                                ) : m.role === 'user' ? <p className="plain">{m.content}</p> : <Markdown text={m.content} />}
                                {m.attachments?.length > 0 && (
                                    <div className="chat-chips">
                                        {m.attachments.map((a) => (
                                            <a key={a.id} className="chat-chip link" href={`/api/attachments/${a.id}/download`} download aria-label={`Download ${a.name}`}>
                                                <Icon name="file" /><span className="n">{a.name}</span><span className="s">{sizeText(a.size)}</span><Icon name="download" />
                                            </a>
                                        ))}
                                    </div>
                                )}
                                {editing?.id !== m.id && (
                                    <div className="chat-actions">
                                        <IconButton icon="copy" label="Copy" onClick={() => copy(m.content)} />
                                        {m.role === 'user' && i === messages.length - 1 - (lastIsUser ? 0 : 1) && !busy && <IconButton icon="edit" label="Edit message" onClick={() => setEditing({ id: m.id, text: m.content })} />}
                                        {m.role === 'assistant' && <>
                                            <IconButton icon="thumb-up" label="Good answer" on={m.rating === 'up'} aria-pressed={m.rating === 'up'} onClick={() => rate(m, 'up')} />
                                            <IconButton icon="thumb-down" label="Poor answer" on={m.rating === 'down'} aria-pressed={m.rating === 'down'} onClick={() => rate(m, 'down')} />
                                            {i === messages.length - 1 && !busy && <IconButton icon="refresh" label="Regenerate answer" onClick={regenerate} />}
                                        </>}
                                    </div>
                                )}
                            </li>
                        ))}
                        {pendingText && !messages.some((m) => m.content === pendingText && m.role === 'user' && lastIsUser) && (
                            <li className="msg user"><div className="who">You</div><p className="plain">{pendingText}</p></li>
                        )}
                        {busy && <li className="msg assistant" aria-label="The assistant is writing"><div className="who">Assistant</div><div className="chat-dots" aria-hidden="true"><i /><i /><i /></div></li>}
                    </ol>
                    <div ref={endRef} />
                </div>

                <div className="chat-foot">
                    {error && (
                        <Alert tone={error.code === 'stopped' ? 'info' : 'danger'} title={error.code === 'token_limit' ? 'Out of tokens for today' : error.code === 'stopped' ? 'Stopped' : 'Could not get an answer'}>
                            <p>{error.message}</p>
                            {current && lastIsUser && !busy && !['token_limit', 'stopped', 'sensitive_blocked'].includes(error.code) && <div><Button size="sm" variant="soft" icon="refresh" onClick={retry}>Try again</Button></div>}
                        </Alert>
                    )}
                    {locked && <Alert tone="info" title="This chatbot is not available to you"><p>You can still read this conversation, but you cannot continue it. Ask an administrator if you need access.</p></Alert>}
                    <UsageMeter usage={usage} />
                    {files.length > 0 && (
                        <div className="chat-chips" aria-label="Attached files">
                            {files.map((f) => (
                                <FileChip key={f.key} name={f.name} size={f.size} tone={f.status === 'error' ? 'bad' : ''}>
                                    {f.status === 'uploading' && <span className="s" role="status">Uploading…</span>}
                                    {f.status === 'ready' && f.truncated && <span className="s" title="Only the first part of this file is sent">shortened</span>}
                                    {f.status === 'error' && <span className="s err" role="alert">{f.error}</span>}
                                    <button type="button" className="x" aria-label={`Remove ${f.name}`} onClick={() => dropFile(f)}><Icon name="x" /></button>
                                </FileChip>
                            ))}
                        </div>
                    )}
                    <div className="chat-composer">
                        <input ref={picker} type="file" multiple hidden accept={limits.types.map((t) => `.${t}`).join(',')} onChange={(e) => { attach([...e.target.files]); e.target.value = ''; }} />
                        <IconButton icon="clip" label="Attach files" disabled={busy || blocked} onClick={() => picker.current?.click()} />
                        <textarea ref={box} className="sl-textarea" rows={2} aria-label="Message" placeholder={locked ? 'You can no longer use this chatbot' : exhausted ? 'Your tokens for today are used up' : 'Message the assistant (Enter to send, Shift+Enter for a new line)'}
                            value={draft} disabled={blocked} maxLength={20000} onChange={(e) => setDraft(e.target.value)} onKeyDown={onKey} />
                        {busy
                            ? <Button variant="dark" icon="stop" onClick={stop}>Stop</Button>
                            : <Button variant="primary" icon="up" disabled={!draft.trim() || blocked || uploading} onClick={() => submit()}>Send</Button>}
                    </div>
                    <p className="chat-note">AI answers can be wrong: check anything important. Do not enter passwords, card numbers or ID numbers.</p>
                </div>
            </section>

            <Dialog open={!!rename} onClose={() => setRename(null)} title="Rename conversation"
                actions={<><Button onClick={() => setRename(null)}>Cancel</Button><Button variant="primary" onClick={doRename}>Save</Button></>}>
                {rename && <TextField label="Name" value={rename.title} error={rename.error} maxLength={150} onChange={(e) => setRename({ ...rename, title: e.target.value, error: undefined })} />}
            </Dialog>
            <ConfirmDialog open={!!removing} onCancel={() => setRemoving(null)} onConfirm={doDelete} title="Delete this conversation?">
                It disappears from your list. Your administrators may keep a copy under the company retention rules.
            </ConfirmDialog>
            <Dialog open={!!sensitive} onClose={() => setSensitive(null)} title="Check before sending"
                actions={<><Button onClick={() => setSensitive(null)}>Go back</Button><Button variant="primary" onClick={() => { const s = sensitive; setSensitive(null); s.retry(); }}>Send anyway</Button></>}>
                <p>{sensitive?.message}</p>
            </Dialog>
        </div>
    );
}

// The shared api() helper has no abort support, so chat requests use a thin variant of it.
async function fetchJson(path, method, body, signal) {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    let res;
    try {
        res = await fetch(`/api${path}`, {
            method, signal, credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(m ? { 'X-XSRF-TOKEN': decodeURIComponent(m[1]) } : {}) },
            body: JSON.stringify(body),
        });
    } catch (e) {
        if (e.name === 'AbortError') throw e;
        const err = new Error('Could not reach the server. Check your connection and try again.'); err.code = 'network'; throw err;
    }
    let json = null;
    try { json = await res.json(); } catch { /* not JSON */ }
    if (!res.ok) {
        const e = json?.error;
        const err = new Error(e?.message ?? 'Something went wrong.'); err.code = e?.code ?? 'http_error'; err.details = e?.details ?? {}; err.status = res.status;
        if (res.status === 401) window.dispatchEvent(new CustomEvent('api:session-lost', { detail: { code: err.code, message: err.message } }));
        throw err;
    }
    return { data: json?.data };
}
