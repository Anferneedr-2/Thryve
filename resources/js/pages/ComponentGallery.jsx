import { useState } from 'react';
import { Button, IconButton } from '../components/ui/Button';
import { Checkbox, Radio, SelectField, Switch, TextField, TextareaField } from '../components/ui/Form';
import { Alert, Banner, EmptyState, ErrorState, Pill, Skeleton, Spinner, ToastProvider, useToast } from '../components/ui/Feedback';
import { ConfirmDialog, Dialog, Menu } from '../components/ui/Overlay';
import { Card, Stat, Table, Tabs } from '../components/ui/Layout';
import { PageBody, PageHeader } from '../components/shell/Page';
import { Markdown } from '../lib/markdown';

// Synthetic data only. This page exists to verify the primitives (P01.04) and is removed before launch.
const COLUMNS = [
    { key: 'name', label: 'Employee' },
    { key: 'dept', label: 'Department' },
    { key: 'date', label: 'Date' },
    { key: 'hours', label: 'Active hours', num: true },
    { key: 'tokens', label: 'Tokens used', num: true },
    { key: 'status', label: 'Status' },
];
const ROWS = [
    { name: 'Sample Employee A', dept: 'Operations', date: '2026-11-19', hours: '7.50', tokens: '12,480', status: <Pill tone="success">Active</Pill> },
    { name: 'Sample Employee B', dept: 'Sales', date: '2026-11-19', hours: '6.25', tokens: '48,902', status: <Pill tone="warning">Near limit</Pill> },
    { name: 'Sample Employee C', dept: 'Finance', date: '2026-11-19', hours: '0.00', tokens: '0', status: <Pill tone="muted">Idle</Pill> },
];

function Section({ title, children }) {
    return (
        <section aria-label={title} className="space-y-4">
            <h2 className="text-2xl">{title}</h2>
            {children}
        </section>
    );
}

const SAMPLE = [
    '### Sample answer',
    '- A list item',
    '- Another with `inline code`',
    '',
    '```js',
    'const total = 1 + 2;',
    '```',
].join('\n');

