import { forwardRef } from 'react';
import { controlClass, hintClass, labelClass } from './control';

const TextField = forwardRef(function TextField({
    label,
    error,
    hint,
    className = '',
    ...props
}, ref) {
    return (
        <label className="block">
            {label && <span className={labelClass()}>{label}</span>}
            <input ref={ref} className={controlClass(Boolean(error), className)} {...props} />
            {error ? (
                <p className={hintClass(true)}>{error}</p>
            ) : hint ? (
                <p className={hintClass()}>{hint}</p>
            ) : null}
        </label>
    );
});

export default TextField;
