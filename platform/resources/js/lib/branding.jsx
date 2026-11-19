import { createContext, useCallback, useContext, useEffect, useState } from 'react';

// Company name, logo and theme default. The server puts the first copy in the page so there is no flash.
const FALLBACK = { company_name: 'Thryve', default_theme: 'system', has_logo: false, has_favicon: false };
const BrandingCtx = createContext({ ...FALLBACK, version: 0, refresh: () => {} });
export const useBranding = () => useContext(BrandingCtx);

export function BrandingProvider({ children }) {
    const [state, setState] = useState({ ...FALLBACK, ...(window.__APP__?.branding ?? {}), version: Date.now() });

    // Called after Super Admin changes branding so the shell updates without a reload.
    const refresh = useCallback((next) => setState({ ...FALLBACK, ...next, version: Date.now() }), []);

    useEffect(() => { document.title = state.company_name; }, [state.company_name]);

    return <BrandingCtx.Provider value={{ ...state, refresh }}>{children}</BrandingCtx.Provider>;
}

// The logo image when one is uploaded, otherwise the company name as text.
export function Brand({ className = '' }) {
    const { company_name, has_logo, version } = useBranding();
    return has_logo
        ? <img src={`/branding/logo?v=${version}`} alt={company_name} style={{ height: 28, width: 'auto', maxWidth: 160, objectFit: 'contain' }} className={className} />
        : <span className={className}>{company_name}</span>;
}
