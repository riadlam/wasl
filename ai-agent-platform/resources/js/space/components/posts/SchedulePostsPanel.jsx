import { useCallback, useEffect, useMemo, useState } from 'react';
import { CalendarClock, ImageIcon, LoaderCircle, Plus, Trash2, Send, X } from 'lucide-react';
import { api } from '../../api';
import { useSpace } from '../../context';
import { platformMeta } from '../channels/platforms';
import { TableSkeleton } from '../inboxSkeletons';
import { FileDrop, TextArea, DateTimePicker } from '../form';
import Modal from '../modals/Modal';

/** Fallback caps when /scheduled-posts/limits is unavailable. */
const DEFAULT_LIMITS = {
    instagram: { max_media: 10, max_text: 2200 },
    facebook: { max_media: 10, max_text: 63206 },
    linkedin: { max_media: 20, max_text: 3000 },
    threads: { max_media: 20, max_text: 500 },
    tiktok: { max_media: 35, max_text: 2200 },
    youtube: { max_media: 1, max_text: 5000 },
    twitter: { max_media: 0, max_text: 280 },
    google: { max_media: 1, max_text: 1500 },
};

export default function SchedulePostsPanel() {
    const { socialAccounts, setView, can, setError } = useSpace();
    const [posts, setPosts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [modalOpen, setModalOpen] = useState(false);
    const [rescheduleId, setRescheduleId] = useState(null);
    const [caption, setCaption] = useState('');
    const [scheduledAt, setScheduledAt] = useState('');
    const [selectedAccounts, setSelectedAccounts] = useState(() => new Set());
    const [images, setImages] = useState([]);
    const [limits, setLimits] = useState(DEFAULT_LIMITS);
    const now = useNow(30_000);

    const accounts = useMemo(
        () => (socialAccounts || []).filter(
            (account) => account.platform
                && account.platform !== 'simulator'
                && account.status !== 'disconnected'
                && account.socialapi_account_id,
        ),
        [socialAccounts],
    );

    const selectedPlatforms = useMemo(() => {
        const platforms = [];
        for (const account of accounts) {
            if (selectedAccounts.has(String(account.id)) && account.platform) {
                platforms.push(account.platform);
            }
        }
        return [...new Set(platforms)];
    }, [accounts, selectedAccounts]);

    const maxMedia = useMemo(() => mediaCapFor(selectedPlatforms, limits), [selectedPlatforms, limits]);
    const maxText = useMemo(() => textCapFor(selectedPlatforms, limits), [selectedPlatforms, limits]);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await api.get('/scheduled-posts', {
                params: { status: 'scheduled', limit: 50 },
            });
            setPosts(data.posts || []);
        } catch (err) {
            setError(err.response?.data?.message || 'Could not load scheduled posts.');
            setPosts([]);
        } finally {
            setLoading(false);
        }
    }, [setError]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        let cancelled = false;
        api.get('/scheduled-posts/limits')
            .then(({ data }) => {
                if (!cancelled && data?.limits) setLimits({ ...DEFAULT_LIMITS, ...data.limits });
            })
            .catch(() => {});
        return () => { cancelled = true; };
    }, []);

    useEffect(() => {
        if (accounts.length === 0) return;
        setSelectedAccounts((current) => {
            if (current.size > 0) return current;
            return new Set(accounts.slice(0, 1).map((a) => String(a.id)));
        });
    }, [accounts]);

    useEffect(() => {
        setImages((current) => {
            if (current.length <= maxMedia) return current;
            const kept = current.slice(0, maxMedia);
            current.slice(maxMedia).forEach((item) => {
                if (item.preview?.startsWith('blob:')) URL.revokeObjectURL(item.preview);
            });
            return kept;
        });
    }, [maxMedia]);

    const resetForm = () => {
        images.forEach((item) => {
            if (item.preview?.startsWith('blob:')) URL.revokeObjectURL(item.preview);
        });
        setCaption('');
        setScheduledAt('');
        setImages([]);
        setRescheduleId(null);
        setModalOpen(false);
        setSelectedAccounts(new Set(accounts.slice(0, 1).map((a) => String(a.id))));
    };

    const openCreate = () => {
        setRescheduleId(null);
        setCaption('');
        setScheduledAt('');
        setImages([]);
        setSelectedAccounts(new Set(accounts.slice(0, 1).map((a) => String(a.id))));
        setModalOpen(true);
    };

    const startReschedule = (post) => {
        setRescheduleId(post.id);
        setCaption(post.text || '');
        setScheduledAt(toLocalInput(post.scheduled_at));
        const fromTargets = (post.targets || [])
            .map((t) => t.local_account_id)
            .filter(Boolean)
            .map(String);
        setSelectedAccounts(new Set(
            fromTargets.length
                ? fromTargets
                : accounts.slice(0, 1).map((a) => String(a.id)),
        ));
        setImages((post.media || []).map((item, index) => ({
            key: `existing-${item.media_id || item.source || index}`,
            media_id: item.media_id || (item.source_type === 'media_id' ? item.source : null),
            preview: item.url || (item.source_type === 'url' ? item.source : null),
            file: null,
        })));
        setModalOpen(true);
    };

    const toggleAccount = (id) => {
        setSelectedAccounts((current) => {
            const next = new Set(current);
            const key = String(id);
            if (next.has(key)) next.delete(key);
            else next.add(key);
            return next;
        });
    };

    const addFiles = (files) => {
        if (maxMedia <= 0) {
            setError('Images are not supported for the selected pages.');
            return;
        }
        const room = Math.max(0, maxMedia - images.length);
        if (room === 0) {
            setError('You have reached the image limit for the selected pages.');
            return;
        }
        const next = [...files].slice(0, room).map((file) => ({
            key: `${file.name}-${file.size}-${file.lastModified}-${Math.random()}`,
            file,
            media_id: null,
            preview: URL.createObjectURL(file),
        }));
        setImages((current) => [...current, ...next]);
        if (files.length > room) {
            setError('Some images were skipped because of the page limit.');
        }
    };

    const removeImage = (key) => {
        setImages((current) => {
            const target = current.find((item) => item.key === key);
            if (target?.preview?.startsWith('blob:')) URL.revokeObjectURL(target.preview);
            return current.filter((item) => item.key !== key);
        });
    };

    const resolveMediaIds = async () => {
        const mediaIds = [];
        for (const item of images) {
            if (item.media_id && !item.file) {
                mediaIds.push(item.media_id);
                continue;
            }
            if (!item.file) continue;
            const body = new FormData();
            body.append('image', item.file);
            const { data } = await api.post('/scheduled-posts/media', body);
            if (data.media_id) mediaIds.push(data.media_id);
        }
        return mediaIds;
    };

    const submit = async (event) => {
        event.preventDefault();
        if (!caption.trim() || !scheduledAt) {
            setError('Caption and schedule time are required.');
            return;
        }
        if (!rescheduleId && selectedAccounts.size === 0) {
            setError('Pick at least one page.');
            return;
        }
        if (caption.trim().length > maxText) {
            setError('Caption is too long for the selected pages.');
            return;
        }
        if (images.length > maxMedia) {
            setError('Too many images for the selected pages.');
            return;
        }

        setBusy(true);
        try {
            const mediaIds = await resolveMediaIds();
            if (rescheduleId) {
                await api.patch(`/scheduled-posts/${encodeURIComponent(rescheduleId)}`, {
                    text: caption.trim(),
                    scheduled_at: new Date(scheduledAt).toISOString(),
                    media_ids: mediaIds,
                });
            } else {
                await api.post('/scheduled-posts', {
                    text: caption.trim(),
                    scheduled_at: new Date(scheduledAt).toISOString(),
                    account_ids: [...selectedAccounts].map((id) => Number(id)),
                    media_ids: mediaIds,
                });
            }
            resetForm();
            await load();
        } catch (err) {
            setError(err.response?.data?.message || err.response?.data?.errors?.media_ids?.[0] || err.response?.data?.errors?.text?.[0] || 'Could not save scheduled post.');
        } finally {
            setBusy(false);
        }
    };

    const cancelPost = async (id) => {
        setBusy(true);
        try {
            await api.delete(`/scheduled-posts/${encodeURIComponent(id)}`);
            setPosts((list) => list.filter((row) => row.id !== id));
        } catch (err) {
            setError(err.response?.data?.message || 'Could not cancel scheduled post.');
        } finally {
            setBusy(false);
        }
    };

    const publishNow = async (id) => {
        setBusy(true);
        try {
            await api.post(`/scheduled-posts/${encodeURIComponent(id)}/publish`);
            await load();
        } catch (err) {
            setError(err.response?.data?.message || 'Could not publish post.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="inbox-scroll min-h-0 flex-1 overflow-y-auto">
                <header className="border-b border-line px-4 py-3 sm:px-5">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h1 className="text-xl font-extrabold tracking-tight text-ink">Schedule posts</h1>
                            <p className="mt-0.5 text-sm text-muted">Queue captions for linked pages. Wasl publishes at the set time.</p>
                        </div>
                        {accounts.length > 0 && (
                            <button
                                type="button"
                                onClick={openCreate}
                                className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-coral px-3.5 text-sm font-semibold text-white hover:bg-coral-dark"
                            >
                                <Plus size={14} /> Schedule
                            </button>
                        )}
                    </div>
                </header>

                <div className="px-4 py-4 sm:px-5">
                    {loading && <TableSkeleton rows={5} columns={3} />}

                    {!loading && accounts.length === 0 && (
                        <div className="mx-auto max-w-sm py-16 text-center">
                            <CalendarClock size={28} className="mx-auto text-muted" />
                            <h2 className="mt-3 text-base font-bold text-ink">No platforms linked</h2>
                            <p className="mt-1 text-sm text-muted">Connect Instagram or Facebook in Channels to schedule posts.</p>
                            {can('settings.view') && (
                                <button
                                    type="button"
                                    onClick={() => setView('channels')}
                                    className="mt-4 h-9 rounded-lg bg-ink px-3 text-xs font-semibold text-white"
                                >
                                    Connect a platform
                                </button>
                            )}
                        </div>
                    )}

                    {!loading && accounts.length > 0 && posts.length === 0 && (
                        <div className="mx-auto max-w-sm py-16 text-center">
                            <CalendarClock size={28} className="mx-auto text-muted" />
                            <h2 className="mt-3 text-base font-bold text-ink">Nothing scheduled</h2>
                            <p className="mt-1 text-sm text-muted">Queue a caption and pick when it should go live.</p>
                        </div>
                    )}

                    {!loading && posts.length > 0 && (
                        <ul className="space-y-3">
                            {posts.map((post) => {
                                const thumbs = (post.media || []).filter((m) => m.url);
                                return (
                                    <li key={post.id} className="rounded-2xl border border-line bg-white p-3.5">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <p className="text-[12px] font-semibold text-coral">{formatWhen(post.scheduled_at)}</p>
                                                    <CountdownBadge iso={post.scheduled_at} now={now} />
                                                </div>
                                                <p className="mt-1 whitespace-pre-wrap text-sm text-ink">{post.text || 'No caption'}</p>
                                                {thumbs.length > 0 && (
                                                    <div className="mt-2 flex gap-1.5 overflow-x-auto">
                                                        {thumbs.slice(0, 5).map((item, index) => (
                                                            <img
                                                                key={`${post.id}-m-${index}`}
                                                                src={item.url}
                                                                alt=""
                                                                className="h-12 w-12 shrink-0 rounded-lg border border-line object-cover"
                                                            />
                                                        ))}
                                                        {(post.media || []).length > thumbs.length && (
                                                            <span className="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-line bg-bubble text-[10px] font-semibold text-muted">
                                                                +{(post.media || []).length - thumbs.length}
                                                            </span>
                                                        )}
                                                    </div>
                                                )}
                                                {(post.media || []).length > 0 && thumbs.length === 0 && (
                                                    <p className="mt-2 inline-flex items-center gap-1 text-[11px] font-semibold text-muted">
                                                        <ImageIcon size={12} /> {(post.media || []).length} image{(post.media || []).length === 1 ? '' : 's'}
                                                    </p>
                                                )}
                                                <div className="mt-2 flex flex-wrap gap-1.5">
                                                    {(post.targets || []).map((target) => {
                                                        const meta = platformMeta(target.platform);
                                                        return (
                                                            <span
                                                                key={`${post.id}-${target.account_id}`}
                                                                className="inline-flex items-center gap-1 rounded-md bg-bubble px-1.5 py-0.5 text-[10px] font-semibold text-ink"
                                                            >
                                                                <meta.Icon size={10} style={{ color: meta.color }} />
                                                                {target.account_name || target.platform || 'Page'}
                                                            </span>
                                                        );
                                                    })}
                                                    <span className="rounded-md bg-bubble px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted">
                                                        {post.status}
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="flex shrink-0 flex-wrap gap-1.5">
                                                <button
                                                    type="button"
                                                    disabled={busy}
                                                    onClick={() => startReschedule(post)}
                                                    className="h-8 rounded-lg border border-line px-2.5 text-[11px] font-semibold text-ink hover:bg-bubble disabled:opacity-50"
                                                >
                                                    Reschedule
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled={busy}
                                                    onClick={() => publishNow(post.id)}
                                                    className="inline-flex h-8 items-center gap-1 rounded-lg border border-line px-2.5 text-[11px] font-semibold text-ink hover:bg-bubble disabled:opacity-50"
                                                >
                                                    <Send size={12} /> Now
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled={busy}
                                                    onClick={() => cancelPost(post.id)}
                                                    className="inline-flex h-8 items-center gap-1 rounded-lg border border-line px-2.5 text-[11px] font-semibold text-coral hover:bg-bubble disabled:opacity-50"
                                                >
                                                    <Trash2 size={12} /> Cancel
                                                </button>
                                            </div>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            </div>

            <ScheduleModal
                open={modalOpen}
                onClose={resetForm}
                rescheduleId={rescheduleId}
                caption={caption}
                setCaption={setCaption}
                scheduledAt={scheduledAt}
                setScheduledAt={setScheduledAt}
                accounts={accounts}
                selectedAccounts={selectedAccounts}
                toggleAccount={toggleAccount}
                images={images}
                addFiles={addFiles}
                removeImage={removeImage}
                maxMedia={maxMedia}
                maxText={maxText}
                busy={busy}
                onSubmit={submit}
            />
        </div>
    );
}

function ScheduleModal({
    open,
    onClose,
    rescheduleId,
    caption,
    setCaption,
    scheduledAt,
    setScheduledAt,
    accounts,
    selectedAccounts,
    toggleAccount,
    images,
    addFiles,
    removeImage,
    maxMedia,
    maxText,
    busy,
    onSubmit,
}) {
    const canEditImages = maxMedia > 0;
    const room = Math.max(0, maxMedia - images.length);

    return (
        <Modal open={open} size="2xl" title={rescheduleId ? 'Edit scheduled post' : 'Schedule post'} onClose={onClose}>
            <form className="space-y-5" onSubmit={onSubmit}>
                {!rescheduleId && (
                    <div>
                        <p className="mb-2 text-[12px] font-semibold text-muted">Pages</p>
                        <div className="flex flex-wrap gap-2">
                            {accounts.map((account) => {
                                const meta = platformMeta(account.platform);
                                const active = selectedAccounts.has(String(account.id));
                                return (
                                    <button
                                        key={account.id}
                                        type="button"
                                        onClick={() => toggleAccount(account.id)}
                                        className={`inline-flex h-9 items-center gap-1.5 rounded-xl border px-3 text-xs font-semibold transition ${
                                            active ? 'border-ink bg-ink text-white' : 'border-line bg-white text-ink hover:bg-bubble'
                                        }`}
                                    >
                                        <meta.Icon size={13} />
                                        {account.name || account.username || `Page ${account.id}`}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                )}

                <div>
                    <TextArea
                        label="Caption"
                        value={caption}
                        onChange={(e) => setCaption(e.target.value)}
                        placeholder="What should go out…"
                        rows={5}
                        maxLength={maxText > 0 ? maxText : undefined}
                    />
                </div>

                <DateTimePicker
                    label="Publish at"
                    value={scheduledAt}
                    onChange={setScheduledAt}
                    required
                    disabled={busy}
                    placeholder="Choose date & time"
                />

                {canEditImages && (
                    <div>
                        <p className="mb-2 text-[12px] font-semibold text-muted">
                            Images{images.length > 0 ? ` · ${images.length}` : ''}
                        </p>
                        {images.length > 0 && (
                            <div className="mb-3 grid grid-cols-3 gap-2.5 sm:grid-cols-4 md:grid-cols-5">
                                {images.map((item) => (
                                    <div key={item.key} className="relative aspect-square overflow-hidden rounded-xl border border-line bg-bubble">
                                        {item.preview ? (
                                            <img src={item.preview} alt="" className="h-full w-full object-cover" />
                                        ) : (
                                            <div className="flex h-full items-center justify-center text-muted">
                                                <ImageIcon size={20} />
                                            </div>
                                        )}
                                        <button
                                            type="button"
                                            onClick={() => removeImage(item.key)}
                                            className="absolute end-1.5 top-1.5 rounded-lg bg-ink/75 p-1 text-white"
                                            aria-label="Remove image"
                                        >
                                            <X size={12} />
                                        </button>
                                    </div>
                                ))}
                                {room > 0 && (
                                    <FileDrop compact onFiles={addFiles} multiple={room > 1} disabled={busy} className="aspect-square rounded-xl" />
                                )}
                            </div>
                        )}
                        {images.length === 0 && (
                            <FileDrop
                                onFiles={addFiles}
                                multiple={maxMedia > 1}
                                disabled={busy}
                                label="Drop images or click to add"
                                hint="JPG, PNG or WEBP"
                            />
                        )}
                    </div>
                )}

                <div className="flex justify-end gap-2 border-t border-line pt-5">
                    <button type="button" onClick={onClose} className="h-10 rounded-xl border border-line px-4 text-sm font-semibold hover:bg-bubble">
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={busy}
                        className="inline-flex h-10 items-center gap-1.5 rounded-xl bg-coral px-5 text-sm font-semibold text-white disabled:opacity-60"
                    >
                        {busy ? <LoaderCircle size={14} className="animate-spin" /> : <CalendarClock size={14} />}
                        {rescheduleId ? 'Save changes' : 'Schedule'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function CountdownBadge({ iso, now }) {
    const label = formatCountdown(iso, now);
    if (!label) return null;
    const overdue = label === 'Due now';
    return (
        <span className={`inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-semibold ${
            overdue ? 'bg-coral/15 text-coral' : 'bg-ink/5 text-ink'
        }`}>
            {label}
        </span>
    );
}

function useNow(intervalMs = 30_000) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now()), intervalMs);
        return () => window.clearInterval(id);
    }, [intervalMs]);
    return now;
}

function formatCountdown(iso, nowMs) {
    if (!iso) return '';
    const target = new Date(iso).getTime();
    if (Number.isNaN(target)) return '';
    const diff = target - nowMs;
    if (diff <= 0) return 'Due now';

    const totalMinutes = Math.floor(diff / 60_000);
    const days = Math.floor(totalMinutes / (60 * 24));
    const hours = Math.floor((totalMinutes % (60 * 24)) / 60);
    const minutes = totalMinutes % 60;

    const parts = [];
    if (days > 0) parts.push(`${days} day${days === 1 ? '' : 's'}`);
    if (hours > 0 || days > 0) parts.push(`${hours} hour${hours === 1 ? '' : 's'}`);
    parts.push(`${minutes} minute${minutes === 1 ? '' : 's'}`);
    return `${parts.join(', ')} left`;
}

function mediaCapFor(platforms, limits) {
    if (!platforms.length) return 10;
    return Math.min(...platforms.map((p) => limits[p]?.max_media ?? 10));
}

function textCapFor(platforms, limits) {
    if (!platforms.length) return 2200;
    return Math.min(...platforms.map((p) => limits[p]?.max_text ?? 2200));
}

function toLocalInput(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function formatWhen(iso) {
    if (!iso) return 'No time set';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return iso;
    return date.toLocaleString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
