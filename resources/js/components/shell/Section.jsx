import { useRouter } from '../../lib/router';
import { PageBody, PageHeader } from './Page';

// One rail item = one Section: a page header with the title and tabs, then the body of the open tab. Each tab has its
// own URL so back, forward, reload and old bookmarks all land on the right tab. `tabs` is [{id, path, label, node}];
// a Section with a single tab shows no tab strip.
export default function Section({ title, tabs, wide = false, preserveSearch = false }) {
    const { path, navigate } = useRouter();
    const current = tabs.find((t) => t.path === path) ?? tabs[0];
    const tabSearch = () => {
        if (!preserveSearch) return '';
        const source = new URLSearchParams(window.location.search); const shared = new URLSearchParams();
        ['days', 'from', 'to', 'user_id', 'department_id'].forEach((key) => { if (source.has(key)) shared.set(key, source.get(key)); });
        return shared.size ? `?${shared}` : '';
    };
    return (
        <>
            <PageHeader
                title={title}
                tabs={tabs.length > 1 ? tabs.map(({ id, label }) => ({ id, label })) : undefined}
                tab={current.id}
                onTab={(id) => navigate(tabs.find((t) => t.id === id).path + tabSearch())}
            />
            <PageBody wide={wide}>{current.node}</PageBody>
        </>
    );
}
