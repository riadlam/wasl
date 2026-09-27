export function IconButton({ label, onClick, active = false, className = '', children }) {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            onClick={onClick}
            className={`relative inline-flex h-8 w-8 items-center justify-center rounded-lg text-ink transition hover:bg-bubble ${active ? 'bg-selected text-accent' : ''} ${className}`}
        >
            {children}
        </button>
    );
}

export function channelTone(channel) {
    if (channel === 'WhatsApp') return 'bg-teal/10 text-teal-dark';
    if (channel === 'Instagram') return 'bg-selected text-accent';
    if (channel === 'Facebook') return 'bg-info text-ink';
    return 'bg-bubble text-ink';
}

export const lifecycleLabel = {
    new: 'New',
    hot: 'Hot',
    payment: 'Payment',
    customer: 'Customer',
};

export const assignmentLabel = {
    mine: 'You',
    unassigned: 'Unassigned',
    collab: 'Shared',
};

export function SpaceHeader({ title, subtitle, children }) {
    return (
        <header className="border-b border-line px-4 py-4 sm:px-6">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-coral">Wasl</p>
                    <h1 className="mt-1 text-2xl font-semibold tracking-tight text-ink">{title}</h1>
                    {subtitle && <p className="mt-1 text-sm text-muted">{subtitle}</p>}
                </div>
                {children}
            </div>
        </header>
    );
}

export function LeadAvatar({ name = '?', src, size = 36, className = '' }) {
    const dim = typeof size === 'number' ? `${size}px` : size;
    const initial = String(name || '?').trim().slice(0, 1).toUpperCase() || '?';

    if (src) {
        return (
            <img
                src={src}
                alt=""
                className={`shrink-0 rounded-full object-cover ${className}`}
                style={{ width: dim, height: dim }}
            />
        );
    }

    return (
        <span
            className={`inline-flex shrink-0 items-center justify-center rounded-full bg-bubble text-xs font-semibold text-ink ${className}`}
            style={{ width: dim, height: dim }}
        >
            {initial}
        </span>
    );
}
