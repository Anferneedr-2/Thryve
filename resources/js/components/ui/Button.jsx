import Icon from '../Icon';

// variant: primary | outline | soft | dark | ghost | danger. One primary per view.
export function Button({ variant = 'outline', size, icon, className = '', children, type = 'button', ...rest }) {
    return (
        <button type={type} className={`sl-btn ${variant} ${size === 'sm' ? 'sm' : ''} ${className}`} {...rest}>
            {icon && <Icon name={icon} />}
            {children}
        </button>
    );
}

export function IconButton({ icon, label, on, round, className = '', ...rest }) {
    return (
        <button type="button" aria-label={label} title={label} className={`sl-iconbtn ${on ? 'on' : ''} ${round ? 'round' : ''} ${className}`} {...rest}>
            <Icon name={icon} />
        </button>
    );
}
