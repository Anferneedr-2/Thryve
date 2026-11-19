import { useState } from 'react';
import Icon from '../components/Icon';
import { getTheme, toggleTheme } from '../lib/theme';

const ICONS = 'plus search clip up stop copy thumb-up thumb-down refresh edit share more chev-down mic file globe sparkle trash panel chat sliders download eye moon sun menu arrow check x info alert clock cap layers brief star users chip circle-arrow pin mail '.trim().split(' ');
const SWATCHES = [
    ['brand', 'var(--brand)'], ['brand-hover', 'var(--brand-hover)'], ['blue-500', 'var(--blue-500)'], ['blue-200', 'var(--blue-200)'],
    ['blue-100', 'var(--blue-100)'], ['blue-50', 'var(--blue-50)'], ['navy-900', 'var(--navy-900)'], ['slate-700', 'var(--slate-700)'],
    ['slate-500', 'var(--slate-500)'], ['slate-450', 'var(--slate-450)'], ['slate-400', 'var(--slate-400)'], ['slate-200', 'var(--slate-200)'],
    ['slate-100', 'var(--slate-100)'], ['slate-50', 'var(--slate-50)'], ['surface', 'var(--surface)'],
    ['green-700', 'var(--green-700)'], ['green-50', 'var(--green-50)'], ['amber-700', 'var(--amber-700)'], ['amber-50', 'var(--amber-50)'],
    ['red-600', 'var(--red-600)'], ['red-50', 'var(--red-50)'],
];
const RADII = ['r-sm', 'r-md', 'r-card', 'r-panel', 'r-pill'];
const SHADOWS = ['sh-sm', 'sh-md', 'sh-lg', 'sh-blue'];

export default function TokenGallery() {
    const [theme, setThemeState] = useState(getTheme());
    return (
        <main className="mx-auto max-w-5xl space-y-10 p-6">
            <header className="flex items-center gap-3">
                <h1 className="text-3xl">Design tokens</h1>
                <button
                    type="button"
                    aria-label="Switch theme"
                    onClick={() => setThemeState(toggleTheme())}
                    className="ml-auto grid size-[38px] place-items-center rounded-full border border-slate-200 bg-surface text-lg text-navy hover:border-blue-600 hover:text-blue-600"
                >
                    <Icon name={theme === 'dark' ? 'sun' : 'moon'} />
                </button>
            </header>

            <section aria-labelledby="g-color">
                <h2 id="g-color" className="mb-3 text-xl">Colour</h2>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                    {SWATCHES.map(([n, v]) => (
                        <div key={n} className="overflow-hidden rounded-md border border-slate-200 bg-surface">
                            <div className="h-12" style={{ background: v }} />
                            <div className="p-2 font-mono text-xs text-slate-500">{n}</div>
                        </div>
                    ))}
                </div>
            </section>

            <section aria-labelledby="g-type">
                <h2 id="g-type" className="mb-3 text-xl">Typography</h2>
                <div className="space-y-2 rounded-card border border-slate-200 bg-surface p-6">
                    <p className="font-display text-4xl font-extrabold text-navy">DM Sans display 800</p>
                    <p className="text-base">Inter body, 14px minimum. Reports are due 2026-11-24, 1,250 tokens.</p>
                    <p className="tnum text-base">Tabular numerals: 1,111.00 / 8,888.00</p>
                    <p className="font-mono text-sm">JetBrains Mono: const x = 42;</p>
                </div>
            </section>

            <section aria-labelledby="g-shape">
                <h2 id="g-shape" className="mb-3 text-xl">Radius and elevation</h2>
                <div className="flex flex-wrap gap-4">
                    {RADII.map((r) => (
                        <div key={r} className="grid h-20 w-28 place-items-center border border-slate-200 bg-surface font-mono text-xs" style={{ borderRadius: `var(--${r})` }}>{r}</div>
                    ))}
                    {SHADOWS.map((s) => (
                        <div key={s} className="grid h-20 w-28 place-items-center rounded-card bg-surface font-mono text-xs" style={{ boxShadow: `var(--${s})` }}>{s}</div>
                    ))}
                </div>
            </section>

            <section aria-labelledby="g-icons">
                <h2 id="g-icons" className="mb-3 text-xl">Icons ({ICONS.length})</h2>
                <div className="grid grid-cols-4 gap-3 sm:grid-cols-6 lg:grid-cols-8">
                    {ICONS.map((n) => (
                        <div key={n} className="flex flex-col items-center gap-2 rounded-md border border-slate-200 bg-surface p-3 text-2xl text-blue-600">
                            <Icon name={n} />
                            <span className="font-mono text-[11px] text-slate-500">{n}</span>
                        </div>
                    ))}
                </div>
            </section>
        </main>
    );
}
