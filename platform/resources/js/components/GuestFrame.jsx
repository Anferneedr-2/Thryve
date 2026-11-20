import { useState } from 'react';
import { useBranding } from '../lib/branding';
import { getTheme, toggleTheme } from '../lib/theme';
import BrandLogo from './BrandLogo';
import Icon from './Icon';

// The frame every page a person sees before the app (sign-in, usage policy, installer) shares: the blue brand panel on the
// left, the page's own content on the right, a small logo above the content on phones, and a theme switch top right.
// The tagline and the three points are sample text until the owner supplies the real line (decision D16).
const POINTS = [
    ['chat', 'One place to chat with every assistant'],
    ['clock', 'Time and attendance, tracked for you'],
    ['lock', 'Private to your company'],
];

export default function GuestFrame({ children, width = 380, tagline = 'Your team’s AI workspace', points = POINTS, label }) {
    const { company_name } = useBranding();
    const [theme, setTheme] = useState(getTheme());
    return (
        <main className="lg-page">
            {/* The content comes first in the page so keyboard and screen-reader order is logo, heading, content, then the theme switch (placed top right by CSS). */}
            <section className="lg-form" aria-label={label}>
                <div className="lg-inner" style={{ width: `min(100%, ${width}px)` }}>
                    <BrandLogo />
                    {children}
                </div>
            </section>

            <aside className="lg-brand" aria-label={`About ${company_name}`}>
                <BrandLogo onBrand size={64} />
                <div>
                    <h2>{tagline}</h2>
                    <ul>
                        {points.map(([icon, text]) => <li key={text}><i><Icon name={icon} /></i>{text}</li>)}
                    </ul>
                </div>
                <small>&copy; {new Date().getFullYear()} {company_name}</small>
            </aside>

            <button type="button" className="lg-tgl" aria-label={theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'} onClick={() => setTheme(toggleTheme())}>
                <Icon name={theme === 'dark' ? 'sun' : 'moon'} />
            </button>
        </main>
    );
}
