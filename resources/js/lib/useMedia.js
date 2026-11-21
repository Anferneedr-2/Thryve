import { useEffect, useState } from 'react';

// True while the CSS media query matches. Used to switch the chat side panels between "column" and "overlay" modes.
export function useMedia(query) {
    const read = () => typeof window !== 'undefined' && window.matchMedia(query).matches;
    const [matches, setMatches] = useState(read);
    useEffect(() => {
        const mq = window.matchMedia(query);
        const on = () => setMatches(mq.matches);
        on();
        mq.addEventListener('change', on);
        return () => mq.removeEventListener('change', on);
    }, [query]);
    return matches;
}
