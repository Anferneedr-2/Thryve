import { createContext, useContext, useEffect, useRef, useState } from 'react';
import { api } from './api';

// Reports "I am here" to the server so attendance can tell active time from idle time.
// The server decides what counts; this only sends the age of the last real interaction.
const StatusCtx = createContext({ state: 'offline', today: null, unread: 0 });
export const useAttendanceStatus = () => useContext(StatusCtx);

const ACTIVITY_EVENTS = ['pointerdown', 'pointermove', 'keydown', 'wheel', 'scroll', 'touchstart'];

export function AttendanceHeartbeat({ children }) {
    const [status, setStatus] = useState({ state: 'offline', today: null, unread: 0 });
    const lastActivity = useRef(Date.now());

    useEffect(() => {
        let timer;
        let stopped = false;
        const touch = () => { lastActivity.current = Date.now(); };
        ACTIVITY_EVENTS.forEach((e) => window.addEventListener(e, touch, { passive: true, capture: true }));
        const onVisible = () => { if (document.visibilityState === 'visible') touch(); };
        document.addEventListener('visibilitychange', onVisible);

        const beat = async () => {
            let next = 30_000;
            try {
                const age = Math.min(604800, Math.max(0, Math.round((Date.now() - lastActivity.current) / 1000)));
                const { data } = await api('/attendance/heartbeat', { method: 'POST', body: { activity_age_seconds: age } });
                if (!stopped) setStatus({ state: data.state, today: data.today, unread: data.unread_notifications ?? 0 });
                next = (data.interval_seconds || 30) * 1000;
            } catch { /* offline or session lost: the next beat tries again; session loss is handled by the auth layer */ }
            if (!stopped) timer = setTimeout(beat, next);
        };
        beat();

        // Best effort when the tab or browser closes; the scheduled sweep covers a message that never arrives.
        const onPageHide = (e) => {
            if (e.persisted) return;
            const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
            try {
                fetch('/api/attendance/close', { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(m ? { 'X-XSRF-TOKEN': decodeURIComponent(m[1]) } : {}) }, body: '{}' });
            } catch { /* nothing more to do */ }
        };
        window.addEventListener('pagehide', onPageHide);

        return () => {
            stopped = true;
            clearTimeout(timer);
            ACTIVITY_EVENTS.forEach((e) => window.removeEventListener(e, touch, { capture: true }));
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener('pagehide', onPageHide);
        };
    }, []);

    return <StatusCtx.Provider value={status}>{children}</StatusCtx.Provider>;
}
