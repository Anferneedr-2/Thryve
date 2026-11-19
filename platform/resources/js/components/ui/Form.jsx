import { useId } from 'react';
import Icon from '../Icon';

// Field wires label, help and error text to the control for assistive tech.
export function Field({ label, help, error, ok, children }) {
    const id = useId();
    const describedBy = error ? `${id}-err` : ok ? `${id}-ok` : help ? `${id}-help` : undefined;
    return (
        <div className="sl-field">
            {label && <label htmlFor={id}>{label}</label>}
            {children({ id, 'aria-describedby': describedBy, 'aria-invalid': error ? true : undefined })}
            {error ? (
                <div id={`${id}-err`} className="sl-error" role="alert"><Icon name="alert" />{error}</div>
            ) : ok ? (
                <div id={`${id}-ok`} className="sl-ok"><Icon name="check" />{ok}</div>
            ) : help ? (
                <div id={`${id}-help`} className="sl-help">{help}</div>
            ) : null}
        </div>
    );
}

export function TextField({ label, help, error, ok, className = '', ...rest }) {
    return (
        <Field label={label} help={help} error={error} ok={ok}>
            {(a11y) => <input className={`sl-input ${error ? 'invalid' : ''} ${ok ? 'ok' : ''} ${className}`} {...a11y} {...rest} />}
        </Field>
    );
}

export function SelectField({ label, help, error, children, ...rest }) {
    return (
        <Field label={label} help={help} error={error}>
            {(a11y) => <select className="sl-select" {...a11y} {...rest}>{children}</select>}
        </Field>
    );
}

export function TextareaField({ label, help, error, ...rest }) {
    return (
        <Field label={label} help={help} error={error}>
            {(a11y) => <textarea className={`sl-textarea ${error ? 'invalid' : ''}`} {...a11y} {...rest} />}
        </Field>
    );
}

function Option({ cls, label, hint, ...rest }) {
    return (
        <label className="sl-opt">
            <input className={cls} {...rest} />
            <span>{label}{hint && <small>{hint}</small>}</span>
        </label>
    );
}
export const Checkbox = (p) => <Option cls="sl-cb" type="checkbox" {...p} />;
export const Radio = (p) => <Option cls="sl-rb" type="radio" {...p} />;
export const Switch = (p) => <Option cls="sl-sw" type="checkbox" role="switch" {...p} />;
