import { useRouter } from '../../lib/router';
import { PageBody, PageHeader } from './Page';

// One rail item = one Section: a page header with the title and tabs, then the body of the open tab. Each tab has its
// own URL so back, forward, reload and old bookmarks all land on the right tab. `tabs` is [{id, path, label, node}];
// a Section with a single tab shows no tab strip.
export default function Section({ title, tabs, wide = false }) {
    const { path, navigate } = useRouter();
    const current = tabs.find((t) => t.path === path) ?? tabs[0];
    return (
        <>
            <PageHeader
                title={title}
                tabs={tabs.length > 1 ? tabs.map(({ id, label }) => ({ id, label })) : undefined}
                tab={current.id}
                onTab={(id) => navigate(tabs.find((t) => t.id === id).path)}
            />
            <PageBody wide={wide}>{current.node}</PageBody>
        </>
    );
}
