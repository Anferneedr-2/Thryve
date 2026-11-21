import { useEffect, useRef, useState } from 'react';
import { useRouter } from '../../lib/router';
import { useMedia } from '../../lib/useMedia';
import { usePanelState } from '../../lib/panelState';
import Icon from '../Icon';

// A page with its own left drawer, built like the chat history panel: a column on wide screens (remembered per browser),
// an overlay below 1024 px, and on phones the list is the home screen with a Back button on each page. Each item has its
// own URL. `items` is [{id, path, label, description, icon, group}]; `children` is the page for the open item.
export default function SideNavLayout({ title, items, current, children }) {
    const { path, navigate } = useRouter();
    const narrow = useMedia('(max-width: 1023px)');
    const phone = useMedia('(max-width: 767px)');
    const [saved, , toggleSaved] = usePanelState('sx-left', true);
    const [ov, setOv] = useState(false);
    const btn = useRef(null);
    const open = narrow ? ov : saved;
    const toggle = () => (narrow ? setOv((v) => !v) : toggleSaved());
    const home = items[0].path;

    // Phones start on the list when the bare address is opened.
    useEffect(() => { if (phone && path === home) setOv(true); }, [phone]); // eslint-disable-line react-hooks/exhaustive-deps
    // Escape closes the overlay and returns focus to its button.
    useEffect(() => {
        const onKey = (e) => { if (e.key === 'Escape' && narrow && ov) { setOv(false); btn.current?.focus(); } };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [narrow, ov]);

    const go = (item) => { setOv(false); navigate(item.path); };
    const item = items.find((i) => i.id === current) ?? items[0];
    const groups = [...new Set(items.map((i) => i.group ?? ''))];

    return (
        <div className="sx" data-left={open ? 'open' : 'closed'} data-lov={String(narrow)}>
            <aside id="sx-left" className="sx-left" aria-label={`${title} sections`} inert={!open}>
                <nav className="in" aria-label={title}>
                    {groups.map((g) => (
                        <div key={g} className="sx-group">
                            <div className="cw-sec"><span>{g || title}</span></div>
                            <div className="cw-agents">
                                {items.filter((i) => (i.group ?? '') === g).map((i) => (
                                    <a key={i.id} href={i.path} className={`cw-ag ${current === i.id ? 'on' : ''}`} aria-current={current === i.id ? 'page' : undefined}
                                        onClick={(e) => { e.preventDefault(); go(i); }}>
                                        <span className="sx-ic" aria-hidden="true"><Icon name={i.icon} /></span>
                                        <span className="t"><b>{i.label}</b>{i.description && <small>{i.description}</small>}</span>
                                    </a>
                                ))}
                            </div>
                        </div>
                    ))}
                </nav>
            </aside>

            <section className="sx-main" aria-label={item.label}>
                <header className="sx-hd">
                    <button ref={btn} type="button" className="cw-tgl" aria-label={phone ? `Back to ${title}` : open ? 'Hide sections' : 'Show sections'} aria-expanded={open} aria-controls="sx-left" onClick={toggle}>
                        {phone ? <Icon name="arrow" className="flip" /> : <Icon name="panel" />}
                    </button>
                    <div className="sx-title">
                        <h1>{item.label}</h1>
                        {item.description && <p>{item.description}</p>}
                    </div>
                </header>
                <div className="sx-body" tabIndex={-1}>
                    <div className="in">{children}</div>
                </div>
            </section>
            {narrow && ov && <div className="cw-backdrop" onClick={() => setOv(false)} aria-hidden="true" />}
        </div>
    );
}
