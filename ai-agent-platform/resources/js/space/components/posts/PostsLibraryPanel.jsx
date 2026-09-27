import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { CheckSquare, Heart, Images, LoaderCircle, MessageCircle, Play, Square, X } from 'lucide-react';
import { api } from '../../api';
import { useSpace } from '../../context';
import { platformMeta } from '../channels/platforms';
import { InlineSpinner, CardGridSkeleton } from '../inboxSkeletons';
import { SearchField } from '../form';

const PAGE_SIZE = 20;

export default function PostsLibraryPanel() {
    const { socialAccounts, setView, can, setError } = useSpace();
    const [posts, setPosts] = useState([]);
    const [cursor, setCursor] = useState(null);
    const [hasMore, setHasMore] = useState(false);
    const [source, setSource] = useState(null);
    const [loading, setLoading] = useState(true);
    const [loadingMore, setLoadingMore] = useState(false);
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [platform, setPlatform] = useState('all');
    const [accountId, setAccountId] = useState('');
    const [selecting, setSelecting] = useState(false);
    const [selected, setSelected] = useState(() => new Set());
    const [bulkBusy, setBulkBusy] = useState(false);
    const previewedRef = useRef(new Set());

    const accounts = useMemo(
        () => (socialAccounts || []).filter((account) => account.platform && account.platform !== 'simulator' && account.status !== 'disconnected'),
        [socialAccounts],
    );
    const platforms = useMemo(() => {
        const ids = [...new Set(accounts.map((account) => account.platform))];
        return ids.map((id) => ({ id, ...platformMeta(id) }));
    }, [accounts]);
    const visibleAccounts = useMemo(
        () => (platform === 'all' ? accounts : accounts.filter((account) => account.platform === platform)),
        [accounts, platform],
    );

    const fetchPage = useCallback(async ({ append = false, nextCursor = null } = {}) => {
        if (append) setLoadingMore(true);
        else setLoading(true);
        try {
            const { data } = await api.get('/posts', {
                params: {
                    limit: PAGE_SIZE,
                    cursor: nextCursor || undefined,
                    search: search || undefined,
                    platform: platform !== 'all' ? platform : undefined,
                    account_id: accountId || undefined,
                    source: append && source ? source : undefined,
                },
            });
            const incoming = data.posts || [];
            if (!append) previewedRef.current = new Set();
            setPosts((current) => (append ? mergePosts(current, incoming) : incoming));
            setHasMore(Boolean(data.pagination?.has_more && data.pagination?.next_cursor));
            setCursor(data.pagination?.next_cursor || null);
            if (data.meta?.source) setSource(data.meta.source);
        } catch (err) {
            setError(err.response?.data?.message || 'Could not load posts.');
            if (!append) setPosts([]);
        } finally {
            setLoading(false);
            setLoadingMore(false);
        }
    }, [accountId, platform, search, setError, source]);

    useEffect(() => {
        const timer = window.setTimeout(() => setSearch(searchInput.trim()), 350);
        return () => window.clearTimeout(timer);
    }, [searchInput]);

    useEffect(() => {
        setSelected(new Set());
        setSource(null);
        setCursor(null);
        previewedRef.current = new Set();
        fetchPage({ append: false });
        // Reset source when filters change so the first page can pick the best endpoint.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [accountId, platform, search]);

    // Lazy-load thumbnails + live metrics after the grid paints (keeps list request fast).
    useEffect(() => {
        const need = posts.filter((post) => {
            if (!post?.permalink || !post?.platform_post_id) return false;
            const key = postKey(post);
            if (previewedRef.current.has(key)) return false;
            return true;
        }).slice(0, PAGE_SIZE);

        if (need.length === 0) return undefined;

        need.forEach((post) => previewedRef.current.add(postKey(post)));
        let cancelled = false;

        (async () => {
            try {
                const { data } = await api.post('/posts/previews', {
                    items: need.map((post) => ({
                        platform_post_id: post.platform_post_id,
                        permalink: post.permalink,
                    })),
                });
                if (cancelled) return;
                const map = data.previews || {};
                setPosts((list) => list.map((post) => {
                    const preview = map[post.platform_post_id];
                    if (!preview) return post;
                    return {
                        ...post,
                        thumbnail: preview.thumbnail || post.thumbnail,
                        video_url: preview.video_url || post.video_url,
                        media_type: preview.media_type || post.media_type,
                        media: preview.media || post.media,
                        like_count: preview.like_count ?? post.like_count,
                        comments_count: preview.comments_count ?? post.comments_count,
                        share_count: preview.share_count ?? post.share_count,
                    };
                }));
            } catch {
                // Grid remains usable without enriched media.
            }
        })();

        return () => { cancelled = true; };
    }, [posts]);

    const openPostWorkflow = (post) => {
        if (post.workflow_id) {
            setView('workflows', { wf: post.workflow_id });
            return;
        }
        setView('workflows', {
            create: 'post_comment',
            account_id: post.account_id,
            platform_post_id: post.platform_post_id,
        });
    };

    const applyBulk = async ({ enable = true } = {}) => {
        const targets = selecting
            ? posts.filter((post) => selected.has(postKey(post)))
            : posts;
        if (targets.length === 0) return;
        setBulkBusy(true);
        try {
            if (!enable) {
                setView('workflows');
                return;
            }
            const { data } = await api.post('/workflows/use', {
                template_key: 'post_comment',
                activate: true,
                posts: targets.map((post) => ({
                    account_id: post.account_id,
                    platform_post_id: post.platform_post_id,
                })),
            });
            const workflow = data.workflow;
            setSelecting(false);
            setSelected(new Set());
            setView('workflows', { wf: workflow.id });
        } catch (err) {
            setError(err.response?.data?.message || 'Could not create post automation.');
        } finally {
            setBulkBusy(false);
        }
    };

    const toggleSelect = (post) => {
        const key = postKey(post);
        setSelected((current) => {
            const next = new Set(current);
            if (next.has(key)) next.delete(key);
            else next.add(key);
            return next;
        });
    };

    return (
        <div className="flex h-full min-h-0 flex-col bg-white">
            <div className="inbox-scroll min-h-0 flex-1 overflow-y-auto">
                <header className="border-b border-line px-4 py-3 sm:px-5">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h1 className="text-xl font-extrabold tracking-tight text-ink">Posts</h1>
                            <p className="mt-0.5 text-sm text-muted">Open a workflow to automate replies and DMs on specific posts.</p>
                        </div>
                        <div className="flex items-center gap-2">
                            {selecting ? (
                                <button type="button" onClick={() => { setSelecting(false); setSelected(new Set()); }} className="h-8 rounded-lg border border-line px-3 text-xs font-semibold text-ink hover:bg-bubble">
                                    Cancel
                                </button>
                            ) : (
                                <button type="button" onClick={() => setSelecting(true)} className="h-8 rounded-lg border border-line px-3 text-xs font-semibold text-ink hover:bg-bubble">
                                    Select
                                </button>
                            )}
                        </div>
                    </div>

                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <div className="min-w-[180px] flex-1">
                            <SearchField value={searchInput} onChange={setSearchInput} placeholder="Search captions…" />
                        </div>
                        {search && (
                            <button type="button" onClick={() => { setSearchInput(''); setSearch(''); }} className="inline-flex h-9 items-center gap-1 rounded-xl border border-line px-2 text-xs font-semibold text-muted hover:bg-bubble">
                                <X size={12} /> Clear
                            </button>
                        )}
                    </div>

                    <div className="mt-3 flex flex-wrap gap-1.5">
                        <Chip active={platform === 'all'} onClick={() => { setPlatform('all'); setAccountId(''); }}>All</Chip>
                        {platforms.map((row) => (
                            <Chip key={row.id} active={platform === row.id} color={row.color} onClick={() => { setPlatform(row.id); setAccountId(''); }}>
                                <row.Icon size={12} /> {row.label}
                            </Chip>
                        ))}
                    </div>
                    {visibleAccounts.length > 1 && (
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            <Chip active={!accountId} onClick={() => setAccountId('')}>All pages</Chip>
                            {visibleAccounts.map((account) => (
                                <Chip key={account.id} active={String(accountId) === String(account.id)} onClick={() => setAccountId(String(account.id))}>
                                    {account.name || account.username || `Page ${account.id}`}
                                </Chip>
                            ))}
                        </div>
                    )}
                </header>

                {selecting && (
                    <div className="flex flex-wrap items-center gap-2 border-b border-line bg-bubble/60 px-4 py-2 sm:px-5">
                        <span className="text-xs font-semibold text-ink">{selected.size || posts.length} selected</span>
                        <BulkButton disabled={bulkBusy} onClick={() => applyBulk({ enable: true })}>Automate selected</BulkButton>
                        <BulkButton disabled={bulkBusy} onClick={() => setView('workflows')}>Open workflows</BulkButton>
                    </div>
                )}

                <div className="px-4 py-4 sm:px-5">
                    {loading && (
                        <CardGridSkeleton
                            count={8}
                            media
                            cols="grid-cols-2 md:grid-cols-3 xl:grid-cols-4"
                        />
                    )}
                    {!loading && accounts.length === 0 && (
                        <EmptyState
                            title="No platforms linked"
                            body="Connect Instagram or Facebook in Channels to load posts."
                            action={can('settings.view') ? 'Connect a platform' : null}
                            onAction={() => setView('channels')}
                        />
                    )}
                    {!loading && accounts.length > 0 && posts.length === 0 && (
                        <EmptyState title="No posts yet" body={search ? 'No posts match that search.' : 'Published posts from linked pages will show here.'} />
                    )}
                    {!loading && posts.length > 0 && (
                        <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
                            {posts.map((post) => (
                                <PostCard
                                    key={postKey(post)}
                                    post={post}
                                    selecting={selecting}
                                    checked={selected.has(postKey(post))}
                                    onSelect={() => toggleSelect(post)}
                                    onOpenAiSettings={() => openPostWorkflow(post)}
                                />
                            ))}
                        </div>
                    )}

                    {!loading && hasMore && (
                        <div className="mt-5 flex justify-center">
                            <button
                                type="button"
                                onClick={() => fetchPage({ append: true, nextCursor: cursor })}
                                disabled={loadingMore}
                                className="inline-flex h-10 items-center gap-2 rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink hover:bg-bubble disabled:opacity-60"
                            >
                                {loadingMore ? <LoaderCircle size={14} className="animate-spin" /> : null}
                                Load more
                            </button>
                        </div>
                    )}
                    {loadingMore && <div className="mt-3 flex justify-center"><InlineSpinner label="Loading posts" /></div>}
                </div>
            </div>
        </div>
    );
}

function PostCard({ post, selecting, checked, onSelect, onOpenAiSettings }) {
    const meta = platformMeta(post.platform);
    const Icon = meta.Icon;
    const isVideo = isVideoPost(post);
    const [broken, setBroken] = useState(false);

    return (
        <article className={`overflow-hidden rounded-2xl border bg-white ${checked ? 'border-accent' : 'border-line'}`}>
            <div className="relative aspect-square bg-bubble">
                {post.thumbnail && !broken ? (
                    <img
                        src={post.thumbnail}
                        alt=""
                        className="h-full w-full object-cover"
                        loading="lazy"
                        onError={() => setBroken(true)}
                    />
                ) : (
                    <div className="flex h-full items-center justify-center text-muted">
                        <Images size={28} />
                    </div>
                )}
                {isVideo && (
                    <span className="pointer-events-none absolute inset-0 flex items-center justify-center">
                        <span className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-black/55 text-white">
                            <Play size={18} fill="currentColor" />
                        </span>
                    </span>
                )}
                <span className="absolute start-2 top-2 inline-flex items-center gap-1 rounded-md bg-black/55 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                    <Icon size={10} /> {meta.label}
                </span>
                {selecting && (
                    <button type="button" onClick={onSelect} className="absolute end-2 top-2 rounded-md bg-white/90 p-1 text-ink">
                        {checked ? <CheckSquare size={16} /> : <Square size={16} />}
                    </button>
                )}
                <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 via-black/45 to-transparent px-2 pb-2 pt-8">
                    <div className="flex items-center justify-between gap-2">
                        <CreativeToggle label="AI reply" on={Boolean(post.ai_comment_reply)} onChange={onOpenAiSettings} />
                        <CreativeToggle label="Auto DM" on={Boolean(post.ai_private_reply)} onChange={onOpenAiSettings} />
                    </div>
                </div>
            </div>
            <div className="space-y-1.5 p-2.5">
                <p className="line-clamp-2 min-h-8 text-xs text-ink">{post.caption || 'No caption'}</p>
                <div className="flex items-center gap-3 text-[11px] font-semibold text-muted">
                    <span className="inline-flex items-center gap-1" title="Reactions">
                        <Heart size={11} /> {formatCount(post.like_count)}
                    </span>
                    <span className="inline-flex items-center gap-1" title="Comments">
                        <MessageCircle size={11} /> {formatCount(post.comments_count)}
                    </span>
                </div>
            </div>
        </article>
    );
}

function CreativeToggle({ label, on, onChange }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={on}
            onClick={() => onChange()}
            className="inline-flex items-center gap-1.5 rounded-md bg-black/35 px-1.5 py-1 text-[10px] font-semibold text-white backdrop-blur-sm"
        >
            <span className={`relative h-3.5 w-6 rounded-full transition ${on ? 'bg-accent' : 'bg-white/35'}`}>
                <span className={`absolute top-0.5 h-2.5 w-2.5 rounded-full bg-white transition ${on ? 'start-3' : 'start-0.5'}`} />
            </span>
            {label}
        </button>
    );
}

function Chip({ active, onClick, children, color }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`inline-flex h-8 items-center gap-1 rounded-lg border px-2.5 text-xs font-semibold ${
                active ? 'border-ink bg-ink text-white' : 'border-line bg-white text-ink hover:bg-bubble'
            }`}
            style={active && color ? { borderColor: color, backgroundColor: color } : undefined}
        >
            {children}
        </button>
    );
}

