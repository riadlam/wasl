import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, ArrowLeft, Check, ImagePlus, LoaderCircle, Pencil, RotateCcw, Save, X, XCircle } from 'lucide-react';
import { api } from '../../api';
import { useSpace } from '../../context';
import { queryKeys } from '../../query';
import { platformMeta } from '../channels/platforms';
import { walletErrorMessage } from './campaignDefaults';
import {
    CAMPAIGN_STATUS,
    LIVE_STATUSES,
    SLOT_SKELETON,
    SLOT_STATUS,
    StatusPill,
    formatDa,
    formatWhen,
} from './campaignStatus';

const LIVE_WITH_PAUSE = [...LIVE_STATUSES, 'paused_wallet'];

export default function CampaignDetail({ campaignId, onBack, initialEditSlotId = null, onDeepLinkConsumed }) {
    const { can, setError } = useSpace();
    const canManage = can('agents.manage');
    const queryClient = useQueryClient();
    const [busy, setBusy] = useState('');
    const [editSlot, setEditSlot] = useState(null);
    const openedDeepLink = useRef(false);

    const query = useQuery({
        queryKey: queryKeys.campaign(campaignId),
        queryFn: async () => (await api.get(`/agent/campaigns/${campaignId}`)).data?.campaign,
        refetchInterval: (q) => {
            const c = q.state.data;
            if (!c) return false;
            if (LIVE_WITH_PAUSE.includes(c.status)) return 8000;
            const slots = c.slots || [];
            if (slots.some((s) => SLOT_SKELETON.includes(s.status) || s.status === 'awaiting_approval')) {
                return 5000;
            }
            return false;
        },
    });
    const campaign = query.data;

    useEffect(() => {
        if (openedDeepLink.current || !initialEditSlotId || !campaign?.slots) return;
        const slot = campaign.slots.find((s) => s.id === initialEditSlotId && s.status === 'awaiting_approval');
        if (slot) {
            setEditSlot(slot);
            openedDeepLink.current = true;
            onDeepLinkConsumed?.();
        } else if (!query.isLoading) {
            openedDeepLink.current = true;
            onDeepLinkConsumed?.();
        }
    }, [campaign, initialEditSlotId, onDeepLinkConsumed, query.isLoading]);

    const days = useMemo(() => {
        const map = new Map();
        for (const slot of campaign?.slots || []) {
            if (!map.has(slot.day_index)) map.set(slot.day_index, []);
            map.get(slot.day_index).push(slot);
        }
        return [...map.entries()].sort((a, b) => a[0] - b[0]);
    }, [campaign]);

    const store = (next) => {
        if (next) queryClient.setQueryData(queryKeys.campaign(campaignId), next);
        queryClient.invalidateQueries({ queryKey: queryKeys.campaigns });
    };

    const onCancel = async () => {
        if (!window.confirm('Cancel this campaign? Posts already queued for the future will be removed from your channels.')) return;
        setBusy('cancel');
        setError('');
        try {
            const { data } = await api.post(`/agent/campaigns/${campaignId}/cancel`);
            store(data?.campaign);
        } catch (e) {
            setError(walletErrorMessage(e, 'Could not cancel the campaign.'));
        } finally {
            setBusy('');
        }
    };

    const onRetry = async (slotId) => {
        setBusy(`slot-${slotId}`);
        setError('');
        try {
            const { data } = await api.post(`/agent/campaigns/${campaignId}/slots/${slotId}/retry`);
            store(data?.campaign);
        } catch (e) {
            setError(walletErrorMessage(e, 'Could not retry this slot.'));
        } finally {
            setBusy('');
        }
    };

    const onSlotAction = async (slotId, action) => {
        setBusy(`slot-${action}-${slotId}`);
        setError('');
        try {
            const { data } = await api.post(`/agent/campaigns/${campaignId}/slots/${slotId}/${action}`);
            store(data?.campaign);
        } catch (e) {
            setError(walletErrorMessage(e, `Could not ${action} this slot.`));
        } finally {
            setBusy('');
        }
    };

    const onEditSave = async ({ caption, title, agentAssetId }) => {
        if (!editSlot) return;
        setBusy(`slot-edit-${editSlot.id}`);
        setError('');
        try {
            const { data } = await api.post(`/agent/campaigns/${campaignId}/slots/${editSlot.id}/edit`, {
                caption,
                title: title || null,
                agent_asset_id: agentAssetId ?? editSlot.agent_asset_id ?? null,
            });
            store(data?.campaign);
            const next = (data?.campaign?.slots || []).find((s) => s.id === editSlot.id);
            if (next?.status === 'awaiting_approval') {
                setEditSlot(next);
            } else {
                setEditSlot(null);
            }
            return true;
        } catch (e) {
            setError(walletErrorMessage(e, 'Could not save edits (Telegram was not updated).'));
            throw e;
        } finally {
            setBusy('');
        }
    };

    const onEditSubmit = async ({ caption, title, agentAssetId }) => {
        if (!editSlot) return;
        setBusy(`slot-edit-${editSlot.id}`);
        setError('');
        try {
            const { data } = await api.post(`/agent/campaigns/${campaignId}/slots/${editSlot.id}/edit-accept`, {
                caption,
                title: title || null,
                agent_asset_id: agentAssetId ?? editSlot.agent_asset_id ?? null,
            });
            store(data?.campaign);
            setEditSlot(null);
        } catch (e) {
            setError(walletErrorMessage(e, 'Could not save and accept this post.'));
        } finally {
            setBusy('');
        }
    };

    if (query.isLoading) {
        return (
            <div className="flex h-full items-center justify-center bg-white text-ink/40">
                <LoaderCircle size={18} className="animate-spin" />
            </div>
        );
    }

    if (!campaign) {
        return (
            <div className="h-full bg-white px-6 py-6">
                <BackLink onBack={onBack} />
                <p className="mt-4 text-[13px] text-ink/60">This campaign could not be loaded.</p>
            </div>
        );
    }

    const p = campaign.progress || {};
    const cancellable = !['cancelled', 'completed', 'failed'].includes(campaign.status);

    return (
        <div className="h-full overflow-y-auto bg-white">
            <div className="mx-auto w-full max-w-3xl px-4 py-5 sm:px-6 sm:py-6">
                <BackLink onBack={onBack} />
                <div className="mt-1 flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="cw-display truncate text-[1.35rem] font-semibold tracking-tight text-ink">
                            {campaign.name || `Campaign #${campaign.id}`}
                        </h1>
                        <div className="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-ink/50">
                            <StatusPill status={campaign.status} map={CAMPAIGN_STATUS} />
                            <span>{campaign.day_count} day{campaign.day_count === 1 ? '' : 's'} from {campaign.starts_on}</span>
                            {campaign.next_slot_at && LIVE_STATUSES.includes(campaign.status) && (
                                <span>Next: {formatWhen(campaign.next_slot_at)}</span>
                            )}
                        </div>
                    </div>
                    {canManage && cancellable && (
                        <button
                            type="button"
                            onClick={onCancel}
                            disabled={busy === 'cancel'}
                            className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-ink/12 px-3 text-[12px] font-medium text-ink/70 hover:bg-ink/5 disabled:opacity-50"
                        >
                            {busy === 'cancel' ? <LoaderCircle size={13} className="animate-spin" /> : <XCircle size={13} />}
                            Cancel campaign
                        </button>
                    )}
                </div>

                <div className="mt-4 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                    <Stat label="Scheduled" value={`${p.scheduled || 0}/${p.total || 0}`} />
                    <Stat label="Awaiting" value={p.awaiting_approval || 0} tone={p.awaiting_approval ? 'text-amber-800' : ''} />
                    <Stat label="Spent" value={formatDa(campaign.spent_da)} />
                    <Stat label="Estimate" value={`~${formatDa(campaign.estimated_da)}`} />
                </div>

                {campaign.status === 'paused_wallet' && (
                    <Notice>
                        The campaign is paused because the wallet balance is too low for the next post. It resumes
                        automatically within a few minutes of a top-up.
                    </Notice>
                )}

                {(campaign.warnings || []).map((w, i) => (
                    <Notice key={`${w.code || 'w'}-${i}`}>{w.message || w.code}</Notice>
                ))}

                <p className="mt-3 text-[11px] leading-relaxed text-ink/45">
                    Edit from Telegram opens this post. Save updates the Telegram card; Submit &amp; accept schedules it.
                    If the schedule time already passed when you approve, the slot is cancelled as delayed.
                </p>

                <div className="mt-5 space-y-4">
                    {days.map(([dayIndex, slots]) => (
                        <section key={dayIndex}>
                            <h2 className="text-[10px] font-semibold uppercase tracking-[0.1em] text-ink/40">Day {dayIndex}</h2>
                            <ul className="mt-2 space-y-2">
                                {slots.map((slot) => (
                                    <SlotRow
                                        key={slot.id}
                                        slot={slot}
                                        canManage={canManage && campaign.status !== 'cancelled'}
                                        busy={busy}
                                        onRetry={() => onRetry(slot.id)}
                                        onAccept={() => onSlotAction(slot.id, 'accept')}
                                        onCancelSlot={() => onSlotAction(slot.id, 'cancel')}
                                        onRegenerate={() => onSlotAction(slot.id, 'regenerate')}
                                        onEdit={() => setEditSlot(slot)}
                                    />
                                ))}
                            </ul>
                        </section>
                    ))}
                </div>
            </div>

            {editSlot && (
                <EditSlotModal
                    slot={editSlot}
                    busy={busy === `slot-edit-${editSlot.id}`}
                    onClose={() => !busy.startsWith('slot-edit-') && setEditSlot(null)}
                    onSave={onEditSave}
                    onSubmit={onEditSubmit}
                />
            )}
        </div>
    );
}

