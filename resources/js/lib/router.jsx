import { createContext, useCallback, useContext, useEffect, useState } from 'react';

// Tiny history-based router: the server serves the same page for every path, React picks the screen.
const RouterCtx = createContext({ path: '/', navigate: () => {} });
export const useRouter = () => useContext(RouterCtx);

export function RouterProvider({ children }) {
    const [location, setLocation] = useState({ path: window.location.pathname, search: window.location.search });
    const { path, search } = location;

    useEffect(() => {
        const onPop = () => setLocation({ path: window.location.pathname, search: window.location.search });
        window.addEventListener('popstate', onPop);
        return () => window.removeEventListener('popstate', onPop);
    }, []);

    const navigate = useCallback((to, { replace = false } = {}) => {
        const target = new URL(to, window.location.origin);
        if (target.pathname + target.search === window.location.pathname + window.location.search) return;
        const changedPage = target.pathname !== window.location.pathname;
        window.history[replace ? 'replaceState' : 'pushState']({}, '', target.pathname + target.search);
        setLocation({ path: target.pathname, search: target.search });
        if (changedPage) window.scrollTo(0, 0);
    }, []);

    return <RouterCtx.Provider value={{ path, search, navigate }}>{children}</RouterCtx.Provider>;
}
