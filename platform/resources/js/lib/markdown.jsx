import { Fragment } from 'react';

// Small, safe Markdown renderer. It builds React elements only (never raw HTML), so nothing an AI answer contains can run
// in the browser. Supports paragraphs, headings, bullet/numbered lists, quotes, fenced code, tables, bold, italic, inline code
// and http(s)/mailto links. Anything else is shown as plain text.

const SAFE_URL = /^(https?:\/\/|mailto:)/i;

function inline(text, key = '') {
    const out = [];
    const re = /(`[^`\n]+`)|(\*\*[^*\n]+\*\*)|(\*[^*\n]+\*)|(\[[^\]\n]+\]\([^)\s]+\))/g;
    let last = 0;
    let m;
    let i = 0;
    while ((m = re.exec(text)) !== null) {
        if (m.index > last) out.push(text.slice(last, m.index));
        const t = m[0];
        const k = `${key}-${i++}`;
        if (m[1]) out.push(<code key={k}>{t.slice(1, -1)}</code>);
        else if (m[2]) out.push(<strong key={k}>{t.slice(2, -2)}</strong>);
        else if (m[3]) out.push(<em key={k}>{t.slice(1, -1)}</em>);
        else {
            const [, label, url] = t.match(/^\[([^\]]+)\]\(([^)\s]+)\)$/);
            out.push(SAFE_URL.test(url)
                ? <a key={k} href={url} target="_blank" rel="noopener noreferrer nofollow">{label}</a>
                : <Fragment key={k}>{label}</Fragment>);
        }
        last = m.index + t.length;
    }
    if (last < text.length) out.push(text.slice(last));
    return out;
}

const isTableRow = (l) => /^\s*\|.*\|\s*$/.test(l);
const isTableSep = (l) => /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(l);
const cells = (l) => l.trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim());

export function Markdown({ text }) {
    const lines = String(text ?? '').replace(/\r\n?/g, '\n').split('\n');
    const blocks = [];
    let i = 0;
    let n = 0;
    while (i < lines.length) {
        const line = lines[i];
        const key = `b${n++}`;
        if (/^```/.test(line)) {
            const body = [];
            i++;
            while (i < lines.length && !/^```/.test(lines[i])) body.push(lines[i++]);
            i++;
            blocks.push(<pre key={key} tabIndex={0}><code>{body.join('\n')}</code></pre>);
        } else if (/^#{1,4}\s/.test(line)) {
            const level = line.match(/^#+/)[0].length;
            const Tag = `h${Math.min(level + 2, 6)}`;
            blocks.push(<Tag key={key}>{inline(line.replace(/^#+\s/, ''), key)}</Tag>);
            i++;
        } else if (/^\s*[-*]\s+/.test(line) || /^\s*\d+[.)]\s+/.test(line)) {
            const ordered = /^\s*\d+[.)]\s+/.test(line);
            const re = ordered ? /^\s*\d+[.)]\s+/ : /^\s*[-*]\s+/;
            const items = [];
            while (i < lines.length && re.test(lines[i])) items.push(lines[i++].replace(re, ''));
            const Tag = ordered ? 'ol' : 'ul';
            blocks.push(<Tag key={key}>{items.map((it, j) => <li key={j}>{inline(it, `${key}-${j}`)}</li>)}</Tag>);
        } else if (/^>\s?/.test(line)) {
            const q = [];
            while (i < lines.length && /^>\s?/.test(lines[i])) q.push(lines[i++].replace(/^>\s?/, ''));
            blocks.push(<blockquote key={key}>{inline(q.join(' '), key)}</blockquote>);
        } else if (isTableRow(line) && i + 1 < lines.length && isTableSep(lines[i + 1])) {
            const head = cells(line);
            i += 2;
            const rows = [];
            while (i < lines.length && isTableRow(lines[i])) rows.push(cells(lines[i++]));
            blocks.push(
                <div key={key} className="sl-tablewrap" tabIndex={0} role="region" aria-label="Table in answer">
                    <table className="sl-table">
                        <thead><tr>{head.map((h, j) => <th key={j} scope="col">{inline(h, `${key}h${j}`)}</th>)}</tr></thead>
                        <tbody>{rows.map((r, j) => <tr key={j}>{r.map((c, k) => <td key={k}>{inline(c, `${key}-${j}-${k}`)}</td>)}</tr>)}</tbody>
                    </table>
                </div>,
            );
        } else if (line.trim() === '') {
            i++;
        } else {
            const p = [];
            while (i < lines.length && lines[i].trim() !== '' && !/^(```|#{1,4}\s|>\s?|\s*[-*]\s+|\s*\d+[.)]\s+)/.test(lines[i])) p.push(lines[i++]);
            if (!p.length) { p.push(lines[i++]); }
            blocks.push(<p key={key}>{p.map((l, j) => <Fragment key={j}>{j > 0 && <br />}{inline(l, `${key}-${j}`)}</Fragment>)}</p>);
        }
    }
    return <div className="chat-md">{blocks}</div>;
}
