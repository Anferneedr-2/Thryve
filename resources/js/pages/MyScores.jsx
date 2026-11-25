import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { useRouter } from '../lib/router';
import { Button } from '../components/ui/Button';
import { Alert, Skeleton } from '../components/ui/Feedback';
import { Table } from '../components/ui/Layout';
import { Panel } from '../components/reports/ExpandedReports';
import { TrendChart, number } from '../components/reports/ReportCharts';
import { Coverage, DimensionBars, Recommendations, SCORE_AREAS, StateNotice, Stat, scoreText } from '../components/reports/AnalysisParts';

const LABEL = { clarity: 'Clarity', context: 'Context', specifics: 'Specifics', refinement: 'Refinement', verification: 'Verification' };
const dateText = (iso) => new Date(iso).toLocaleDateString('en', { month: 'short', day: 'numeric', timeZone: 'Asia/Manila' });

// A person's own prompt scores for the last 90 days. The server uses the signed-in person: no one else's results can load here.
export default function MyScores() {
    const { navigate } = useRouter();
    const [result, setResult] = useState(null);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        let active = true;
        setResult(null); setError('');
        api('/analysis/mine').then((r) => { if (active) setResult(r.data); }).catch((e) => { if (active) setError(e.message); });
        return () => { active = false; };
    }, [retry]);

    const d = result;
    const scored = d ? d.dimensions.filter((x) => x.average != null) : [];
    const strongest = scored.length ? scored.reduce((a, b) => (b.average > a.average ? b : a)) : null;
    const weakest = scored.length ? scored.reduce((a, b) => (b.average < a.average ? b : a)) : null;
    return <div className="rp" aria-label="My prompt scores">
        <div className="rp-intro"><div>
            <span className="rp-label">MY PROMPT SCORES</span>
            <h2>How your prompts are working</h2>
            <p>Scores from 1 to 5 for how you wrote your prompts in your AI chats over the last 90 days. They describe your prompting only, not your work or performance. Administrators see summaries in company reports.</p>
        </div></div>
        {error && <Alert tone="danger" title="Could not load your scores"><p>{error}</p><Button size="sm" onClick={() => setRetry((n) => n + 1)}>Try again</Button></Alert>}
        {!d && !error && <div className="sl-card" role="status"><Skeleton lines={8} /></div>}
        {d && <>
            <StateNotice state={d.state} conversations={d.totals.conversations} own />
            {d.priority_tips.length > 0 && (
                <Panel title="Tips to try first" subtitle="Start with the first one. Each tip comes from one of your own conversations, and they are ordered by the area where you score lowest.">
                    <div className="rp-recs">
                        {d.priority_tips.map((t) => (
                            <Alert key={t.conversation_id} tone="info" title={`${t.first ? 'Do this first' : 'Next'} · ${t.area_label}`}>
                                <p>{t.tip}</p>
                                <button type="button" className="rp-text-button" onClick={() => navigate(`/chat/${t.conversation_id}`)}>From: {t.title}</button>
                                <small> · {dateText(t.at)}</small>
                            </Alert>
                        ))}
                    </div>
                </Panel>
            )}
            <div className="rp-kpis">
                <Stat label="Overall score" value={d.totals.average == null ? '—' : `${number(d.totals.average)} / 5`} note="Average across your scored conversations" />
                <Stat label="Conversations scored" value={number(d.totals.conversations)} note={`Of ${number(d.coverage.eligible)} in the last 90 days`} />
                <Stat label="Strongest area" value={strongest ? strongest.label : '—'} note={strongest ? `${number(strongest.average)} / 5 on average` : 'Not enough results yet'} />
                <Stat label="Room to grow" value={weakest ? weakest.label : '—'} note={weakest ? `${number(weakest.average)} / 5 on average` : 'Not enough results yet'} />
            </div>
            <div className="rp-chart-grid">
                <Panel title="Your score over time" subtitle="Average per day. Days without a scored conversation are gaps.">
                    <TrendChart rows={d.daily} metric="average" label="Average score" nullable ceiling={5} />
                </Panel>
                <Panel title="Score by area" subtitle="Your average out of 5"><DimensionBars rows={d.dimensions} /></Panel>
            </div>
            <Panel title="Notes on your scores" subtitle="Based on your own scored conversations">
                <Recommendations items={d.recommendations} minimum={d.min_sample} conversations={d.totals.conversations} />
            </Panel>
            <Panel title="Recent conversations" subtitle="Your latest scored conversations, with a tip for each">
                {d.recent.length === 0 ? <p className="rp-muted">No scored conversations yet.</p> : (
                    <Table caption="Recent scored conversations" columns={[{ key: 'chat', label: 'Conversation' }, { key: 'average', label: 'Score', num: true }, ...SCORE_AREAS.map((k) => ({ key: k, label: LABEL[k], num: true })), { key: 'tip', label: 'Tip' }]}
                        rows={d.recent.map((r) => ({
                            chat: <span className="rp-person"><button type="button" className="rp-text-button" onClick={() => navigate(`/chat/${r.conversation_id}`)}>{r.title}</button><small>{dateText(r.at)} · {r.task_label}{r.topics.length ? ` · ${r.topics.join(', ')}` : ''}</small></span>,
                            average: number(r.average), tip: r.tip,
                            ...Object.fromEntries(SCORE_AREAS.map((k) => [k, scoreText(r.scores[k])])),
                        }))} />
                )}
                <p className="rp-note">"n/a" under Refinement means the conversation had a single message from you, so there was no answer to follow up on.</p>
            </Panel>
            <Panel title="Coverage" subtitle="Where your conversations from the last 90 days stand"><Coverage c={d.coverage} /></Panel>
            {d.notes.map((n) => <p className="rp-note" key={n}>{n}</p>)}
        </>}
    </div>;
}
