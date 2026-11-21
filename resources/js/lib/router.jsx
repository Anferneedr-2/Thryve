import { createContext, useCallback, useContext, useEffect, useState } from 'react';

// Tiny history-based router: the server serves the same page for every path, React picks the screen.
const RouterCtx = createContext({ path: '/', navigate: () => {} });
export const useRouter = () => useContext(RouterCtx);

export function RouterProvider({ children }) {
    const [path, setPath] = useState(window.location.pathname);

    useEffect(() => {
        const onPop = () => setPath(window.location.pathname);
        window.addEventListener('popstate', onPop);
        return () => window.removeEventListener('popstate', onPop);
    }, []);

    const navigate = useCallback((to, { replace = false } = {}) => {
        if (to === window.location.pathname) return;
        window.history[replace ? 'replaceState' : 'pushState']({}, '', to);
        setPath(to);
        window.scrollTo(0, 0);
    }, []);

    return <RouterCtx.Provider value={{ path, navigate }}>{children}</RouterCtx.Provider>;
}
