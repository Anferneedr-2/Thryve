import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { ApiError, api, send } from './api';

// status: 'loading' (checking for an existing session) | 'guest' | 'ready'
const AuthCtx = createContext(null);
export const useAuth = () => useContext(AuthCtx);

export function AuthProvider({ children }) {
    const [state, setState] = useState({ status: 'loading', user: null, notice: null });

    useEffect(() => {
        api('/auth/me')
            .then((r) => setState({ status: 'ready', user: r.data, notice: null }))
            .catch((err) => setState({
                status: 'guest',
                user: null,
                // Explain why a returning visitor is signed out (signed in elsewhere, ended by an admin, deactivated).
                notice: ['session_replaced', 'session_terminated', 'account_deactivated'].includes(err.code) ? err.message : null,
            }));
    }, []);

    // Any request that finds the session gone (signed in elsewhere, ended by an admin, deactivated) returns to sign-in.
    useEffect(() => {
        const onLost = (e) => setState((s) => (s.status === 'ready' ? { status: 'guest', user: null, notice: e.detail.message } : s));
        window.addEventListener('api:session-lost', onLost);
        return () => window.removeEventListener('api:session-lost', onLost);
    }, []);

    const login = useCallback(async (username, password) => {
        const user = await send('POST', '/auth/login', { username, password });
        setState({ status: 'ready', user, notice: null });
        return user;
    }, []);

    const logout = useCallback(async () => {
        try { await send('POST', '/auth/logout'); } catch (e) { if (!(e instanceof ApiError)) throw e; }
        setState({ status: 'guest', user: null, notice: null });
    }, []);

    const acknowledgePolicy = useCallback(async () => {
        await send('POST', '/policy/acknowledge', { accept: true });
        setState((s) => ({ ...s, user: { ...s.user, policy_acknowledged: true } }));
    }, []);

    return <AuthCtx.Provider value={{ ...state, login, logout, acknowledgePolicy }}>{children}</AuthCtx.Provider>;
}
