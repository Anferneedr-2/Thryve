import { Fragment, useState } from 'react';
import Icon from '../components/Icon';

// Small, safe Markdown renderer. It builds React elements only (never raw HTML), so nothing an AI answer contains can run
// in the browser: <script>, event attributes and javascript: links are shown as plain text. Supports paragraphs, headings,
// nested bullet/numbered lists, quotes, horizontal rules, fenced code (language label and copy button), tables (own scroll
// container, column alignment), bold, italic, strikethrough, inline code and http(s)/mailto links. Anything else is text.

const SAFE_URL = /^(https?:\/\/|mailto:)/i;

function Link({ url, children }) {
    return SAFE_URL.test(url)
        ? <a href={url} target="_blank" rel="noopener noreferrer nofollow">{children}</a>
        : <>{children}</>;
}

const INLINE = /(`[^`\n]+`)|(\*\*(?=\S)(.+?)\*\*)|(__(?=\S)(.+?)__)|(~~(?=\S)(.+?)~~)|(\*(?=[^\s*])([^*\n]+?)\*)|(\[([^\]\n]+)\]\(([^)\s]+)\))|(https?:\/\/[^\s<>]+)/g;

function inline(text, key = '') {
    const out = [];
    let last = 0;
    let i = 0;
    let m;
    INLINE.lastIndex = 0;
    while ((m = INLINE.exec(text)) !== null) {
        if (m.index > last) out.push(text.slice(last, m.index));
        const k = `${key}-${i++}`;
        let consumed = m[0].length;
        if (m[1]) out.push(<code key={k}>{m[1].slice(1, -1)}</code>);
        else if (m[2]) out.push(<strong key={k}>{inline(m[3], k)}</strong>);
        else if (m[4]) out.push(<strong key={k}>{inline(m[5], k)}</strong>);
        else if (m[6]) out.push(<del key={k}>{inline(m[7], k)}</del>);
        else if (m[8]) out.push(<em key={k}>{inline(m[9], k)}</em>);
        else if (m[10]) out.push(<Link key={k} url={m[12]}>{inline(m[11], k)}</Link>);
        else {
            // A bare address: keep trailing punctuation out of the link.
            const url = m[13].replace(/[.,;:!?)\]]+$/, '');
            consumed = url.length;
            out.push(<Link key={k} url={url}>{url}</Link>);
        }
        last = m.index + consumed;
        INLINE.lastIndex = last;
    }
    if (last < text.length) out.push(text.slice(last));
    return out;
}

function CodeBlock({ lang, code }) {
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        try { await navigator.clipboard.writeText(code); setCopied(true); setTimeout(() => setCopied(false), 1600); } catch (e) { /* clipboard blocked: nothing to do */ }
    };
    return (
        <figure className="md-code">
            <figcaption>
                <span>{lang || 'Code'}</span>
                <button type="button" onClick={copy} aria-label={copied ? 'Code copied' : `Copy ${lang || 'code'}`}>
                    <Icon name={copied ? 'check' : 'copy'} /><span>{copied ? 'Copied' : 'Copy'}</span>
                </button>
            </figcaption>
            <pre tabIndex={0}><code>{code}</code></pre>
        </figure>
    );
}

const isTableRow = (l) => /^\s*\|.*\|\s*$/.test(l);
const isTableSep = (l) => /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(l) && l.includes('-');
const cells = (l) => l.trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim());
const align = (c) => (/^:-+:$/.test(c) ? 'center' : /-:$/.test(c) ? 'right' : undefined);

const RULE = /^\s{0,3}([-*_])(\s*\1){2,}\s*$/;
const BULLET = /^(\s*)[-*+]\s+(.*)$/;
const NUMBER = /^(\s*)(\d+)[.)]\s+(.*)$/;
const BLOCK_START = /^(\s*```|#{1,6}\s|>\s?|\s*[-*+]\s+|\s*\d+[.)]\s+|\s{0,3}([-*_])(\s*\2){2,}\s*$)/;
const indentOf = (l) => l.match(/^\s*/)[0].replace(/\t/g, '    ').length;
const dedent = (lines, n) => lines.map((l) => l.replace(/\t/g, '    ').slice(Math.min(n, indentOf(l))));

