export function Skeleton({ className = '', style }) {
    return <span className={`skeleton inline-block rounded-md ${className}`} style={style} aria-hidden />;
}

function FadeIn({ className = '', children }) {
    return (
        <div className={`animate-in fade-in duration-300 ${className}`} role="status" aria-label="Loading">
            {children}
        </div>
    );
}

export function ConversationRowSkeleton() {
    return (
        <div className="flex gap-2.5 border-b border-line px-3 py-2.5">
            <Skeleton className="h-9 w-9 shrink-0 !rounded-full" />
            <div className="min-w-0 flex-1 space-y-2 pt-0.5">
                <div className="flex items-center justify-between gap-3">
                    <Skeleton className="h-3 w-28" />
                    <Skeleton className="h-2.5 w-10" />
                </div>
                <Skeleton className="h-2.5 w-[85%]" />
                <Skeleton className="h-2.5 w-16" />
            </div>
        </div>
    );
}

export function ConversationListSkeleton({ rows = 8 }) {
    return (
        <FadeIn>
            {Array.from({ length: rows }, (_, i) => (
                <ConversationRowSkeleton key={i} />
            ))}
        </FadeIn>
    );
}

export function MessageBubbleSkeleton({ side = 'left', width = '55%' }) {
    return (
        <div className={`flex ${side === 'right' ? 'justify-end' : ''}`}>
            <Skeleton
                className={`h-12 max-w-[85%] rounded-2xl ${side === 'right' ? 'rounded-tr-md' : 'rounded-tl-md'}`}
                style={{ width }}
            />
        </div>
    );
}

export function MessageThreadSkeleton() {
    return (
        <div className="space-y-3 px-1 py-2">
            <MessageBubbleSkeleton side="left" width="48%" />
            <MessageBubbleSkeleton side="right" width="62%" />
            <MessageBubbleSkeleton side="left" width="70%" />
            <MessageBubbleSkeleton side="right" width="40%" />
            <MessageBubbleSkeleton side="left" width="55%" />
        </div>
    );
}

export function LeadPanelSkeleton() {
    return (
        <div className="space-y-4 px-2.5 py-3">
            <div className="flex items-start gap-2.5 border-b border-line pb-3">
                <Skeleton className="h-10 w-10 shrink-0 !rounded-full" />
                <div className="min-w-0 flex-1 space-y-2 pt-1">
                    <Skeleton className="h-3 w-28" />
                    <Skeleton className="h-2.5 w-20" />
                    <Skeleton className="h-5 w-14 rounded" />
                </div>
            </div>
            {Array.from({ length: 5 }, (_, i) => (
                <div key={i} className="flex items-center justify-between gap-2 py-1">
                    <Skeleton className="h-2.5 w-14" />
                    <Skeleton className="h-2.5 w-24" />
                </div>
            ))}
        </div>
    );
}

/** Full-height page: soft header + body slot (or default body). */
export function PageSkeleton({ children, className = '' }) {
    return (
        <FadeIn className={`flex h-full min-h-0 flex-col bg-white ${className}`}>
            <div className="shrink-0 space-y-3 border-b border-line px-4 py-4 sm:px-6">
                <Skeleton className="h-2.5 w-14" />
                <Skeleton className="h-6 w-48" />
                <Skeleton className="h-3 w-72 max-w-full" />
            </div>
            <div className="min-h-0 flex-1 overflow-hidden px-4 py-4 sm:px-6">
                {children}
            </div>
        </FadeIn>
    );
}

