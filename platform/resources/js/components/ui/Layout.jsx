import { useState } from 'react';
import Icon from '../Icon';
import { getTheme, toggleTheme } from '../../lib/theme';
import { IconButton } from './Button';

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

// Top navigation: below 1024px the links collapse into a menu button. The reference says 640px, but an admin's links
// (up to eight for a Super Admin) overflow the bar well past that, so the collapse point is higher (recorded in the plan).
export function AppNav({ brand = 'Thryve', links, current, onNavigate, right }) {
    const [open, setOpen] = useState(false);
    const [theme, setThemeState] = useState(getTheme());
    const go = (e, id, close) => {
        if (close) setOpen(false);
        if (onNavigate) { e.preventDefault(); onNavigate(id); }
    };
    return (
        <header className="mx-auto w-full max-w-[1200px] px-6 pt-4">
            <nav className="sl-nav" aria-label="Main">
                <span className="logo">{brand}</span>
                <div className="hidden items-center gap-1.5 lg:flex">
                    {links.map((l) => (
                        <a key={l.id} href={l.href} className={`l ${current === l.id ? 'on' : ''}`} aria-current={current === l.id ? 'page' : undefined} onClick={(e) => go(e, l.id)}>{l.label}</a>
                    ))}
                </div>
                {right}
                <IconButton icon={theme === 'dark' ? 'sun' : 'moon'} label="Switch theme" round onClick={() => setThemeState(toggleTheme())} />
                <IconButton icon={open ? 'x' : 'menu'} label="Menu" className="lg:!hidden" aria-expanded={open} onClick={() => setOpen(!open)} />
            </nav>
            {open && (
                <div className="sl-menu mt-2 lg:hidden" role="group" aria-label="Pages">
                    {links.map((l) => (
                        <a key={l.id} href={l.href} className="it" aria-current={current === l.id ? 'page' : undefined} onClick={(e) => go(e, l.id, true)}>{l.label}</a>
                    ))}
                </div>
            )}
        </header>
    );
}

// Page container: 24px gutter on phones, max 1200px from 1024px (reference section 26).
export function Page({ children }) {
    return <main className="mx-auto w-full max-w-[1200px] space-y-8 px-6 py-8">{children}</main>;
}