// One list (at one indent level) starting at lines[i]. Deeper-indented lines belong to the current item, so lists nest.
function parseList(lines, i, key) {
    const first = lines[i].match(BULLET) ?? lines[i].match(NUMBER);
    const ordered = !lines[i].match(BULLET);
    const base = indentOf(lines[i]);
    const start = ordered ? Number(first[2]) : undefined;
    const items = [];
    while (i < lines.length) {
        const line = lines[i];
        const m = line.match(ordered ? NUMBER : BULLET);
        if (!m || indentOf(line) !== base) break;
        const text = ordered ? m[3] : m[2];
        const sub = [];
        i++;
        while (i < lines.length) {
            const l = lines[i];
            if (l.trim() === '') {
                // A blank line stays inside the item only when more indented content follows.
                let j = i + 1;
                while (j < lines.length && lines[j].trim() === '') j++;
                if (j < lines.length && indentOf(lines[j]) > base) { sub.push(''); i++; continue; }
                break;
            }
            if (indentOf(l) > base) { sub.push(l); i++; continue; }
            break;
        }
        items.push({ text, sub: dedent(sub, base + 2) });
    }
    const Tag = ordered ? 'ol' : 'ul';
    return [
        <Tag key={key} start={start && start !== 1 ? start : undefined}>
            {items.map((it, j) => (
                <li key={j}>
                    {inline(it.text, `${key}-${j}`)}
                    {it.sub.some((s) => s.trim()) && parseBlocks(it.sub, `${key}-${j}`)}
                </li>
            ))}
        </Tag>,
        i,
    ];
}

function parseBlocks(lines, prefix = 'b') {
    const blocks = [];
    let i = 0;
    let n = 0;
    while (i < lines.length) {
        const line = lines[i];
        const key = `${prefix}${n++}`;
        const fence = line.match(/^\s*```\s*([\w+#.-]*)/);
        if (fence) {
            const body = [];
            i++;
            while (i < lines.length && !/^\s*```/.test(lines[i])) body.push(lines[i++]);
            i++;
            blocks.push(<CodeBlock key={key} lang={fence[1]} code={body.join('\n')} />);
        } else if (RULE.test(line)) {
            blocks.push(<hr key={key} />);
            i++;
        } else if (/^#{1,6}\s/.test(line)) {
            const level = line.match(/^#+/)[0].length;
            const Tag = `h${Math.min(level + 2, 6)}`;
            blocks.push(<Tag key={key}>{inline(line.replace(/^#+\s+/, '').replace(/\s+#+\s*$/, ''), key)}</Tag>);
            i++;
        } else if (BULLET.test(line) || NUMBER.test(line)) {
            const [el, next] = parseList(lines, i, key);
            blocks.push(el);
            i = next;
        } else if (/^>\s?/.test(line)) {
            const q = [];
            while (i < lines.length && /^>\s?/.test(lines[i])) q.push(lines[i++].replace(/^>\s?/, ''));
            blocks.push(<blockquote key={key}>{parseBlocks(q, `${key}q`)}</blockquote>);
        } else if (isTableRow(line) && i + 1 < lines.length && isTableSep(lines[i + 1])) {
            const head = cells(line);
            const aligns = cells(lines[i + 1]).map(align);
            i += 2;
            const rows = [];
            while (i < lines.length && isTableRow(lines[i])) rows.push(cells(lines[i++]));
            blocks.push(
                <div key={key} className="sl-tablewrap md-table" tabIndex={0} role="region" aria-label="Table in answer">
                    <table className="sl-table">
                        <thead><tr>{head.map((h, j) => <th key={j} scope="col" style={{ textAlign: aligns[j] }}>{inline(h, `${key}h${j}`)}</th>)}</tr></thead>
                        <tbody>{rows.map((r, j) => <tr key={j}>{head.map((_, k) => <td key={k} style={{ textAlign: aligns[k] }}>{inline(r[k] ?? '', `${key}-${j}-${k}`)}</td>)}</tr>)}</tbody>
                    </table>
                </div>,
            );
        } else if (line.trim() === '') {
            i++;
        } else {
            const p = [];
            while (i < lines.length && lines[i].trim() !== '' && (p.length === 0 || !BLOCK_START.test(lines[i]))) p.push(lines[i++]);
            blocks.push(<p key={key}>{p.map((l, j) => <Fragment key={j}>{j > 0 && <br />}{inline(l.trim(), `${key}-${j}`)}</Fragment>)}</p>);
        }
    }
    return blocks;
}

export function Markdown({ text }) {
    const lines = String(text ?? '').replace(/\r\n?/g, '\n').split('\n');
    return <div className="chat-md">{parseBlocks(lines)}</div>;
}
