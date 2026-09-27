import { Search } from 'lucide-react';
import { controlClass } from './control';

export default function SearchField({
    value,
    onChange,
    placeholder = 'Search',
    className = '',
}) {
    return (
        <label className={`relative block ${className}`}>
            <Search size={15} className="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-ink/45" />
            <input
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                className={controlClass(false, 'h-10 ps-9')}
            />
        </label>
    );
}
