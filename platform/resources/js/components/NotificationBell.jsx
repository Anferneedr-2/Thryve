import { useEffect, useRef, useState } from 'react';
import { api, send } from '../lib/api';
import { useAttendanceStatus } from '../lib/heartbeat';
import { formatDateTime } from '../lib/util';
import Icon from './Icon';
import { Button } from './ui/Button';
import { EmptyState, ErrorState, Skeleton } from './ui/Feedback';

function ago(iso) {
    const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return 'just now';
    if (s < 3600) return `${Math.floor(s / 60)} min ago`;
    if (s < 86400) return `${Math.floor(s / 3600)} h ago`;
    return formatDateTime(iso);
}

// Bell with an unread badge and a list. The count arrives with every attendance heartbeat, so it stays fresh without extra requests.
export default function NotificationBell() {
    const { unread: fromServer } = useAttendanceStatus();
    const [unread, setUnread] = useState(fromServer);
    const [open, setOpen] = useState(false);
    const [items, setItems] = useState(null);
    const [error, setError] = useState(null);
    const [pos, setPos] = useState({ top: 0, right: 0 });
    const wrap = useRef(null);
    const trigger = useRef(null);

    useEffect(() => setUnread(fromServer), [fromServer]);

    const load = () => {
        setError(null);
        api('/notifications?per_page=15').then((r) => { setItems(r.data); setUnread(r.meta.unread); }).catch(setError);
    };

    const toggle = () => {
        if (!open) {
            const r = trigger.current.getBoundingClientRect();
            setPos({ top: r.bottom + 6, right: Math.max(8, window.innerWidth - r.right) });
            load();
        }
        setOpen(!open);
    };

    useEffect(() => {
        if (!open) return;
        const away = (e) => !wrap.current.contains(e.target) && setOpen(false);
        const key = (e) => { if (e.key === 'Escape') { setOpen(false); trigger.current.focus(); } };
        const close = () => setOpen(false);
        document.addEventListener('mousedown', away);
        document.addEventListener('keydown', key);
        window.addEventListener('resize', close);
        return () => { document.removeEventListener('mousedown', away); document.removeEventListener('keydown', key); window.removeEventListener('resize', close); };
    }, [open]);

    const markRead = async (n) => {
        if (n.read) return;
        setItems((l) => l.map((x) => (x.id === n.id ? { ...x, read: true } : x)));
        try { const d = await send('POST', `/notifications/${n.id}/read`); setUnread(d.unread); } catch { load(); }
    };
    const markAll = async () => {
        setItems((l) => l.map((x) => ({ ...x, read: true })));
        try { const d = await send('POST', '/notifications/read-all'); setUnread(d.unread); } catch { load(); }
    };

    const label = unread > 0 ? `Notifications, ${unread} unread` : 'Notifications';
    return (
        <div ref={wrap} style={{ position: 'relative', display: 'inline-block' }}>
            <button ref={trigger} type="button" className="sl-iconbtn round" aria-label={label} title="Notifications" aria-haspopup="dialog" aria-expanded={open} onClick={toggle} style={{ position: 'relative' }}>
                <Icon name="bell" />
                {unread > 0 && (
                    <span aria-hidden="true" className="tnum" style={{ position: 'absolute', top: -2, right: -2, minWidth: 18, height: 18, padding: '0 5px', borderRadius: 99, background: 'var(--brand)', color: '#fff', font: '700 11px/18px var(--font-body)', textAlign: 'center' }}>
                        {unread > 99 ? '99+' : unread}
                    </span>
                )}
            </button>
            {open && (
                <div role="dialog" aria-label="Notifications" className="sl-menu" style={{ position: 'fixed', top: pos.top, right: pos.right, zIndex: 40, width: 'min(360px, calc(100vw - 16px))', maxHeight: 'min(480px, 70vh)', overflowY: 'auto', padding: 0 }}>
                    <div className="flex items-center gap-2 px-3 py-2" style={{ borderBottom: '1px solid var(--slate-200)' }}>
                        <strong className="font-display text-base" style={{ color: 'var(--sl-heading)' }}>Notifications</strong>
                        <Button variant="ghost" size="sm" className="ml-auto" disabled={unread === 0} onClick={markAll}>Mark all as read</Button>
                    </div>
                    {error ? <ErrorState onRetry={load}>{error.message}</ErrorState> : !items ? <div className="p-3"><Skeleton lines={3} /></div> : items.length === 0 ? (
                        <EmptyState icon="bell" title="You are all caught up">New alerts will appear here.</EmptyState>
                    ) : (
                        <ul style={{ listStyle: 'none', margin: 0, padding: 6, display: 'grid', gap: 2 }}>
                            {items.map((n) => (
                                <li key={n.id}>
                                    <button type="button" className="it" onClick={() => markRead(n)} style={{ display: 'flex', gap: 10, alignItems: 'flex-start', width: '100%', padding: '9px 12px', borderRadius: 10, border: 0, background: n.read ? 'transparent' : 'var(--blue-50)', textAlign: 'left', cursor: 'pointer', font: 'inherit', color: 'var(--slate-700)' }}>
                                        <span aria-hidden="true" style={{ marginTop: 6, width: 8, height: 8, borderRadius: 99, flex: 'none', background: n.read ? 'transparent' : 'var(--brand)' }} />
                                        <span style={{ display: 'grid', gap: 2, minWidth: 0 }}>
                                            <span style={{ fontWeight: n.read ? 500 : 700, color: 'var(--sl-heading)' }}>{n.title}<span className="sr-only">{n.read ? '' : ' (unread)'}</span></span>
                                            {n.body && <span className="sl-help" style={{ overflowWrap: 'anywhere' }}>{n.body}</span>}
                                            <span className="sl-help tnum">{ago(n.created_at)}</span>
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}