/** Table / list rows for contacts, leads, orders, automations, schedule. */
export function TableSkeleton({ rows = 6, columns = 4, className = '' }) {
    return (
        <FadeIn className={className}>
            <div className="overflow-hidden rounded-2xl border border-line">
                <div className="flex gap-4 border-b border-line bg-bubble/60 px-4 py-3">
                    {Array.from({ length: columns }, (_, i) => (
                        <Skeleton key={i} className={`h-2.5 ${i === 0 ? 'w-28' : 'w-16'} ${i === columns - 1 ? 'ms-auto' : ''}`} />
                    ))}
                </div>
                <div className="divide-y divide-line bg-white">
                    {Array.from({ length: rows }, (_, row) => (
                        <div key={row} className="flex items-center gap-4 px-4 py-3.5">
                            <Skeleton className="h-8 w-8 shrink-0 !rounded-lg" />
                            <div className="min-w-0 flex-1 space-y-2">
                                <Skeleton className="h-3 w-[42%]" />
                                <Skeleton className="h-2.5 w-[28%]" />
                            </div>
                            {columns > 2 && <Skeleton className="hidden h-2.5 w-16 sm:block" />}
                            {columns > 3 && <Skeleton className="hidden h-2.5 w-14 md:block" />}
                            <Skeleton className="h-7 w-14 shrink-0 rounded-lg" />
                        </div>
                    ))}
                </div>
            </div>
        </FadeIn>
    );
}

/** Card grid for products, workflows, posts. */
export function CardGridSkeleton({
    count = 6,
    cols = 'grid-cols-1 sm:grid-cols-2 xl:grid-cols-3',
    media = false,
    className = '',
}) {
    return (
        <FadeIn className={`grid gap-4 ${cols} ${className}`}>
            {Array.from({ length: count }, (_, i) => (
                <div key={i} className="overflow-hidden rounded-2xl border border-line bg-white">
                    {media ? (
                        <Skeleton className="aspect-[4/3] w-full !rounded-none" />
                    ) : (
                        <div className="flex h-[100px] items-center justify-center border-b border-line bg-bubble/40 px-4">
                            <Skeleton className="h-12 w-28 rounded-xl" />
                        </div>
                    )}
                    <div className="space-y-2.5 p-3.5">
                        <Skeleton className="h-3.5 w-3/4" />
                        <Skeleton className="h-2.5 w-full" />
                        <Skeleton className="h-2.5 w-2/3" />
                        <div className="flex gap-2 pt-1">
                            <Skeleton className="h-6 w-16 rounded-lg" />
                            <Skeleton className="h-6 w-12 rounded-lg" />
                        </div>
                    </div>
                </div>
            ))}
        </FadeIn>
    );
}

/** Stacked form fields — agents, delivery, modals. */
export function FormSkeleton({ fields = 5, compact = false, className = '' }) {
    return (
        <FadeIn className={`space-y-4 ${className}`}>
            {!compact && (
                <div className="space-y-2 pb-1">
                    <Skeleton className="h-2.5 w-16" />
                    <Skeleton className="h-5 w-40" />
                </div>
            )}
            {Array.from({ length: fields }, (_, i) => (
                <div key={i} className="space-y-2">
                    <Skeleton className="h-2.5 w-20" />
                    <Skeleton className={`w-full rounded-xl ${compact ? 'h-9' : 'h-10'}`} />
                </div>
            ))}
            <div className="flex justify-end gap-2 pt-2">
                <Skeleton className="h-9 w-20 rounded-lg" />
                <Skeleton className="h-9 w-28 rounded-lg" />
            </div>
        </FadeIn>
    );
}

/** Compact vertical list (post picker, options). */
export function ListSkeleton({ rows = 5, className = '' }) {
    return (
        <FadeIn className={`space-y-2 ${className}`}>
            {Array.from({ length: rows }, (_, i) => (
                <div key={i} className="flex items-center gap-2.5 rounded-xl border border-line px-2.5 py-2">
                    <Skeleton className="h-9 w-9 shrink-0 !rounded-lg" />
                    <div className="min-w-0 flex-1 space-y-1.5">
                        <Skeleton className="h-2.5 w-[55%]" />
                        <Skeleton className="h-2 w-[35%]" />
                    </div>
                </div>
            ))}
        </FadeIn>
    );
}

