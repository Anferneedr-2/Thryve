import { Markdown } from '../lib/markdown';

// Developer fixture (P05A.05): one answer containing every element the renderer supports, plus unsafe input that must stay text.
// Removed before launch with the other galleries.
export const FIXTURE = `# Quarterly plan
Here is a **bold** claim, some *italic* words, ~~struck text~~ and \`inline_code()\`. A very long unbroken value: ${'abcdefghij'.repeat(14)} and a link https://example.com/a/very/long/path/that/keeps/going/and/going/and/going/forever?x=1.

## Steps
1. Gather the numbers
   - Sales export
   - Finance export
     - Bank statement
     - Card statement
2. Clean the data
3. Share the result

- First point
- Second point with [a safe link](https://example.com)
  1. nested number one
  2. nested number two

> A quoted block that explains the reasoning
> and continues on a second line.

---

### Comparison
| Plan | Seats | Storage | Support | Price | Billing | Region | Notes |
|:-----|------:|:-------:|---------|------:|---------|--------|-------|
| Starter | 5 | 10 GB | Email | 12 | Monthly | PH | Good for small teams that are only getting started with the tool |
| Team | 25 | 100 GB | Chat | 49 | Monthly | PH | Most popular |
| Business | 100 | 1 TB | Phone | 199 | Annual | SG | Includes audit exports |

\`\`\`js
function total(rows) {
  return rows.reduce((sum, r) => sum + r.amount, 0); // a long line to force sideways scrolling inside the block, not the page
}
\`\`\`

#### Unsafe input (must stay plain text)
<script>window.__pwned = true</script>
<img src=x onerror="window.__pwned = true">
[click me](javascript:window.__pwned=true)
[data link](data:text/html,<b>x</b>)
`;

export default function AnswerGallery() {
    return (
        <main style={{ padding: 24, maxWidth: 1180, margin: '0 auto' }}>
            <ol className="m-thread" style={{ listStyle: 'none', padding: 0 }}>
                <li className="m ai"><div className="body"><Markdown text={FIXTURE} /></div></li>
            </ol>
        </main>
    );
}
