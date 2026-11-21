import { LogoImg, useBranding } from '../lib/branding';

// The company mark and name. Shows the uploaded logo when there is one, otherwise a rounded mark with the company's first
// letter next to its name. `onBrand` is for sitting on the blue brand panel; `size` is the mark height in px.
// P02A.02 will swap in the supplied light and dark brand files here, so every screen picks them up from one place.
export default function BrandLogo({ onBrand = false, size = 36, className = '' }) {
    const { company_name, has_logo, has_logo_dark, has_logo_login, version } = useBranding();
    const name = company_name || 'Thryve';
    // The blue panel looks the same in both themes, so it uses one logo made for it: the login-page logo if there is one,
    // else the dark-mode logo (also made for dark backgrounds), else the normal logo on a white backing.
    if (onBrand && (has_logo_login || has_logo_dark || has_logo)) {
        const kind = has_logo_login ? 'logo_login' : has_logo_dark ? 'logo_dark' : 'logo';
        const img = <img className={`logo-img on-panel ${kind === 'logo' ? 'solo' : ''}`} src={`/branding/${kind}?v=${version}`} alt={name} style={{ '--logo-h': `${size}px` }} />;
        return kind === 'logo' ? <span className={`brandlogo-chip ${className}`}>{img}</span> : <span className={`brandlogo ${className}`}>{img}</span>;
    }
    if (has_logo) return <span className={`brandlogo ${className}`}><LogoImg height={size} /></span>;
    return (
        <span className={`brandlogo word ${onBrand ? 'on-brand' : ''} ${className}`} role="img" aria-label={name} style={{ fontSize: Math.round(size * 0.67) }}>
            <span className="brandlogo-mark" aria-hidden="true" style={{ width: size, height: size, fontSize: Math.round(size * 0.53) }}>{name.trim()[0]?.toUpperCase() ?? 'T'}</span>
            <span aria-hidden="true">{name}</span>
        </span>
    );
}
