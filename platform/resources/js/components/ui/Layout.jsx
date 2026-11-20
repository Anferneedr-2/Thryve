import Icon from '../Icon';

export function Card({ title, icon, lift, children, className = '' }) {
    return (
        <section className={`sl-card ${lift ? 'hover' : ''} ${className}`}>
            {icon && <span className="sl-icon fill"><Icon name={icon} /></span>}
            {title && <h5>{title}</h5>}
            {children}
        </section>
    );
}

export function Stat({ value, label }) {
    return <div className="sl-stat"><b className="tnum">{value}</b><span>{label}</span></div>;
}

// Wide tables scroll inside their own wrapper; the page never scrolls sideways.
export function Table({ columns, rows, caption }) {
    return (
        <div className="sl-tablewrap" tabIndex={0} role="region" aria-label={caption}>
            <table className="sl-table">
                <thead><tr>{columns.map((c) => <th key={c.key} scope="col">{c.label}</th>)}</tr></thead>
                <tbody>{rows.map((r, i) => <tr key={i}>{columns.map((c) => <td key={c.key} className={c.num ? 'tnum' : undefined}>{r[c.key]}</td>)}</tr>)}</tbody>
            </table>
        </div>
    );
}

export function Tabs({ tabs, value, onChange }) {
    const move = (e, i) => {
        const n = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : null;
        if (n === null) return;
        const t = tabs[(n + tabs.length) % tabs.length];
        onChange(t.id);
        document.getElementById(`tab-${t.id}`)?.focus();
    };
    return (
        <div className="sl-tabs" role="tablist">
            {tabs.map((t, i) => (
                <button key={t.id} id={`tab-${t.id}`} role="tab" aria-selected={value === t.id} tabIndex={value === t.id ? 0 : -1}
                    className={value === t.id ? 'on' : ''} onClick={() => onChange(t.id)} onKeyDown={(e) => move(e, i)}>{t.label}</button>
            ))}
        </div>
    );
}
