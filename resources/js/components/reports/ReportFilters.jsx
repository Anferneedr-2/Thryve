import { useEffect, useState } from 'react';
import { api } from '../../lib/api';
import { TextField, SelectField } from '../ui/Form';
import { Button } from '../ui/Button';
import { Alert } from '../ui/Feedback';

export function readFilters(search) {
    const p = new URLSearchParams(search);
    return { days: p.get('days') ?? '7', from: p.get('from') ?? '', to: p.get('to') ?? '', user_id: p.get('user_id') ?? '', department_id: p.get('department_id') ?? '' };
}

export default function ReportFilters({ search, busy, onApply }) {
    const [draft, setDraft] = useState(() => readFilters(search));
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    const [options, setOptions] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [attempt, setAttempt] = useState(0);
    useEffect(() => setDraft(readFilters(search)), [search]);
    useEffect(() => {
        let active = true;
        setLoading(true);
        const timer = setTimeout(() => {
            api(`/reports/filters?${new URLSearchParams({ q: query, page, per_page: 20, ...(draft.user_id ? { selected_id: draft.user_id } : {}) })}`)
                .then((r) => { if (active) { setOptions(r); setError(null); } }).catch((e) => { if (active) setError(e.message); }).finally(() => { if (active) setLoading(false); });
        }, 250);
        return () => { active = false; clearTimeout(timer); };
    }, [query, page, draft.user_id, attempt]);
    const change = (key) => (e) => setDraft((d) => ({ ...d, [key]: e.target.value }));
    const users = [...(options?.data.users ?? [])];
    const selected = options?.data.selected_user;
    if (selected && !users.some((u) => u.id === selected.id)) users.unshift(selected);
    const apply = (e) => {
        e.preventDefault();
        const params = new URLSearchParams();
        if (draft.from || draft.to) { if (draft.from) params.set('from', draft.from); if (draft.to) params.set('to', draft.to); }
        else params.set('days', draft.days);
        if (draft.user_id) params.set('user_id', draft.user_id);
        if (draft.department_id) params.set('department_id', draft.department_id);
        onApply(params.toString());
    };
    return <form onSubmit={apply} className="sl-card rp-filters" aria-label="Report filters">
        <div className="rp-filter-top"><span className="rp-label">REPORT PERIOD</span><div className="rp-presets" aria-label="Date presets">{[7, 30, 90].map((n) => <button key={n} type="button" className={draft.days === String(n) && !draft.from && !draft.to ? 'on' : ''} aria-pressed={draft.days === String(n) && !draft.from && !draft.to} onClick={() => setDraft((d) => ({ ...d, days: String(n), from: '', to: '' }))}>{n} days</button>)}</div></div>
        <div className="rp-filter-grid">
            <TextField type="date" label="From" value={draft.from} onChange={change('from')} />
            <TextField type="date" label="To" value={draft.to} onChange={change('to')} />
            <SelectField label="Department" value={draft.department_id} onChange={change('department_id')}><option value="">All</option>{options?.data.departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</SelectField>
            <SelectField label="Person" value={draft.user_id} onChange={change('user_id')}><option value="">Everyone</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}{u.is_active ? '' : ' (inactive)'}</option>)}</SelectField>
            <Button type="submit" variant="primary" disabled={busy}>Apply filters</Button>
        </div>
        <div className="rp-search-row"><label>Find a person<input className="sl-input" type="search" placeholder="Search name or username" value={query} onChange={(e) => { setQuery(e.target.value); setPage(1); }} /></label><div className="rp-option-pages" aria-live="polite"><span>{loading ? 'Loading people…' : `${options?.meta.total ?? 0} people · page ${options?.meta.page ?? 1} of ${Math.max(1, options?.meta.last_page ?? 1)}`}</span><button type="button" aria-label="Previous people options" disabled={loading || page === 1} onClick={() => setPage((p) => p - 1)}>←</button><button type="button" aria-label="Next people options" disabled={loading || page >= (options?.meta.last_page ?? 1)} onClick={() => setPage((p) => p + 1)}>→</button></div><span className="rp-membership">Department filters use current membership.</span></div>
        {error && <Alert tone="danger" title="Could not load filter options">{error} <button type="button" onClick={() => setAttempt((n) => n + 1)}>Retry options</button></Alert>}
    </form>;
}