function SlotRow({ slot, canManage, busy, onRetry, onAccept, onCancelSlot, onRegenerate, onEdit }) {
    const targets = slot.targets || [];
    const retryable = slot.status === 'failed' || targets.some((t) => t.status === 'failed');
    const skeleton = SLOT_SKELETON.includes(slot.status);
    const awaiting = slot.status === 'awaiting_approval';
    const actionBusy = String(busy || '').includes(`slot-`) && String(busy).includes(String(slot.id));
    const pastSchedule = awaiting && slot.scheduled_at && new Date(slot.scheduled_at).getTime() <= Date.now();

    return (
        <li className="rounded-xl border border-ink/8 px-3.5 py-3">
            <div className="flex items-start gap-3">
                {slot.image_url ? (
                    <img
                        src={slot.image_url}
                        alt=""
                        className={`${slot.slot_kind === 'story' ? 'h-16 w-9' : 'h-12 w-12'} shrink-0 rounded-md object-cover ring-1 ring-ink/10`}
                    />
                ) : skeleton ? (
                    <div
                        className={`${slot.slot_kind === 'story' ? 'h-16 w-9' : 'h-12 w-12'} shrink-0 animate-pulse rounded-md bg-ink/8`}
                    />
                ) : (
                    <div className={`${slot.slot_kind === 'story' ? 'h-16 w-9' : 'h-12 w-12'} shrink-0 rounded-md bg-cream`} />
                )}
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-[12px] font-semibold capitalize text-ink">{slot.slot_kind}</span>
                        <span className="text-[11px] text-ink/45">{formatWhen(slot.scheduled_at)}</span>
                        <StatusPill status={slot.status} map={SLOT_STATUS} />
                        {pastSchedule && (
                            <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800">
                                Schedule passed
                            </span>
                        )}
                        {slot.cost_da > 0 && <span className="text-[10px] tabular-nums text-ink/40">{formatDa(slot.cost_da)}</span>}
                    </div>
                    {skeleton && !slot.caption ? (
                        <div className="mt-2 space-y-1.5">
                            {slot.title ? (
                                <p className="text-[12px] font-semibold text-ink">
                                    <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-ink/40">Planned idea · </span>
                                    {slot.title}
                                    {slot.content_pillar && (
                                        <span className="ml-2 rounded bg-ink/5 px-1.5 py-0.5 text-[10px] font-medium text-ink/50">
                                            {slot.content_pillar}
                                        </span>
                                    )}
                                </p>
                            ) : (
                                <>
                                    <div className="h-3 w-4/5 max-w-[18rem] animate-pulse rounded bg-ink/8" />
                                    <div className="h-3 w-3/5 max-w-[12rem] animate-pulse rounded bg-ink/6" />
                                </>
                            )}
                            <p className="pt-0.5 text-[11px] text-ink/40">
                                {slot.status === 'generating' || slot.status === 'regen_requested'
                                    ? 'Writing caption and creative…'
                                    : slot.title
                                      ? 'Queued — this idea is locked; caption drafts next.'
                                      : 'Queued — generation starts a few hours before publish time.'}
                            </p>
                        </div>
                    ) : (
                        <>
                            {slot.title ? (
                                <p className="mt-1 text-[12px] font-semibold text-ink">
                                    <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-ink/40">Idea · </span>
                                    {slot.title}
                                    {slot.content_pillar && (
                                        <span className="ml-2 rounded bg-ink/5 px-1.5 py-0.5 text-[10px] font-medium text-ink/50">
                                            {slot.content_pillar}
                                        </span>
                                    )}
                                </p>
                            ) : null}
                            {slot.caption ? (
                                <p className={`line-clamp-3 whitespace-pre-wrap text-[12px] leading-relaxed text-ink/70 ${slot.title ? 'mt-1' : 'mt-1'}`}>{slot.caption}</p>
                            ) : (
                                slot.status === 'pending' && (
                                    <p className="mt-1 text-[11px] text-ink/40">Written a few hours before it goes out.</p>
                                )
                            )}
                        </>
                    )}
                    {slot.error && <p className="mt-1 text-[11px] leading-relaxed text-red-700">{slot.error}</p>}
                    {targets.length > 0 && (
                        <ul className="mt-2 flex flex-wrap gap-1.5">
                            {targets.map((t) => {
                                const meta = platformMeta(t.platform);
                                const Icon = meta.Icon;
                                return (
                                    <li
                                        key={t.id}
                                        title={t.error || ''}
                                        className="inline-flex items-center gap-1.5 rounded-md bg-cream px-2 py-1 text-[11px]"
                                    >
                                        <Icon size={12} style={{ color: meta.color }} />
                                        <span className="max-w-[9rem] truncate text-ink/75">{t.channel_name || meta.label || t.platform}</span>
                                        <StatusPill status={t.status} map={SLOT_STATUS} />
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    {canManage && awaiting && (
                        <div className="mt-2.5 flex flex-wrap gap-1.5">
                            <button
                                type="button"
                                onClick={onAccept}
                                disabled={actionBusy}
                                className="inline-flex h-7 items-center gap-1 rounded-md bg-ink px-2.5 text-[11px] font-semibold text-white disabled:opacity-50"
                            >
                                {busy === `slot-accept-${slot.id}` ? <LoaderCircle size={12} className="animate-spin" /> : <Check size={12} />}
                                Accept
                            </button>
                            <button
                                type="button"
                                onClick={onEdit}
                                disabled={actionBusy}
                                className="inline-flex h-7 items-center gap-1 rounded-md border border-ink/12 px-2.5 text-[11px] font-medium text-ink/70 disabled:opacity-50"
                            >
                                <Pencil size={12} />
                                Edit
                            </button>
                            <button
                                type="button"
                                onClick={onRegenerate}
                                disabled={actionBusy}
                                className="inline-flex h-7 items-center gap-1 rounded-md border border-ink/12 px-2.5 text-[11px] font-medium text-ink/70 disabled:opacity-50"
                            >
                                {busy === `slot-regenerate-${slot.id}` ? <LoaderCircle size={12} className="animate-spin" /> : <RotateCcw size={12} />}
                                Regenerate
                            </button>
                            <button
                                type="button"
                                onClick={onCancelSlot}
                                disabled={actionBusy}
                                className="inline-flex h-7 items-center gap-1 rounded-md border border-ink/12 px-2.5 text-[11px] font-medium text-ink/55 disabled:opacity-50"
                            >
                                {busy === `slot-cancel-${slot.id}` ? <LoaderCircle size={12} className="animate-spin" /> : <XCircle size={12} />}
                                Cancel
                            </button>
                        </div>
                    )}
                </div>
                {canManage && retryable && (
                    <button
                        type="button"
                        onClick={onRetry}
                        disabled={busy === `slot-${slot.id}`}
                        className="inline-flex h-7 shrink-0 items-center gap-1 rounded-md bg-ink px-2.5 text-[11px] font-semibold text-white disabled:opacity-50"
                    >
                        {busy === `slot-${slot.id}` ? <LoaderCircle size={12} className="animate-spin" /> : <RotateCcw size={12} />}
                        Retry
                    </button>
                )}
            </div>
        </li>
    );
}

function EditSlotModal({ slot, busy, onClose, onSave, onSubmit }) {
    const fileRef = useRef(null);
    const [caption, setCaption] = useState(slot.caption || '');
    const [title, setTitle] = useState(slot.title || '');
    const [previewUrl, setPreviewUrl] = useState(slot.image_url || '');
    const [assetId, setAssetId] = useState(slot.agent_asset_id || null);
    const [uploading, setUploading] = useState(false);
    const [localError, setLocalError] = useState('');
    const [savedNote, setSavedNote] = useState('');
    const pastSchedule = slot.scheduled_at && new Date(slot.scheduled_at).getTime() <= Date.now();

    useEffect(() => {
        setCaption(slot.caption || '');
        setTitle(slot.title || '');
        setPreviewUrl(slot.image_url || '');
        setAssetId(slot.agent_asset_id || null);
    }, [slot.id, slot.caption, slot.title, slot.image_url, slot.agent_asset_id]);

    const onPickImage = async (file) => {
        if (!file) return;
        setLocalError('');
        setSavedNote('');
        setUploading(true);
        try {
            const body = new FormData();
            body.append('file', file);
            const { data } = await api.post('/agent/assets', body, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            if (!data?.asset?.id) throw new Error('Upload failed');
            setAssetId(data.asset.id);
            setPreviewUrl(data.asset.url || URL.createObjectURL(file));
        } catch (e) {
            setLocalError(walletErrorMessage(e, 'Could not upload the image.'));
        } finally {
            setUploading(false);
        }
    };

    const payload = () => {
        const text = caption.trim();
        if (!text) {
            setLocalError('Caption is required.');
            return null;
        }
        return { caption: text, title: title.trim(), agentAssetId: assetId };
    };

    const save = async () => {
        const body = payload();
        if (!body) return;
        setLocalError('');
        setSavedNote('');
        try {
            await onSave(body);
            setSavedNote('Saved — Telegram approval card updated.');
        } catch {
            setLocalError('Could not save. Try again.');
        }
    };

    const submit = () => {
        const body = payload();
        if (!body) return;
        setLocalError('');
        setSavedNote('');
        onSubmit(body);
    };

    return createPortal(
        <div className="fixed inset-0 z-[80] flex items-end justify-center bg-ink/40 p-3 sm:items-center" role="dialog" aria-modal="true">
            <div className="flex max-h-[92vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl bg-white shadow-xl">
                <div className="flex items-center justify-between border-b border-ink/8 px-4 py-3">
                    <div>
                        <p className="text-[13px] font-semibold text-ink">Edit post</p>
                        <p className="text-[11px] text-ink/45">
                            {slot.slot_kind} · {formatWhen(slot.scheduled_at)}
                        </p>
                    </div>
                    <button type="button" onClick={onClose} disabled={busy} className="rounded-lg p-1.5 text-ink/50 hover:bg-ink/5">
                        <X size={16} />
                    </button>
                </div>
                <div className="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-3">
                    {pastSchedule && (
                        <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-[12px] leading-relaxed text-amber-900">
                            Schedule time already passed. Submit &amp; accept will <strong>cancel</strong> this post as delayed — it will not publish late.
                        </div>
                    )}
                    <label className="block">
                        <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-ink/40">Idea (optional)</span>
                        <input
                            value={title}
                            onChange={(e) => { setTitle(e.target.value); setSavedNote(''); }}
                            className="mt-1 w-full rounded-lg border border-ink/10 bg-cream px-3 py-2 text-[13px] text-ink focus:outline-none focus:ring-1 focus:ring-ink/15"
                        />
                    </label>
                    <label className="block">
                        <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-ink/40">Caption</span>
                        <textarea
                            value={caption}
                            onChange={(e) => { setCaption(e.target.value); setSavedNote(''); }}
                            rows={8}
                            className="mt-1 w-full resize-y rounded-lg border border-ink/10 bg-cream px-3 py-2 text-[13px] leading-relaxed text-ink focus:outline-none focus:ring-1 focus:ring-ink/15"
                        />
                    </label>
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-ink/40">Post image</p>
                        <div className="mt-1.5 flex items-start gap-3">
                            {previewUrl ? (
                                <img src={previewUrl} alt="" className="h-20 w-20 rounded-lg object-cover ring-1 ring-ink/10" />
                            ) : (
                                <div className="flex h-20 w-20 items-center justify-center rounded-lg bg-cream text-ink/30">
                                    <ImagePlus size={18} />
                                </div>
                            )}
                            <div className="min-w-0 flex-1">
                                <input
                                    ref={fileRef}
                                    type="file"
                                    accept="image/*"
                                    className="hidden"
                                    onChange={(e) => onPickImage(e.target.files?.[0])}
                                />
                                <button
                                    type="button"
                                    disabled={busy || uploading}
                                    onClick={() => fileRef.current?.click()}
                                    className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-ink/12 px-3 text-[12px] font-medium text-ink/70 disabled:opacity-50"
                                >
                                    {uploading ? <LoaderCircle size={13} className="animate-spin" /> : <ImagePlus size={13} />}
                                    {previewUrl ? 'Replace image' : 'Upload image'}
                                </button>
                                <p className="mt-1 text-[11px] text-ink/40">
                                    Save syncs caption/image to Telegram. Submit &amp; accept schedules (or cancels if late).
                                </p>
                            </div>
                        </div>
                    </div>
                    {localError && <p className="text-[12px] font-medium text-coral" role="alert">{localError}</p>}
                    {savedNote && !localError && <p className="text-[12px] font-medium text-teal-dark">{savedNote}</p>}
                </div>
                <div className="flex flex-wrap items-center justify-end gap-2 border-t border-ink/8 px-4 py-3">
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={busy}
                        className="h-9 rounded-lg px-3 text-[13px] font-medium text-ink/55 hover:bg-ink/5 disabled:opacity-50"
                    >
                        Close
                    </button>
                    <button
                        type="button"
                        onClick={save}
                        disabled={busy || uploading || !caption.trim()}
                        className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-ink/12 px-3 text-[13px] font-semibold text-ink disabled:opacity-45"
                    >
                        {busy ? <LoaderCircle size={14} className="animate-spin" /> : <Save size={14} />}
                        Save
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={busy || uploading || !caption.trim()}
                        className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-ink px-4 text-[13px] font-semibold text-white disabled:opacity-45"
                    >
                        {busy ? <LoaderCircle size={14} className="animate-spin" /> : <Check size={14} />}
                        {pastSchedule ? 'Submit (will cancel — late)' : 'Submit & accept'}
                    </button>
                </div>
            </div>
        </div>,
        document.body,
    );
}

function Stat({ label, value, tone = '' }) {
    return (
        <div className="rounded-xl border border-ink/8 px-3 py-2.5">
            <p className="text-[10px] font-semibold uppercase tracking-[0.1em] text-ink/40">{label}</p>
            <p className={`mt-0.5 text-[15px] font-semibold tabular-nums text-ink ${tone}`}>{value}</p>
        </div>
    );
}

function Notice({ children }) {
    return (
        <div className="mt-3 flex gap-2 rounded-xl border border-amber-200/80 bg-amber-50 px-3 py-2.5 text-[12px] leading-relaxed text-amber-900">
            <AlertTriangle size={14} className="mt-0.5 shrink-0" />
            <div>{children}</div>
        </div>
    );
}

function BackLink({ onBack }) {
    return (
        <button
            type="button"
            onClick={onBack}
            className="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-coral hover:underline"
        >
            <ArrowLeft size={12} />
            Campaigns
        </button>
    );
}
