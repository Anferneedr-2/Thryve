import { createContext, useCallback, useContext, useEffect, useState } from 'react';

// Company name, logo and theme default. The server puts the first copy in the page so there is no flash.
const FALLBACK = { company_name: 'Thryve', default_theme: 'system', has_logo: false, has_logo_dark: false, has_logo_login: false, has_favicon: false };
const BrandingCtx = createContext({ ...FALLBACK, version: 0, refresh: () => {} });
export const useBranding = () => useContext(BrandingCtx);

export function BrandingProvider({ children }) {
    const [state, setState] = useState({ ...FALLBACK, ...(window.__APP__?.branding ?? {}), version: Date.now() });

    // Called after Super Admin changes branding so the shell updates without a reload.
    const refresh = useCallback((next) => setState({ ...FALLBACK, ...next, version: Date.now() }), []);

    useEffect(() => { document.title = state.company_name; }, [state.company_name]);

    return <BrandingCtx.Provider value={{ ...state, refresh }}>{children}</BrandingCtx.Provider>;
}

// The uploaded logo. When a separate dark-theme logo exists both files are in the page and CSS shows the one that fits the
// current theme (it follows the theme switch and shows the right one from the first paint). Without a dark file, the
// normal logo is shown on a white backing in the dark theme so it stays visible. `height` is in px.
export function LogoImg({ height = 28, className = '' }) {
    const { company_name, has_logo_dark, version } = useBranding();
    return (
        <>
            <img className={`logo-img lt ${has_logo_dark ? '' : 'nodark'} ${className}`} src={`/branding/logo?v=${version}`} alt={company_name} style={{ '--logo-h': `${height}px` }} />
            {has_logo_dark && <img className={`logo-img dk ${className}`} src={`/branding/logo_dark?v=${version}`} alt={company_name} style={{ '--logo-h': `${height}px` }} />}
        </>
    );
}

// The logo image when one is uploaded, otherwise the company name as text.
export function Brand({ className = '' }) {
    const { company_name, has_logo } = useBranding();
    return has_logo ? <LogoImg height={28} /> : <span className={className}>{company_name}</span>;
}
