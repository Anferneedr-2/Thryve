import { useEffect, useRef, useState } from 'react';
import { get, upload } from '../lib/api';
import { errorText } from '../lib/util';
import { Card } from '../components/ui/Layout';
import { Button } from '../components/ui/Button';
import { Switch, TextField } from '../components/ui/Form';
import { Alert, useToast } from '../components/ui/Feedback';

const mb = (n) => `${Math.max(1, Math.round(n / 1048576))} MB`;

// Super Admin. Brings a chatbot's chats and access key from another installation, with no terminal needed.
export default function Transfer() {
    const toast = useToast();
    const [limit, setLimit] = useState(null);
    useEffect(() => { get('/settings/transfer').then((d) => setLimit(d.max_upload_bytes)).catch(() => {}); }, []);

    return (
        <div className="grid gap-5" style={{ maxWidth: 760 }}>
            <Alert tone="info" title="Move a chatbot from another device">
                Do step 1, then step 2. Importing the chats creates the chatbot here if it is missing. People are matched by username and are never created, so
                they must already exist here. Nothing already here is replaced, and importing the same file twice is safe.
                {limit ? ` This server accepts files up to ${mb(limit)}.` : ''}
            </Alert>
            <ChatsCard toast={toast} />
            <KeyCard toast={toast} />
        </div>
    );
}

function ChatsCard({ toast }) {
    const file = useRef(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [report, setReport] = useState(null);

    const run = async (dry) => {
        const f = file.current?.files?.[0];
        if (!f) return setError('Choose the chat file first (general-chats.json).');
        setBusy(true);
        setError(null);
        try {
            const fd = new FormData();
            fd.append('file', f);
            fd.append('dry_run', dry ? '1' : '0');
            const r = await upload('/settings/transfer/chats', fd);
            setReport(r);
            if (!dry) toast(`${r.imported} chat${r.imported === 1 ? '' : 's'} imported`);
        } catch (e) { setError(errorText(e)); setReport(null); }
        setBusy(false);
    };
    const missing = report ? Object.entries(report.missing_users) : [];

    return (
        <Card title="1. Chats">
            <p className="sl-help">Choose <code>general-chats.json</code>, made on the other device. Press Preview first to see what would happen.</p>
            {error && <Alert tone="danger" title="Could not import">{error}</Alert>}
            <input ref={file} type="file" accept=".json,application/json" aria-label="Chat file" className="sl-input" style={{ padding: 8 }} onChange={() => { setReport(null); setError(null); }} />
            <div className="sl-btn-row">
                <Button size="sm" disabled={busy} onClick={() => run(true)}>Preview</Button>
                <Button size="sm" variant="primary" disabled={busy} onClick={() => run(false)}>{busy ? 'Working...' : 'Import'}</Button>
            </div>
            {report && (
                <Alert tone={report.dry_run ? 'info' : 'success'} title={report.dry_run ? 'Preview: nothing was changed' : 'Done'}>
                    {` ${report.dry_run ? 'Would import' : 'Imported'} ${report.imported} chat${report.imported === 1 ? '' : 's'} (${report.messages} messages, ${report.files} files).`}
                    {report.duplicates ? ` ${report.duplicates} already here, left alone.` : ''}
                    {report.missing_files ? ` ${report.missing_files} attached file(s) were missing in the file.` : ''}
                    {` Chatbot: ${report.agent}.`}
                    {missing.length > 0 && ` Skipped because these people do not exist here: ${missing.map(([u, n]) => `${u} (${n})`).join(', ')}. Create them in People, then import again.`}
                </Alert>
            )}
        </Card>
    );
}

function KeyCard({ toast }) {
    const file = useRef(null);
    const [pass, setPass] = useState('');
    const [replace, setReplace] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [done, setDone] = useState(null);

    const submit = async () => {
        const f = file.current?.files?.[0];
        if (!f) return setError('Choose the key file first (general-ai-assistant.key.json).');
        if (!pass) return setError('Type the passphrase you chose when you made the key file.');
        setBusy(true);
        setError(null);
        try {
            const fd = new FormData();
            fd.append('file', f);
            fd.append('passphrase', pass);
            fd.append('replace', replace ? '1' : '0');
            setDone(await upload('/settings/transfer/key', fd));
            setPass('');
            toast('Key saved');
        } catch (e) { setError(errorText(e)); setDone(null); }
        setBusy(false);
    };

    return (
        <Card title="2. Access key">
            <p className="sl-help">Choose <code>general-ai-assistant.key.json</code> and type its passphrase. The passphrase is used once and is never saved. Import the chats first: that creates the chatbot the key belongs to.</p>
            {error && <Alert tone="danger" title="Could not save the key">{error}</Alert>}
            <input ref={file} type="file" accept=".json,application/json" aria-label="Key file" className="sl-input" style={{ padding: 8 }} onChange={() => { setDone(null); setError(null); }} />
            <TextField label="Passphrase" type="password" autoComplete="off" value={pass} onChange={(e) => setPass(e.target.value)} />
            <Switch label="Replace the key it has, if it has a different one" checked={replace} onChange={(e) => setReplace(e.target.checked)} />
            <div className="sl-btn-row">
                <Button size="sm" variant="primary" disabled={busy} onClick={submit}>{busy ? 'Working...' : 'Save the key'}</Button>
            </div>
            {done && (
                <Alert tone="success" title={done.result === 'same' ? 'Nothing changed' : 'Key saved'}>
                    {` ${done.agent} ${done.result === 'same' ? 'already has this key' : 'now has the key'}, ending in ${done.fingerprint}. It should match the other device. Open Agents and press Test connection.`}
                </Alert>
            )}
        </Card>
    );
}
