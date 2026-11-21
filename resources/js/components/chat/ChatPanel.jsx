import { useMemo, useRef, useState } from 'react';
import Icon from '../Icon';
import { Skeleton } from '../ui/Feedback';
import { Menu } from '../ui/Overlay';
import { ago, hue, useNow } from './chatUtil';
import AgentAvatar from './AgentAvatar';

// The left panel: New chat, the assistants this person may use, then their recent chats.
// Choosing an assistant filters the chats and sets who the next new chat is with; the chip clears the filter.
export default function ChatPanel({ agents, picked, filter, onPick, onClearFilter, list, query, onQuery, archived, onArchived, currentId, onOpen, onNew, onRename, onArchive, onDelete }) {
    useNow(30000); // keeps the "5 min" labels moving
    const [searching, setSearching] = useState(false);
    const searchBox = useRef(null);
    const several = (agents?.length ?? 0) > 1; // a person with one assistant has nothing to choose, so the section is hidden
    const pickedAgent = agents?.find((a) => a.id === picked);

    // Counts show how many chats each assistant has; only meaningful for the plain, unfiltered list.
    const counts = useMemo(() => {
        if (!list || query || archived) return null;
        return list.reduce((m, c) => { if (c.agent) m[c.agent.id] = (m[c.agent.id] ?? 0) + 1; return m; }, {});
    }, [list, query, archived]);

    const rows = (list ?? []).filter((c) => !filter || c.agent?.id === filter);
    const filterAgent = agents?.find((a) => a.id === filter);

    const toggleSearch = () => {
        if (searching) { setSearching(false); onQuery(''); } else { setSearching(true); setTimeout(() => searchBox.current?.focus(), 0); }
    };

    return (
        <div className="pin">
            <div className="cw-sec cw-top">
                <span>{several ? 'Assistants' : 'Chats'}</span>
                <button type="button" className="sl-btn primary cw-new" title={several && pickedAgent ? `New chat with ${pickedAgent.name}` : undefined} onClick={() => onNew()}>
                    <Icon name="plus" />
                    <span>New chat</span>
                </button>
            </div>

            {several && (
                <>
                    <div className="cw-agents" role="group" aria-label="Assistants">
                        {agents.map((a) => (
                            <button key={a.id} type="button" className={`cw-ag ${picked === a.id ? 'on' : ''}`} aria-pressed={picked === a.id} onClick={() => onPick(a.id)}>
                                <AgentAvatar agent={a} agents={agents} />
                                <span className="t"><b>{a.name}</b>{a.description && <small>{a.description}</small>}</span>
                                {counts && <span className="n" aria-label={`${counts[a.id] ?? 0} chats`}>{counts[a.id] ?? 0}</span>}
                            </button>
                        ))}
                    </div>
                </>
            )}

            <div className="cw-sec">
                <span>{archived ? 'Archived chats' : 'Recent chats'}</span>
                <span className="acts">
                    <button type="button" onClick={toggleSearch} aria-expanded={searching}>Search</button>
                    <button type="button" onClick={() => onArchived(!archived)}>{archived ? 'Back to chats' : 'Archived'}</button>
                </span>
            </div>

            {searching && (
                <label className="cw-search">
                    <Icon name="search" /><span className="sr-only">Search chats</span>
                    <input ref={searchBox} type="search" placeholder="Search chats" value={query} onChange={(e) => onQuery(e.target.value)} />
                </label>
            )}

            {filterAgent && (
                <div className="cw-filter">
                    <button type="button" className="cw-chip" onClick={onClearFilter} aria-label={`Showing ${filterAgent.name} chats only. Show all assistants`}>
                        <i className={`dot ${hue(agents, filterAgent.id)}`} />{filterAgent.name}<Icon name="x" />
                    </button>
                </div>
            )}

            <nav className="cw-chats" aria-label="Chats">
                {list === null && <div className="p-2"><Skeleton lines={4} /></div>}
                {list && rows.length === 0 && (
                    <p className="cw-none">
                        {query ? 'No chats match your search.' : archived ? 'Nothing archived.' : filterAgent ? `No chats with ${filterAgent.name} yet.` : 'No chats yet. Start one above.'}
                    </p>
                )}
                {rows.map((c) => (
                    <div key={c.id} className={`cv ${currentId === c.id ? 'on' : ''}`}>
                        <button type="button" className="cv-main" aria-current={currentId === c.id ? 'page' : undefined} onClick={() => onOpen(c.id)}>
                            <span className="t">{c.title}</span>
                            <small><i className={`dot ${hue(agents, c.agent?.id)}`} />{c.agent?.name ?? 'Assistant'}<span className="when">{ago(c.last_message_at)}</span></small>
                        </button>
                        <div className="cv-menu">
                            <Menu label={`Actions for ${c.title}`} triggerClass="cw-rowbtn" items={[
                                { label: 'Rename', icon: 'edit', onSelect: () => onRename(c) },
                                { label: c.archived ? 'Unarchive' : 'Archive', icon: 'layers', onSelect: () => onArchive(c) },
                                { label: 'Delete', icon: 'trash', onSelect: () => onDelete(c) },
                            ]} />
                        </div>
                    </div>
                ))}
            </nav>
        </div>
    );
}
