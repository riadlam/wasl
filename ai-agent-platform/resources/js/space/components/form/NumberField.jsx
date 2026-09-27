import { forwardRef } from 'react';
import TextField from './TextField';

const NumberField = forwardRef(function NumberField(props, ref) {
    return <TextField ref={ref} inputMode="decimal" {...props} />;
});

export default NumberField;