/** App boot: rail + main content. */
export function WorkspaceShellSkeleton() {
    return (
        <FadeIn className="flex h-full min-h-0 bg-white">
            <aside className="hidden h-full w-[220px] shrink-0 flex-col border-e border-line bg-white md:flex">
                <div className="space-y-3 border-b border-line px-3 py-3">
                    <div className="flex items-center gap-2.5">
                        <Skeleton className="h-9 w-9 shrink-0 !rounded-xl" />
                        <div className="min-w-0 flex-1 space-y-1.5">
                            <Skeleton className="h-3 w-24" />
                            <Skeleton className="h-2 w-16" />
                        </div>
                    </div>
                    <div className="flex gap-1.5">
                        <Skeleton className="h-8 w-8 !rounded-lg" />
                        <Skeleton className="h-8 w-8 !rounded-lg" />
                        <Skeleton className="ms-auto h-8 flex-1 !rounded-lg" />
                    </div>
                </div>
                <div className="flex flex-col gap-1.5 px-2 py-3">
                    {Array.from({ length: 8 }, (_, i) => (
                        <Skeleton key={i} className="h-9 w-full rounded-lg" />
                    ))}
                </div>
            </aside>
            <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                <div className="flex h-11 items-center gap-2 border-b border-line px-4">
                    <Skeleton className="h-3 w-20" />
                    <Skeleton className="ms-auto h-8 w-8 !rounded-lg" />
                </div>
                <div className="min-h-0 flex-1 p-4 sm:p-6">
                    <div className="mb-5 space-y-2">
                        <Skeleton className="h-2.5 w-14" />
                        <Skeleton className="h-6 w-44" />
                        <Skeleton className="h-3 w-64 max-w-full" />
                    </div>
                    <CardGridSkeleton count={6} />
                </div>
            </div>
        </FadeIn>
    );
}

/** Products / posts-style shell with left nav + grid. */
export function SplitViewSkeleton({ cards = 6, media = false }) {
    return (
        <FadeIn className="flex h-full min-h-0 overflow-hidden bg-cream">
            <aside className="hidden h-full w-[220px] shrink-0 flex-col border-e border-line bg-white/80 lg:flex">
                <div className="flex h-11 items-center px-3">
                    <Skeleton className="h-3.5 w-20" />
                </div>
                <div className="space-y-1.5 px-2 pb-2">
                    <Skeleton className="mx-1 mb-2 h-2 w-14" />
                    {Array.from({ length: 4 }, (_, i) => (
                        <Skeleton key={i} className="h-9 w-full rounded-lg" />
                    ))}
                    <Skeleton className="mx-1 mb-2 mt-4 h-2 w-16" />
                    <Skeleton className="h-9 w-full rounded-lg" />
                </div>
            </aside>
            <div className="min-h-0 flex-1 overflow-hidden bg-white p-4 sm:p-6">
                <div className="mb-5 flex items-end justify-between gap-3">
                    <div className="space-y-2">
                        <Skeleton className="h-5 w-36" />
                        <Skeleton className="h-3 w-52 max-w-full" />
                    </div>
                    <Skeleton className="h-9 w-24 rounded-lg" />
                </div>
                <CardGridSkeleton count={cards} media={media} />
            </div>
        </FadeIn>
    );
}

export function InlineSpinner({ className = '', label = 'Loading' }) {
    return (
        <span className={`inline-flex items-center gap-2 text-[11px] font-semibold text-muted ${className}`} role="status">
            <span className="inbox-spinner h-3.5 w-3.5 shrink-0 rounded-full border-2 border-line border-t-accent" aria-hidden />
            {label}
        </span>
    );
}

export function TopLoadBar({ active }) {
    if (!active) return null;
    return (
        <div className="pointer-events-none absolute inset-x-0 top-0 z-20 h-0.5 overflow-hidden bg-transparent">
            <div className="inbox-load-bar h-full w-1/3 rounded-full bg-accent" />
        </div>
    );
}
