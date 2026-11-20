import { useCallback, useState } from 'react';

// A panel that stays open or closed the way the person left it, per browser. Works when storage is blocked (private
// windows, blocked site data): the panel then simply starts from its default each visit.
const read = (key, fallback) => {
    try {
        const v = localStorage.getItem(`ws-panel:${key}`);
        return v === null ? fallback : v === '1';
    } catch { return fallback; }
};

export function usePanelState(key, defaultOpen) {
    const [open, setOpenState] = useState(() => read(key, defaultOpen));
    const setOpen = useCallback((next) => {
        setOpenState((cur) => {
            const value = typeof next === 'function' ? next(cur) : next;
            try { localStorage.setItem(`ws-panel:${key}`, value ? '1' : '0'); } catch { /* storage unavailable */ }
            return value;
        });
    }, [key]);
    const toggle = useCallback(() => setOpen((v) => !v), [setOpen]);
    return [open, setOpen, toggle];
}