function Gallery() {
    const toast = useToast();
    const [tab, setTab] = useState('a');
    const [dialog, setDialog] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const [page, setPage] = useState('components');
    const [email, setEmail] = useState('');
    const bad = email.length > 0 && !email.includes('@');

    return (
        <>
            <PageHeader title="Component gallery" tabs={[{ id: 'components', label: 'Components' }, { id: 'reports', label: 'Reports' }, { id: 'settings', label: 'Settings' }]} tab={page} onTab={setPage} actions={<Button variant="primary" size="sm" icon="plus">Add item</Button>} />
            <PageBody>
                <Section title="Buttons">
                    <div className="sl-btn-row">
                        <Button variant="primary">Save changes</Button>
                        <Button>Outline</Button>
                        <Button variant="soft">Soft</Button>
                        <Button variant="dark">Dark</Button>
                        <Button variant="ghost">Ghost</Button>
                        <Button variant="danger">Delete</Button>
                        <Button variant="primary" size="sm">Small</Button>
                        <Button variant="primary" disabled>Disabled</Button>
                        <IconButton icon="copy" label="Copy" />
                        <IconButton icon="thumb-up" label="Good response" on />
                    </div>
                </Section>

                <Section title="Form fields">
                    <div className="grid items-start gap-4 sm:grid-cols-2">
                        <TextField label="Username" placeholder="firstname.lastname" help="Created by an administrator." />
                        <TextField label="Email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} error={bad ? 'Enter a valid email address.' : undefined} ok={email.includes('@') ? 'Looks good.' : undefined} placeholder="name@company.com" />
                        <SelectField label="Department" defaultValue="ops"><option value="ops">Operations</option><option value="sales">Sales</option></SelectField>
                        <TextField label="Disabled" disabled defaultValue="Read only" />
                        <div className="sm:col-span-2"><TextareaField label="Notes" placeholder="Optional" /></div>
                        <Checkbox label="Remember me" hint="On this device only" defaultChecked />
                        <Radio label="Daily digest" name="r" defaultChecked />
                        <Switch label="Email alerts" defaultChecked />
                    </div>
                </Section>

                <Section title="Alerts, banners, badges">
                    <div className="grid gap-3">
                        <Alert tone="success" title="Saved">Your changes were saved.</Alert>
                        <Alert tone="info" title="Heads up">Attendance resets at midnight (Philippine time).</Alert>
                        <Alert tone="warning" title="Almost out">You have used 90% of today's tokens.</Alert>
                        <Alert tone="danger" title="Could not save">Check your connection and try again.</Alert>
                        <Banner action={<Button size="sm" variant="soft">View usage</Button>}>You have used 80% of your daily allowance.</Banner>
                        <Banner bad>Daily allowance reached. It resets at midnight.</Banner>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Pill tone="info">Info</Pill><Pill tone="success">Success</Pill><Pill tone="warning">Warning</Pill><Pill tone="danger">Danger</Pill><Pill>Muted</Pill>
                    </div>
                </Section>

                <Section title="Cards and stats">
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Card title="Attendance" icon="clock" lift><p>Active time excludes idle periods.</p></Card>
                        <Card title="Usage" icon="chip"><Stat value="48,902" label="Tokens today" /></Card>
                        <Card title="People" icon="users"><Stat value="42" label="Active employees" /></Card>
                    </div>
                </Section>

                <Section title="Table">
                    <Table caption="Sample attendance table" columns={COLUMNS} rows={ROWS} />
                </Section>

                <Section title="Tabs, menu, dialogs, toast">
                    <Tabs tabs={[{ id: 'a', label: 'Overview' }, { id: 'b', label: 'Attendance' }, { id: 'c', label: 'Usage' }]} value={tab} onChange={setTab} />
                    <p>Selected tab: <strong>{tab}</strong></p>
                    <div className="sl-btn-row">
                        <Menu label="More actions" items={[{ label: 'Rename', icon: 'edit' }, { label: 'Download', icon: 'download' }, '-', { label: 'Delete', icon: 'trash', danger: true, onSelect: () => setConfirm(true) }]} />
                        <Button onClick={() => setDialog(true)}>Open dialog</Button>
                        <Button variant="soft" onClick={() => toast('Settings saved')}>Show toast</Button>
                    </div>
                    <Dialog open={dialog} onClose={() => setDialog(false)} title="Change password" actions={<><Button onClick={() => setDialog(false)}>Cancel</Button><Button variant="primary" onClick={() => setDialog(false)}>Update</Button></>}>
                        <TextField label="New password" type="password" />
                    </Dialog>
                    <ConfirmDialog open={confirm} onCancel={() => setConfirm(false)} onConfirm={() => { setConfirm(false); toast('Deleted'); }} title="Delete this item?">
                        This cannot be undone.
                    </ConfirmDialog>
                </Section>

                <Section title="Loading, empty, error">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Card><div className="flex items-center gap-2"><Spinner />Loading...</div><Skeleton /></Card>
                        <Card><EmptyState title="No conversations yet" icon="chat">Start a chat to see it here.</EmptyState></Card>
                        <Card><ErrorState onRetry={() => toast('Retrying')}>We could not load this list.</ErrorState></Card>
                    </div>
                </Section>
                <Section title="Workspace shell (page frame and chat answer)">
                    <p>The header above is the standard page frame: title, tabs and actions, then a padded body. Chat answers render as plain text on the page; the sample below shows headings, a list and a code block.</p>
                    <div className="m ai"><div className="body"><Markdown text={SAMPLE} /></div></div>
                </Section>
            </PageBody>
        </>
    );
}

export default function ComponentGallery() {
    return <ToastProvider><Gallery /></ToastProvider>;
}
