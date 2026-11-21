import { Tabs } from '../ui/Layout';

// Standard header for every non-chat page (P05A.02 moves the pages onto it): title on the left, optional tabs under it,
// optional actions on the right. Sticks to the top of the scrolling main area.
export function PageHeader({ title, tabs, tab, onTab, actions, children }) {
    return (
        <header className="ws-head">
            <div className="ws-head-row">
                <h1>{title}</h1>
                {actions && <div className="ws-head-actions">{actions}</div>}
            </div>
            {children}
            {tabs && <Tabs tabs={tabs} value={tab} onChange={onTab} />}
        </header>
    );
}

// The padded, centered body under a PageHeader. `wide` lifts the 1,240 px limit for dense tables.
export function PageBody({ wide = false, children }) {
    return <div className={`ws-content ${wide ? 'wide' : ''}`}>{children}</div>;
}
