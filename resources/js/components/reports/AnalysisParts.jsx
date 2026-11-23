import { Alert } from '../ui/Feedback';
import { number } from './ReportCharts';

export const SCORE_AREAS = ['clarity', 'context', 'specifics', 'refinement', 'verification'];
export const scoreText = (v) => (v == null ? 'n/a' : number(v));

export function Stat({ label, value, note }) {
    return <section className="sl-card rp-metric"><span className="rp-label">{label}</span><strong>{value}</strong><p>{note}</p></section>;
}

// Say why there is nothing to show before showing empty charts: switched off, no agent, or nothing finished yet.
export function StateNotice({ state, conversations, own = false }) {
    if (!state.enabled) {
        return <Alert tone="info" title="Prompt analysis is switched off">
            {own ? 'Your prompt scores are not available yet. Your administrator has not turned analysis on.' : 'No conversations are being analyzed. A Super Admin can turn it on under Settings, Work and analytics, after adding a Report agent under AI agents.'}
        </Alert>;
    }
    if (!state.agent_ready) {
        return <Alert tone="warning" title="No working Report agent">
            {own ? 'Analysis is on but still being set up, so nothing is scored yet.' : 'Analysis is on, but there is no enabled Report agent with a key, so nothing is being analyzed. Add one under AI agents and press Test connection.'}
        </Alert>;
    }
    if (conversations === 0) {
        return <Alert tone="info" title="No results yet for this selection">
            Conversations are analyzed in the background after they have been quiet for a while. Results appear here as they finish.
        </Alert>;
    }
    return null;
}

// Average score for each area, on the 1 to 5 scale. A gap means no data, never zero.
export function DimensionBars({ rows }) {
    return <ol className="rp-bars">{rows.map((d) => (
        <li key={d.key}>
            <div className="rp-bar-label"><span>{d.label}</span><strong>{d.average == null ? 'No data yet' : `${number(d.average)} / 5`}</strong></div>
            <div className="rp-track" aria-hidden="true"><span style={{ width: `${d.average == null ? 0 : d.average / 5 * 100}%` }} /></div>
            <small>{d.samples ? `${number(d.samples)} conversation${d.samples === 1 ? '' : 's'}${d.key === 'refinement' ? ' with a follow-up' : ''}` : 'Nothing scored yet'}</small>
        </li>
    ))}</ol>;
}

// Shares of conversations, as a percentage bar per topic.
export function ShareBars({ rows }) {
    return <ol className="rp-bars">{rows.map((r) => (
        <li key={r.id}>
            <div className="rp-bar-label"><span>{r.name}</span><strong>{number(r.conversations)} · {number(r.share)}%</strong></div>
            <div className="rp-track" aria-hidden="true"><span style={{ width: `${Math.min(100, r.share)}%` }} /></div>
        </li>
    ))}</ol>;
}

// Rule-based advice with the numbers behind it. Nothing appears until enough conversations have a result.
export function Recommendations({ items, minimum, conversations }) {
    if (items.length) {
        return <div className="rp-recs">{items.map((r) => <Alert key={r.id} tone={r.tone} title={r.title}>{r.detail}</Alert>)}</div>;
    }
    return <p className="rp-muted">{conversations < minimum
        ? `Recommendations appear once at least ${minimum} conversations have a result. So far: ${conversations}.`
        : 'Nothing stands out right now.'}</p>;
}

// Where every eligible conversation stands. Each one is in exactly one state.
export function Coverage({ c }) {
    const items = [
        ['Analyzed', c.analyzed], ['Waiting', c.pending], ['Changed since', c.stale], ['Could not be assessed', c.unassessable], ['Gave up', c.failed],
    ];
    return <>
        <div className="rp-mini-stats">{items.map(([label, value]) => <span key={label}><b>{number(value)}</b>{label}</span>)}</div>
        <p className="rp-note">{c.eligible
            ? `${number(c.percent_analyzed)}% of ${number(c.eligible)} eligible conversations have a current result. "Waiting" includes conversations still within the quiet period and ones that will be tried again. "Changed since" were analyzed before new messages arrived.`
            : 'No eligible conversations in this selection. A conversation is eligible when it has at least one employee message and has not been deleted by its owner.'}</p>
    </>;
}
