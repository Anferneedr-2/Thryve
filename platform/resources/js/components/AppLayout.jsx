import { useAuth } from '../lib/auth';
import { useRouter } from '../lib/router';
import { AppNav, Page } from './ui/Layout';
import { Menu } from './ui/Overlay';
import { AttendanceHeartbeat } from '../lib/heartbeat';
import { Brand } from '../lib/branding';
import NotificationBell from './NotificationBell';

const ROLE_LABEL = { employee: 'Employee', admin: 'Admin', super_admin: 'Super Admin' };

// Navigation depends on role for convenience only; the server enforces every permission.
export function linksFor(user) {
    const links = [{ id: '/', label: 'Home', href: '/' }, { id: '/attendance', label: 'Attendance', href: '/attendance' }];
    if (user.role === 'admin' || user.role === 'super_admin') {
        links.push(
            { id: '/team-attendance', label: 'Team', href: '/team-attendance' },
            { id: '/people', label: 'People', href: '/people' },
            { id: '/departments', label: 'Departments', href: '/departments' },
        );
    }
    if (user.role === 'super_admin') {
        links.push({ id: '/settings', label: 'Settings', href: '/settings' }, { id: '/system', label: 'System', href: '/system' });
    }
    links.push({ id: '/account', label: 'Account', href: '/account' });
    return links;
}

export default function AppLayout({ children }) {
    const { user, logout } = useAuth();
    const { path, navigate } = useRouter();
    return (
        <AttendanceHeartbeat>
            <AppNav
                brand={<Brand />}
                links={linksFor(user)}
                current={path}
                onNavigate={navigate}
                right={<><NotificationBell /><Menu label={`Account menu for ${user.name}`} icon="users" items={[
                    { label: user.name, icon: 'users', onSelect: () => navigate('/account') },
                    '-',
                    { label: 'Sign out', icon: 'arrow', onSelect: logout },
                ]} /></>}
            />
            <Page>{children}</Page>
        </AttendanceHeartbeat>
    );
}

export { ROLE_LABEL };
