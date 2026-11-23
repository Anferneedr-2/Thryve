import { useId, useState } from 'react';
import { Table } from '../ui/Layout';

export const number = (n = 0) => new Intl.NumberFormat('en', { maximumFractionDigits: 1 }).format(n);
export const hours = (n = 0) => `${number(n / 3600)} h`;
export const shortDate = (day) => new Date(`${day}T12:00:00Z`).toLocaleDateString('en', { month: 'short', day: 'numeric', timeZone: 'Asia/Manila' });

export function TrendChart({ rows, metric = 'tokens', label = 'Tokens', secondary, secondaryLabel, formatter = number, nullable = false, ceiling }) {
    const id = useId();
    const [hover, setHover] = useState(null);
    const selected = Math.min(hover ?? rows.length - 1, rows.length - 1);
    const max = Math.max(1, ...rows.map((r) => Math.max(r[metric] ?? 0, secondary ? r[secondary] ?? 0 : 0)));
    // A fixed top (a score out of 5) keeps the chart honest; otherwise round the largest value up.
    const top = ceiling ?? Math.ceil(max / (10 ** Math.floor(Math.log10(max)))) * (10 ** Math.floor(Math.log10(max)));
    const x = (i) => 60 + (rows.length === 1 ? 288 : i * 576 / (rows.length - 1));
    const y = (v) => 180 - v / top * 152;
    const points = (key) => rows.map((r, i) => `${x(i)},${y(r[key] ?? 0)}`).join(' ');
    const path = (key) => { let connected = false; return rows.map((r, i) => { if (r[key] == null) { connected = false; return ''; } const command = connected ? 'L' : 'M'; connected = true; return `${command}${x(i)},${y(r[key])}`; }).join(' '); };
    const display = (v) => nullable && v == null ? 'Not recorded' : formatter(v ?? 0);
    const row = rows[selected];
    const move = (event) => {
        const rect = event.currentTarget.getBoundingClientRect();
        setHover(Math.max(0, Math.min(rows.length - 1, Math.round(((event.clientX - rect.left) / rect.width * 660 - 60) / 576 * (rows.length - 1)))));
    };
    return <>
        <div className="rp-chart-value" aria-live="polite"><span>{row ? shortDate(row.day) : 'No data'}</span><strong>{display(row?.[metric])}</strong>{secondary && <span>{secondaryLabel}: {display(row?.[secondary])}</span>}</div>
        <svg className="rp-trend" viewBox="0 0 660 220" role="slider" tabIndex={0} aria-label={`${label} by day. Use left and right arrows to explore.`}
            aria-valuemin={1} aria-valuemax={Math.max(rows.length, 1)} aria-valuenow={Math.max(1,selected + 1)} aria-valuetext={row ? `${row.day}: ${display(row[metric])} ${label}` : 'No data'}
            onPointerMove={move} onPointerLeave={() => setHover(null)} onKeyDown={(e) => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
                e.preventDefault(); setHover(e.key === 'Home' ? 0 : e.key === 'End' ? rows.length - 1 : Math.min(rows.length - 1, Math.max(0, selected + (e.key === 'ArrowLeft' ? -1 : 1))));
            }}>
            <title>{label} over the selected dates</title>
            {[0, .5, 1].map((v) => <g key={v}><line x1="60" x2="636" y1={y(top * v)} y2={y(top * v)} className="rp-gridline" /><text x="48" y={y(top * v) + 4} textAnchor="end">{formatter === number ? new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(top * v) : formatter(top * v)}</text></g>)}
            {!nullable && rows.length > 1 && <polygon points={`60,180 ${points(metric)} 636,180`} className="rp-area" />}
            {nullable ? <><path d={path(metric)} className="rp-line" />{secondary && <path d={path(secondary)} className="rp-line secondary" />}{rows.map((r,i)=>r[metric]!=null&&<circle key={i} cx={x(i)} cy={y(r[metric])} r="3" className="rp-dot" />)}</> : <><polyline points={points(metric)} className="rp-line" />{secondary && <polyline points={points(secondary)} className="rp-line secondary" />}</>}
            {row && <><line x1={x(selected)} x2={x(selected)} y1="28" y2="180" className="rp-crosshair" />{(!nullable || row[metric]!=null) && <circle cx={x(selected)} cy={y(row[metric] ?? 0)} r="5" className="rp-dot" />}</>}
            {[...new Set([0, Math.floor((rows.length - 1) / 2), rows.length - 1])].filter((i) => i >= 0).map((i) => <text key={i} x={x(i)} y="210" textAnchor={i === 0 ? 'start' : i === rows.length - 1 ? 'end' : 'middle'}>{shortDate(rows[i].day)}</text>)}
        </svg>
        <div className="rp-legend"><span><i className="rp-key" />{label}</span>{secondary && <span><i className="rp-key secondary" />{secondaryLabel}</span>}</div>
        <details className="rp-data"><summary id={id}>View daily data</summary><Table caption={`${label} daily data`} columns={[{ key: 'day', label: 'Date' }, { key: 'value', label, num: true }, ...(secondary ? [{ key: 'second', label: secondaryLabel, num: true }] : [])]}
            rows={rows.map((r) => ({ day: r.day, value: display(r[metric]), second: secondary ? display(r[secondary]) : '' }))} /></details>
    </>;
}

export function Outcomes({ totals }) {
    const segments = [{ label: 'Successful', value: totals.successful, color: 'var(--brand)' }, { label: 'Failed', value: totals.failed, color: 'var(--red-600)' }, { label: 'Pending', value: totals.pending, color: 'var(--amber-700)' }, { label: 'Cancelled', value: totals.cancelled, color: 'var(--sl-muted)' }];
    let offset = 0;
    return <div className="rp-outcomes"><div className="rp-ring-wrap"><svg viewBox="0 0 120 120" role="img" aria-label={`${number(totals.requests)} requests: ${segments.map((s) => `${s.value} ${s.label.toLowerCase()}`).join(', ')}`}>
        <circle cx="60" cy="60" r="46" fill="none" stroke="var(--sl-line)" strokeWidth="12" />
        {segments.map((s) => { const length = totals.requests ? s.value / totals.requests * 289.027 : 0; const start = offset; offset += length; return <circle key={s.label} cx="60" cy="60" r="46" fill="none" stroke={s.color} strokeWidth="12" strokeDasharray={`${length} 289.027`} strokeDashoffset={-start} transform="rotate(-90 60 60)" />; })}
    </svg><div><strong>{number(totals.requests)}</strong><span>requests</span></div></div>
        <ul className="rp-outcome-list">{segments.map((s) => <li key={s.label}><i style={{ background: s.color }} /><span>{s.label}</span><strong>{number(s.value)}</strong></li>)}</ul></div>;
}

export function AgentBars({ rows }) {
    const max = Math.max(1, ...rows.map((r) => r.tokens));
    if (!rows.length) return <p className="rp-muted">No assistant activity in this period.</p>;
    return <ol className="rp-bars">{rows.map((r, i) => <li key={r.id ?? 'legacy'}><div className="rp-bar-label"><span><b>{String(i + 1).padStart(2, '0')}</b>{r.name}</span><strong>{number(r.tokens)}</strong></div><div className="rp-track" aria-hidden="true"><span style={{ width: `${r.tokens / max * 100}%` }} /></div><small>{number(r.requests)} completed attempts</small></li>)}</ol>;
}
