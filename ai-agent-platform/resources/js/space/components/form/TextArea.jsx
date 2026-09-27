import { forwardRef } from 'react';
import { controlClass, hintClass, labelClass } from './control';

const TextArea = forwardRef(function TextArea({
    label,
    error,
    hint,
    rows = 4,
    className = '',
    ...props
}, ref) {
    return (
        <label className="block">
            {label && <span className={labelClass()}>{label}</span>}
            <textarea
                ref={ref}
                rows={rows}
                className={controlClass(Boolean(error), `h-auto min-h-[6.5rem] py-2.5 ${className}`)}
                {...props}
            />
            {error ? (
                <p className={hintClass(true)}>{error}</p>
            ) : hint ? (
                <p className={hintClass()}>{hint}</p>
            ) : null}
        </label>
    );
});

export default TextArea;
