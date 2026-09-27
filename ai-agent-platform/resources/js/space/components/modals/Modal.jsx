import { X } from 'lucide-react';

const SIZE = {
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-2xl',
    '2xl': 'max-w-3xl',
};

export default function Modal({ open, title, onClose, children, wide = false, size }) {
    if (!open) {
        return null;
    }

    const width = SIZE[size] || (wide ? SIZE.lg : SIZE.md);

    return (
        <div className="fixed inset-0 z-[80] flex items-end justify-center bg-ink/45 p-3 backdrop-blur-[2px] sm:items-center sm:p-6" onClick={onClose}>
            <div
                role="dialog"
                aria-modal="true"
                aria-label={title}
                onClick={(e) => e.stopPropagation()}
                className={`max-h-[90dvh] w-full overflow-y-auto rounded-2xl border border-line bg-white shadow-[0_24px_80px_-24px_rgba(15,23,42,0.45)] ${width} ${
                    size === 'xl' || size === '2xl' ? 'p-6 sm:p-8' : 'p-5'
                }`}
            >
                <div className={`mb-5 flex items-start justify-between gap-3 ${size === 'xl' || size === '2xl' ? 'mb-6' : ''}`}>
                    <h2 className={`font-semibold tracking-tight text-ink ${size === 'xl' || size === '2xl' ? 'text-xl sm:text-2xl' : 'text-lg'}`}>
                        {title}
                    </h2>
                    <button type="button" onClick={onClose} aria-label="Close" className="rounded-lg p-1.5 text-ink hover:bg-bubble">
                        <X size={18} />
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}
