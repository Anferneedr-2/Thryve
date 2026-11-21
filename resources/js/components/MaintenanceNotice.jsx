import { useEffect, useState } from 'react';
import Icon from './Icon';
import BrandLogo from './BrandLogo';

// Shown over the whole app while an update or restore is running. It checks every 10 seconds and reloads the page once
// the server answers normally again, because the update may have replaced the page's own scripts.
export default function MaintenanceNotice() {
    const [message, setMessage] = useState(null);

    useEffect(() => {
        const on = (e) => setMessage(e.detail?.message || 'The platform is being updated. Please try again in a few minutes.');
        window.addEventListener('api:maintenance', on);
        return () => window.removeEventListener('api:maintenance', on);
    }, []);

    useEffect(() => {
        if (!message) return undefined;
        const t = setInterval(async () => {
            try {
                const res = await fetch('/api/branding', { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
                if (res.status !== 503) window.location.reload();
            } catch { /* still down: keep waiting */ }
        }, 10000);
        return () => clearInterval(t);
    }, [message]);

    if (!message) return null;
    return (
        <div className="maint" role="alertdialog" aria-modal="true" aria-labelledby="maint-title" aria-describedby="maint-text">
            <div className="maint-card">
                <BrandLogo size={40} />
                <span className="maint-ic" aria-hidden="true"><Icon name="clock" /></span>
                <h1 id="maint-title">We are updating</h1>
                <p id="maint-text">{message}</p>
                <p className="sl-help">This page checks by itself and comes back when the update is done. Your work is safe.</p>
            </div>
        </div>
    );
}