function BulkButton({ children, onClick, disabled }) {
    return (
        <button type="button" disabled={disabled} onClick={onClick} className="h-7 rounded-lg border border-line bg-white px-2.5 text-[11px] font-semibold text-ink hover:bg-white disabled:opacity-50">
            {children}
        </button>
    );
}

function EmptyState({ title, body, action, onAction }) {
    return (
        <div className="mx-auto max-w-sm py-16 text-center">
            <Images size={28} className="mx-auto text-muted" />
            <h2 className="mt-3 text-base font-bold text-ink">{title}</h2>
            <p className="mt-1 text-sm text-muted">{body}</p>
            {action && (
                <button type="button" onClick={onAction} className="mt-4 h-9 rounded-lg bg-ink px-3 text-xs font-semibold text-white">
                    {action}
                </button>
            )}
        </div>
    );
}

function postKey(post) {
    return `${post.account_id}:${post.platform_post_id}`;
}

function samePost(a, b) {
    return String(a.account_id) === String(b.account_id) && String(a.platform_post_id) === String(b.platform_post_id);
}

function mergePosts(current, incoming) {
    const seen = new Set(current.map(postKey));
    const extra = incoming.filter((post) => !seen.has(postKey(post)));
    return [...current, ...extra];
}

function isVideoPost(post) {
    const kind = String(post?.media_type || '').toLowerCase();
    if (kind.includes('video') || kind.includes('reel')) return true;
    if (post?.video_url) return true;
    const media = Array.isArray(post?.media) ? post.media : [];
    return media.some((item) => String(item?.type || '').toLowerCase().includes('video'));
}

function formatCount(value) {
    const n = Number(value) || 0;
    if (n >= 1000000) return `${(n / 1000000).toFixed(1).replace(/\.0$/, '')}M`;
    if (n >= 1000) return `${(n / 1000).toFixed(1).replace(/\.0$/, '')}K`;
    return String(n);
}
