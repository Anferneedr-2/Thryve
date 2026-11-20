import { isAdmin, isSuperAdmin } from '../../lib/roles';

// Rail items by role. Navigation depends on role for convenience only; the server enforces every permission.
// TRANSITIONAL: until P05A.02 merges related pages into tabs, every existing page keeps its own rail item so nothing
// becomes unreachable. P05A.02 reduces this list to Chat, Attendance, People, Agents and Settings.
export function railItems(user) {
    const items = [
        { id: '/chat', label: 'Chat', icon: 'chat' },
        { id: '/attendance', label: 'Attendance', icon: 'clock' },
    ];
    if (isAdmin(user)) {
        items.push(
            { id: '/team-attendance', label: 'Team', icon: 'layers' },
            { id: '/people', label: 'People', icon: 'users' },
            { id: '/departments', label: 'Depts', icon: 'brief', title: 'Departments' },
            { id: '/agents', label: 'Agents', icon: 'chip', title: 'AI agents' },
        );
    }
    if (isSuperAdmin(user)) {
        items.push(
            { id: '/settings', label: 'Settings', icon: 'sliders' },
            { id: '/system', label: 'System', icon: 'globe' },
        );
    }

    return items;
}

export const isActive = (path, id) => path === id || path.startsWith(`${id}/`);
