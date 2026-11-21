import { useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Icon from '../Icon';
import { Button } from './Button';

// Native <dialog>: focus trap, Esc to close and inert background come from the browser.
export function Dialog({ open, onClose, title, children, actions }) {
    const ref = useRef(null);
    const titleId = useId();
    useEffect(() => {
        const d = ref.current;
        if (open && !d.open) d.showModal();
        if (!open && d.open) d.close();
    }, [open]);
    return (
        <dialog ref={ref} className="sl-dialog" aria-labelledby={titleId} onClose={onClose} onClick={(e) => e.target === ref.current && onClose()}>
            <div className="sl-modal" style={{ margin: 'auto' }}>
                <h5 id={titleId}>{title}</h5>
                <div>{open && children}</div>
                <div className="row">{actions}</div>
            </div>
        </dialog>
    );
}

// Destructive actions always confirm.
export function ConfirmDialog({ open, onCancel, onConfirm, title, children, confirmLabel = 'Delete' }) {
    return (
        <Dialog open={open} onClose={onCancel} title={title}
            actions={<><Button onClick={onCancel}>Cancel</Button><Button variant="danger" onClick={onConfirm}>{confirmLabel}</Button></>}>
            <p>{children}</p>
        </Dialog>
    );
}

// Menu button + popup. Arrow keys move, Esc closes and returns focus, outside click closes.
// placement: 'below-end' (default, under the button, right edges aligned), 'right-end' (to the right of the button, bottoms
// aligned: for the left rail) or 'above-end' (above the button: for the phone tab bar). triggerClass / triggerContent let
// the shell restyle the button (avatar, rail icon with a caption) without a second menu component.
export function popupPosition(rect, placement) {
    if (placement === 'right-end') return { left: rect.right + 10, bottom: Math.max(8, window.innerHeight - rect.bottom) };
    if (placement === 'above-end') return { bottom: window.innerHeight - rect.top + 8, right: Math.max(8, window.innerWidth - rect.right) };

    return { top: rect.bottom + 6, right: Math.max(8, window.innerWidth - rect.right) };
}

export function Menu({ label, icon = 'more', items, placement = 'below-end', triggerClass = 'sl-iconbtn', triggerContent = null }) {
    const [open, setOpen] = useState(false);
    const [pos, setPos] = useState({ top: 0, right: 0 });
    const wrap = useRef(null);
    const pop = useRef(null);
    const trigger = useRef(null);
    const toggle = () => {
        if (!open) {
            // Fixed positioning lets the popup escape scrolling table wrappers and clipped cards.
            setPos(popupPosition(trigger.current.getBoundingClientRect(), placement));
        }
        setOpen(!open);
    };
    useEffect(() => {
        if (!open) return;
        pop.current?.querySelector('[role=menuitem]')?.focus();
        const away = (e) => !wrap.current.contains(e.target) && !pop.current?.contains(e.target) && setOpen(false);
        const close = () => setOpen(false);
        document.addEventListener('mousedown', away);
        window.addEventListener('scroll', close, true);
        window.addEventListener('resize', close);
        return () => {
            document.removeEventListener('mousedown', away);
            window.removeEventListener('scroll', close, true);
            window.removeEventListener('resize', close);
        };
    }, [open]);
    const onKey = (e) => {
        const els = [...(pop.current?.querySelectorAll('[role=menuitem]') ?? [])];
        const i = els.indexOf(document.activeElement);
        if (e.key === 'Escape') { setOpen(false); trigger.current.focus(); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); els[(i + 1) % els.length]?.focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); els[(i - 1 + els.length) % els.length]?.focus(); }
    };
    return (
        <div ref={wrap} style={{ position: 'relative', display: 'inline-block' }} onKeyDown={open ? onKey : undefined}>
            <button ref={trigger} type="button" className={triggerClass} aria-label={label} aria-haspopup="menu" aria-expanded={open} onClick={toggle}>
                {triggerContent ?? <Icon name={icon} />}
            </button>
            {open && createPortal(
                // Rendered on the page itself: the navigation bar blurs its background, which would otherwise make "fixed" mean "inside the bar".
                <div ref={pop} className="sl-menu" role="menu" style={{ position: 'fixed', ...pos, zIndex: 40 }}>
                    {items.map((it, i) => it === '-' ? <hr key={i} /> : (
                        <button key={it.label} type="button" role="menuitem" className={`it ${it.danger ? 'danger' : ''}`} onClick={() => { setOpen(false); trigger.current.focus(); it.onSelect?.(); }}>
                            {it.icon && <Icon name={it.icon} />}{it.label}
                        </button>
                    ))}
                </div>,
                document.body,
            )}
        </div>
    );
}
