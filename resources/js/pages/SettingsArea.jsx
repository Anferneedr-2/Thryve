import { useRouter } from '../lib/router';
import SideNavLayout from '../components/shell/SideNavLayout';
import Settings from './Settings';
import { Health } from './System';
import Updates from './Updates';

// Super Admin: every settings group is its own page, listed in the left drawer. The bare /settings address is General.
export const SETTINGS_ITEMS = [
    { id: 'general', path: '/settings', label: 'General', description: 'Company name and default theme', icon: 'sliders', group: 'Company' },
    { id: 'branding', path: '/settings/branding', label: 'Logo and favicon', description: 'What people see in the rail and tab', icon: 'star', group: 'Company' },
    { id: 'work', path: '/settings/work', label: 'Work and analytics', description: 'Idle time and analysis delay', icon: 'clock', group: 'Rules' },
    { id: 'files', path: '/settings/files', label: 'Files', description: 'Upload count and size limits', icon: 'file', group: 'Rules' },
    { id: 'tokens', path: '/settings/tokens', label: 'Tokens', description: 'Daily allowances and budget', icon: 'chip', group: 'Rules' },
    { id: 'security', path: '/settings/security', label: 'Security', description: 'Sensitive data handling', icon: 'eye', group: 'Rules' },
    { id: 'email', path: '/settings/email', label: 'Email', description: 'Mail server for alerts', icon: 'mail', group: 'Rules' },
    { id: 'health', path: '/settings/health', label: 'Health', description: 'Live checks of the platform', icon: 'globe', group: 'System' },
    { id: 'updates', path: '/settings/updates', label: 'Updates and backups', description: 'Version, updates and rollback', icon: 'download', group: 'System' },
];

export default function SettingsArea() {
    const { path } = useRouter();
    const item = SETTINGS_ITEMS.find((i) => i.path === path) ?? SETTINGS_ITEMS[0];
    return (
        <SideNavLayout title="Settings" items={SETTINGS_ITEMS} current={item.id}>
            {item.id === 'health' ? <Health /> : item.id === 'updates' ? <Updates /> : <Settings section={item.id} />}
        </SideNavLayout>
    );
}
