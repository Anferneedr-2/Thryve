// Icons come only from the brand reference sprite (resources/icons/sprite.svg, inlined by the layout).
export default function Icon({ name, className = '', label }) {
    return (
        <svg className={`ic ${className}`} role={label ? 'img' : undefined} aria-label={label} aria-hidden={label ? undefined : true}>
            <use href={`#i-${name}`} />
        </svg>
    );
}
