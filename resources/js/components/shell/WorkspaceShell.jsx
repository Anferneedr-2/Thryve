import { useEffect, useRef, useState } from 'react';
import { send } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { useRouter } from '../../lib/router';
import { LogoImg, useBranding } from '../../lib/branding';
import { getTheme, toggleTheme } from '../../lib/theme';
import { ROLE_LABEL } from '../../lib/roles';
import { AttendanceHeartbeat } from '../../lib/heartbeat';
import Icon from '../Icon';
import NotificationBell from '../NotificationBell';
import { Button } from '../ui/Button';
import { Banner } from '../ui/Feedback';
import { Menu } from '../ui/Overlay';
import { isActive, railItems } from './nav';

const initials = (name = '') => name.trim().split(/\s+/).slice(0, 2).map((p) => p[0]?.toUpperCase() ?? '').join('') || '?';

// The company mark at the top of the rail: the uploaded logo when there is one, else the company's first letter.
function BrandMark({ onClick }) {
    const { company_name, has_logo } = useBranding();
    return (
        <a href="/chat" className="ws-mark" aria-label={`${company_name}: go to chat`} onClick={onClick}>
            {has_logo
                ? <LogoImg height={30} />
                : <span aria-hidden="true">{company_name.trim()[0]?.toUpperCase() ?? 'T'}</span>}
        </a>
    );
}

// Account, theme and sign out. Used by the rail (avatar) and, on phones, by the "More" tab.
function useAccountItems() {
    const { user, logout } = useAuth();
    const { navigate } = useRouter();
    const [theme, setTheme] = useState(getTheme());
    return [
        { label: user.name, icon: 'users', onSelect: () => navigate('/account') },
        '-',
        { label: theme === 'dark' ? 'Light theme' : 'Dark theme', icon: theme === 'dark' ? 'sun' : 'moon', onSelect: () => setTheme(toggleTheme()) },
        '-',
        { label: 'Sign out', icon: 'arrow', onSelect: logout },
    ];
}

function Rail({ items, path, go }) {
    const { user } = useAuth();
    const account = useAccountItems();
    return (
        <nav className="ws-rail" aria-label="Main">
            <BrandMark onClick={(e) => { e.preventDefault(); go('/chat'); }} />
            <div className="ws-scroll">
                {items.map((it) => (
                    <a key={it.id} href={it.id} className="ws-it" title={it.title ?? it.label} aria-current={isActive(path, it.id) ? 'page' : undefined} onClick={(e) => { e.preventDefault(); go(it.id); }}>
                        <Icon name={it.icon} /><span>{it.label}</span>
                    </a>
                ))}
            </div>
            <NotificationBell placement="right-end" triggerClass="ws-it ws-btn" caption="Alerts" />
            <Menu label={`Account menu for ${user.name}`} placement="right-end" triggerClass="ws-avatar" triggerContent={<span aria-hidden="true">{initials(user.name)}</span>} items={account} />
        </nav>
    );
}

// Phones: the rail becomes a bottom tab bar. Chat, Time and Alerts stay one tap away; everything else is under More.
function TabBar({ items, path, go }) {
    const { user } = useAuth();
    const account = useAccountItems();
    const main = items.filter((i) => i.id === '/chat' || i.id === '/attendance');
    const rest = items.filter((i) => !main.includes(i)).map((i) => ({ label: i.title ?? i.label, icon: i.icon, onSelect: () => go(i.id) }));
    return (
        <nav className="ws-tabs" aria-label="Main (phone)">
            {main.map((it) => (
                <a key={it.id} href={it.id} className="ws-it" aria-current={isActive(path, it.id) ? 'page' : undefined} onClick={(e) => { e.preventDefault(); go(it.id); }}>
                    <Icon name={it.icon} /><span>{it.id === '/attendance' ? 'Time' : it.label}</span>
                </a>
            ))}
            <NotificationBell placement="above-end" triggerClass="ws-it ws-btn" caption="Alerts" />
            <Menu label={`More, and account menu for ${user.name}`} placement="above-end" triggerClass="ws-it ws-btn"
                triggerContent={<><Icon name="menu" /><span>More</span></>}
                items={[...rest, ...(rest.length ? ['-'] : []), ...account]} />
        </nav>
    );
}

// Shown on every screen while a Super Admin is viewing as someone. One click returns to the Super Admin's own account.
function ViewingAsBanner({ name }) {
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    const back = async () => {
        setBusy(true); setFailed(false);
        try { await send('POST', '/auth/impersonation/stop'); window.location.assign('/people'); } catch (e) { setFailed(true); setBusy(false); }
    };
    return (
        <Banner action={<Button size="sm" variant="primary" disabled={busy} onClick={back}>{busy ? 'Returning...' : 'Return to my account'}</Button>}>
            Viewing as <strong>{name}</strong>. Your actions are saved to their account and logged under your name.{failed && ' Could not return. Try again.'}
        </Banner>
    );
}

export default function WorkspaceShell({ children }) {
    const { user } = useAuth();
    const { path, navigate } = useRouter();
    const main = useRef(null);
    const items = railItems(user);
    // Chat manages its own scrolling and panels; every other page scrolls inside the padded page body.
    const flush = path === '/chat' || path.startsWith('/chat/') || path === '/settings' || path.startsWith('/settings/');

    // The page no longer scrolls (the main area does), so reset its scroll when the screen changes.
    useEffect(() => { main.current?.scrollTo(0, 0); }, [path]);

    return (
        <AttendanceHeartbeat>
            <div className={user.viewing_as_by ? 'ws-frame' : undefined}>
                {user.viewing_as_by && <ViewingAsBanner name={user.name} />}
                <div className="ws">
                    <a className="ws-skip" href="#ws-main" onClick={(e) => { e.preventDefault(); main.current?.focus(); }}>Skip to content</a>
                    <Rail items={items} path={path} go={navigate} />
                    <main id="ws-main" ref={main} tabIndex={-1} className={`ws-main ${flush ? 'flush' : ''}`} aria-label={ROLE_LABEL[user.role]}>
                        {children}
                    </main>
                    <TabBar items={items} path={path} go={navigate} />
                </div>
            </div>
        </AttendanceHeartbeat>
    );
}
