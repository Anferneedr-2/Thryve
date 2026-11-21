import { createContext, useCallback, useContext, useRef, useState } from 'react';
import Icon from '../Icon';

const ALERT_ICON = { success: 'check', warning: 'alert', danger: 'alert', info: 'info' };

export function Alert({ tone = 'info', title, children }) {
    return (
        <div className={`sl-alert ${tone}`} role={tone === 'danger' ? 'alert' : 'status'}>
            <Icon name={ALERT_ICON[tone]} />
            <div>{title && <strong>{title}</strong>}<span>{children}</span></div>
        </div>
    );
}

// Limit / quota banner with an optional action. bad = red variant.
export function Banner({ bad, action, children }) {
    return (
        <div className={`sl-banner ${bad ? 'bad' : ''}`} role={bad ? 'alert' : 'status'}>
            <Icon name="alert" />
            <span>{children}</span>
            {action}
        </div>
    );
}

export function Pill({ tone = 'muted', children }) {
    return <span className={`sl-pill ${tone}`}>{children}</span>;
}

export function Spinner({ label = 'Loading' }) {
    return <span className="sl-spin" role="status" aria-label={label} />;
}

export function Skeleton({ lines = 3 }) {
    return (
        <div className="sl-skel" aria-busy="true" aria-label="Loading">
            {Array.from({ length: lines }, (_, i) => <i key={i} style={{ width: i === lines - 1 ? '60%' : '100%' }} />)}
        </div>
    );
}

export function EmptyState({ icon = 'file', title, children, action }) {
    return (
        <div className="sl-empty">
            <span className="sl-icon"><Icon name={icon} /></span>
            <strong>{title}</strong>
            {children && <span>{children}</span>}
            {action}
        </div>
    );
}

// Actionable error: say what failed and offer a retry.
export function ErrorState({ title = 'Something went wrong', children, onRetry }) {
    return (
        <div className="sl-empty" role="alert">
            <span className="sl-icon" style={{ color: 'var(--red-600)' }}><Icon name="alert" /></span>
            <strong>{title}</strong>
            {children && <span>{children}</span>}
            {onRetry && <button type="button" className="sl-btn soft sm" onClick={onRetry}><Icon name="refresh" />Try again</button>}
        </div>
    );
}

// Toasts: announced politely, auto-dismiss, never steal focus.
const ToastCtx = createContext(() => {});
export const useToast = () => useContext(ToastCtx);

export function ToastProvider({ children }) {
    const [items, setItems] = useState([]);
    const nextId = useRef(0);
    const push = useCallback((message, ms = 4000) => {
        const id = ++nextId.current;
        setItems((l) => [...l, { id, message }]);
        setTimeout(() => setItems((l) => l.filter((t) => t.id !== id)), ms);
    }, []);
    return (
        <ToastCtx.Provider value={push}>
            {children}
            <div aria-live="polite" style={{ position: 'fixed', left: 16, right: 16, bottom: 16, display: 'grid', justifyItems: 'center', gap: 8, zIndex: 50, pointerEvents: 'none' }}>
                {items.map((t) => <div key={t.id} className="sl-toast"><Icon name="check" />{t.message}</div>)}
            </div>
        </ToastCtx.Provider>
    );
}
