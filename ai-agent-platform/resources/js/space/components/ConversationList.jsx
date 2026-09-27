import { useEffect, useState } from 'react';
import { ChevronDown, LoaderCircle, PanelLeft, Search, SlidersHorizontal } from 'lucide-react';
import { platformMeta, conversationPlatform } from './channels/platforms';
import { ConversationListSkeleton, InlineSpinner, TopLoadBar } from './inboxSkeletons';
import { useSpace } from '../context';
import { IconButton, LeadAvatar } from './ui';
import { SearchField } from './form';

const PAGE_SIZE = 20;

export default function ConversationList({ platformsCollapsed = false, onExpandPlatforms }) {
    const {
        visible,
        selectedId,
        setSelectedId,
        filter,
        sort,
        setSort,
        status,
        setStatus,
        unrepliedOnly,
        setUnrepliedOnly,
        query,
        setQuery,
        setFiltersOpen,
        setLeadOpen,
        inboxReady,
        inboxSyncing,
    } = useSpace();
    const [searching, setSearching] = useState(false);
    const [sortOpen, setSortOpen] = useState(false);
    const [limit, setLimit] = useState(PAGE_SIZE);
    const [loadingMore, setLoadingMore] = useState(false);
    const title = filter === 'all' ? 'All' : platformMeta(filter.replace('platform:', '')).label;
    const showSkeleton = !inboxReady || (inboxSyncing && visible.length === 0);

    // Reset the page window whenever the filtered lead set changes.
    useEffect(() => {
        setLimit(PAGE_SIZE);
    }, [filter, sort, status, unrepliedOnly, query]);

    // Keep the open chat visible in the list if it falls outside the current window.
    useEffect(() => {
        if (!selectedId) return;
        const index = visible.findIndex((c) => String(c.id) === String(selectedId));
        if (index >= 0 && index + 1 > limit) {
            setLimit(Math.ceil((index + 1) / PAGE_SIZE) * PAGE_SIZE);
        }
    }, [selectedId, visible, limit]);

    const shown = visible.slice(0, limit);
    const hasMore = visible.length > limit;
    const remaining = Math.max(0, visible.length - limit);

    const loadMore = () => {
        if (!hasMore || loadingMore) return;
        setLoadingMore(true);
        // Keep the click snappy even when the list is already in memory.
        window.requestAnimationFrame(() => {
            setLimit((current) => current + PAGE_SIZE);
            setLoadingMore(false);
        });
    };

    return (
        <div className="relative flex h-full min-h-0 w-full flex-col bg-white">
            <TopLoadBar active={inboxSyncing && visible.length > 0} />
            <header className="border-b border-line px-2 pt-1">
                <div className="flex h-11 items-center justify-between">
                    <div className="flex min-w-0 items-center gap-2 px-2">
                        <h2 className="truncate text-sm font-semibold text-ink">{title}</h2>
                        {inboxSyncing && <InlineSpinner label="Syncing" />}
                    </div>
                    <div className="flex items-center">
                        {platformsCollapsed && (
                            <IconButton label="Show platforms" className="hidden lg:inline-flex" onClick={onExpandPlatforms}>
                                <PanelLeft size={15} />
                            </IconButton>
                        )}
                        <IconButton label="Inbox filters" className="lg:hidden" onClick={() => setFiltersOpen(true)}>
                            <SlidersHorizontal size={15} />
                        </IconButton>
                        <IconButton label="Search conversations" active={searching} onClick={() => setSearching((v) => !v)}>
                            <Search size={15} />
                        </IconButton>
                    </div>
                </div>
                {searching && (
                    <SearchField value={query} onChange={setQuery} placeholder="Search name or message" />
                )}
                <div className="flex items-center justify-between gap-2 py-1.5">
                    <div className="relative">
                        <button
                            type="button"
                            onClick={() => setSortOpen((v) => !v)}
                            className="inline-flex items-center gap-1 rounded-lg px-1 py-1 text-[13px] font-semibold text-ink hover:bg-bubble"
                        >
                            {status === 'open' ? 'Open' : 'Closed'}, {sort === 'newest' ? 'Newest' : 'Oldest'}
                            <ChevronDown size={14} />
                        </button>
                        {sortOpen && (
                            <div className="absolute start-0 top-full z-20 mt-1 w-40 overflow-hidden rounded-xl border border-line bg-white py-1 shadow-lg">
                                {[
                                    ['open', 'newest', 'Open, Newest'],
                                    ['open', 'oldest', 'Open, Oldest'],
                                    ['closed', 'newest', 'Closed'],
                                ].map(([nextStatus, nextSort, label]) => (
                                    <button
                                        key={label}
                                        type="button"
                                        onClick={() => {
                                            setStatus(nextStatus);
                                            setSort(nextSort);
                                            setSortOpen(false);
                                        }}
                                        className="block w-full px-3 py-1.5 text-left text-sm text-ink hover:bg-bubble"
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                    <label className="flex items-center gap-2 text-xs font-semibold text-ink">
                        <button
                            type="button"
                            role="switch"
                            aria-checked={unrepliedOnly}
                            onClick={() => setUnrepliedOnly(!unrepliedOnly)}
                            className={`relative h-4 w-7 rounded-full transition ${unrepliedOnly ? 'bg-accent' : 'bg-line'}`}
                        >
                            <span className={`absolute top-0.5 h-3 w-3 rounded-full bg-white transition ${unrepliedOnly ? 'start-3.5' : 'start-0.5'}`} />
                        </button>
                        Unreplied
                    </label>
                </div>
            </header>

            <div className="inbox-scroll min-h-0 flex-1 overflow-y-auto">
                {showSkeleton && <ConversationListSkeleton rows={9} />}
                {!showSkeleton && visible.length === 0 && (
                    <p className="px-4 py-10 text-center text-xs font-semibold text-muted">No conversations to show</p>
                )}
                {!showSkeleton &&
                    shown.map((c) => {
                        const meta = platformMeta(conversationPlatform(c));
                        const Icon = meta.Icon;
                        return (
                            <button
                                key={c.id}
                                type="button"
                                onClick={() => {
                                    setSelectedId(c.id);
                                    setLeadOpen(false);
                                }}
                                className={`flex w-full gap-2.5 border-b border-line px-3 py-2.5 text-left transition hover:bg-bubble ${selectedId === c.id ? 'bg-selected' : ''}`}
                            >
                                <LeadAvatar name={c.name} src={c.avatar_url} size={36} />
                                <span className="min-w-0 flex-1">
                                    <span className="flex items-baseline justify-between gap-2">
                                        <span className="truncate text-[13px] font-semibold text-ink">{c.name}</span>
                                        <span className="shrink-0 text-[11px] text-muted">{c.time}</span>
                                    </span>
                                    <span className="mt-0.5 block truncate text-xs text-muted">{c.preview}</span>
                                    <span className="mt-1.5 flex items-center gap-1.5">
                                        <span className="inline-flex min-w-0 items-center gap-1 text-[11px] font-semibold text-muted">
                                            <Icon size={11} className="shrink-0" style={{ color: meta.color }} />
                                            <span className="truncate">
                                                {c.account_name ? `${meta.label} · ${c.account_name}` : meta.label}
                                            </span>
                                        </span>
                                        {c.unreplied && <span className="ms-auto h-2 w-2 shrink-0 rounded-full bg-coral" />}
                                    </span>
                                </span>
                            </button>
                        );
                    })}

                {!showSkeleton && hasMore && (
                    <div className="border-b border-line px-3 py-3">
                        <button
                            type="button"
                            onClick={loadMore}
                            disabled={loadingMore}
                            className="flex w-full items-center justify-center gap-2 rounded-xl border border-line bg-white px-3 py-2 text-xs font-semibold text-ink transition hover:bg-bubble disabled:opacity-60"
                        >
                            {loadingMore ? <LoaderCircle size={14} className="animate-spin" /> : null}
                            Load more
                            <span className="font-medium text-muted">
                                ({Math.min(PAGE_SIZE, remaining)} of {remaining})
                            </span>
                        </button>
                    </div>
                )}

                {!showSkeleton && shown.length > 0 && !hasMore && visible.length > PAGE_SIZE && (
                    <p className="px-3 py-3 text-center text-[11px] font-semibold text-muted">
                        Showing all {visible.length} chats
                    </p>
                )}
            </div>
        </div>
    );
}
