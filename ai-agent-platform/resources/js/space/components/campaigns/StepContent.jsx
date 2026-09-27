import { useCallback, useEffect, useImperativeHandle, useRef, useState, forwardRef } from 'react';
import { createPortal } from 'react-dom';
import { useDropzone } from 'react-dropzone';
import { ImagePlus, LoaderCircle, Sparkles, Wand2, X, ArrowLeft, Check, Ban } from 'lucide-react';
import { api } from '../../api';
import {
    CAMPAIGN_TEASE_AGENTS,
    CONTENT_MODES,
    MIN_PROMPT_LEN,
    campaignPayload,
    campaignTotals,
    imageSizeAspectClass,
    minImagesForPosts,
    walletErrorMessage,
} from './campaignDefaults';

async function uploadLocalImages(images) {
    const next = [];
    for (const row of images || []) {
        if (row.assetId) {
            next.push(row);
            continue;
        }
        if (!row.file) {
            next.push(row);
            continue;
        }
        const body = new FormData();
        body.append('file', row.file);
        const { data } = await api.post('/agent/assets', body, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        const asset = data?.asset;
        if (!asset?.id) {
            throw new Error('Upload failed');
        }
        if (row.url?.startsWith('blob:')) {
            URL.revokeObjectURL(row.url);
        }
        next.push({
            ...row,
            id: asset.id,
            assetId: asset.id,
            url: asset.url || row.url,
            name: asset.original_name || row.name,
            file: null,
        });
    }
    return next;
}

/** Caption body without a trailing hashtag line; hashtags shown once. */
function splitCaptionAndTags(caption, hashtags) {
    const raw = String(caption || '');
    const tags = Array.isArray(hashtags) ? hashtags.filter(Boolean) : [];
    const captionHasHash = /#\w/u.test(raw);
    if (captionHasHash) {
        const lines = raw.split('\n');
        const body = [];
        const fromCaption = [];
        for (const line of lines) {
            const trimmed = line.trim();
            if (/^#[\w\u0600-\u06FF]+(?:\s+#[\w\u0600-\u06FF]+)*$/u.test(trimmed)) {
                fromCaption.push(...trimmed.split(/\s+/).filter((t) => t.startsWith('#')));
            } else {
                body.push(line);
            }
        }
        return {
            body: body.join('\n').trimEnd(),
            tags: fromCaption.length > 0 ? fromCaption : tags,
        };
    }
    return { body: raw, tags };
}

/** Prefer RTL when Arabic script dominates — keeps Maghreb bubbles readable with Latin product names. */
function textDirection(text) {
    const s = String(text || '');
    const ar = (s.match(/[\u0600-\u06FF]/gu) || []).join('').length;
    const lat = (s.match(/[A-Za-z]/g) || []).join('').length;
    if (ar === 0 && lat === 0) return 'auto';
    return ar >= lat ? 'rtl' : 'ltr';
}

export default forwardRef(function StepContent({ state, onChange, errors, onBriefComplete }, ref) {
    const mode = state.contentMode;
    const totals = campaignTotals(state.days);
    const minImages = minImagesForPosts(totals.posts);
    const modes = [CONTENT_MODES.ai_recent, CONTENT_MODES.product_images];
    const activeMeta = CONTENT_MODES[mode] || CONTENT_MODES.ai_recent;
    const promptLen = state.focusPrompt?.trim().length || 0;
    const promptOk = promptLen >= MIN_PROMPT_LEN;
    const imageCount = (state.images || []).length;
    const canGenerate = mode === 'ai_recent' ? promptOk : imageCount >= 1;
    const [generating, setGenerating] = useState(false);
    const [briefing, setBriefing] = useState(false);
    const [genError, setGenError] = useState('');
    const [modalOpen, setModalOpen] = useState(false);
    const [modalPhase, setModalPhase] = useState('brief'); // brief | preview
    const [briefMessages, setBriefMessages] = useState([]);
    const [briefStack, setBriefStack] = useState([]);
    const [briefReady, setBriefReady] = useState(false);
    const [briefAgents, setBriefAgents] = useState(null);
    const [understanding, setUnderstanding] = useState('');
    const [awaitingAccept, setAwaitingAccept] = useState(false);
    const [confirmCards, setConfirmCards] = useState([]);
    /** @type {[Record<string, 'confirm'|'deny'>, Function]} */
    const [cardChoices, setCardChoices] = useState({});
    const [imagePolling, setImagePolling] = useState(false);
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;
    const previewRef = useRef(state.examplePreview);
    previewRef.current = state.examplePreview;
    const threadRef = useRef(null);

    const applyBriefResponse = (data) => {
        setBriefMessages(data.messages || []);
        setBriefReady(Boolean(data.ready));
        setAwaitingAccept(Boolean(data.awaiting_accept ?? data.ready));
        if (data.agents) setBriefAgents(data.agents);
        if (data.understanding) setUnderstanding(String(data.understanding));
        const cards = (Array.isArray(data.confirm_cards) ? data.confirm_cards : [])
            .filter((c) => c && (c.group === 'process' || !c.group))
            .map((c, i) => ({
                ...c,
                id: String(c.id || `process_${i + 1}`),
                group: 'process',
                text: String(c.text || '').trim(),
            }))
            .filter((c) => c.text);
        setConfirmCards(cards);
        setCardChoices({});
        if (data.product_focus || data.understanding || data.image_analyses || cards.length) {
            onChange({
                planMeta: {
                    ...(state.planMeta || {}),
                    understanding: data.understanding || understanding || null,
                    product_focus: data.product_focus || null,
                    image_analyses: data.image_analyses || [],
                    confirm_cards: cards,
                },
            });
        }
    };

    const setCardChoice = (id, choice) => {
        const key = String(id || '');
        if (!key) return;
        setCardChoices((prev) => ({ ...prev, [key]: choice }));
        setGenError('');
    };

    const notesFromCards = () => {
        const processOn = [];
        const processOff = [];
        confirmCards.forEach((c) => {
            const choice = cardChoices[String(c.id)];
            if (!choice) return;
            if (choice === 'confirm') processOn.push(c.text);
            else processOff.push(c.text);
        });
        const parts = [];
        if (understanding) {
            parts.push(`UNDERSTANDING (trusted — do not re-ask):\n${understanding}`);
        }
        if (processOn.length) parts.push(`PROCESS YES:\n- ${processOn.join('\n- ')}`);
        if (processOff.length) parts.push(`PROCESS NO:\n- ${processOff.join('\n- ')}`);
        return parts.join('\n\n');
    };

    const answeredCount = confirmCards.filter(
        (c) => cardChoices[String(c.id)] === 'confirm' || cardChoices[String(c.id)] === 'deny',
    ).length;

    const processCardsDecided = confirmCards.length > 0
        && confirmCards.every(
            (c) => cardChoices[String(c.id)] === 'confirm' || cardChoices[String(c.id)] === 'deny',
        );

    // Vision is auto-trusted; with process cards, every card must be answered first.
    const canGenerateFromCards = awaitingAccept
        && (confirmCards.length === 0 || processCardsDecided);

    const prepareAssets = async () => {
        let images = state.images || [];
        let assetIds = images.map((row) => row.assetId).filter(Boolean);
        if (mode === 'product_images') {
            images = await uploadLocalImages(images);
            assetIds = images.map((row) => row.assetId).filter(Boolean);
            onChange({ images });
        }
        return { images, assetIds, payload: campaignPayload({ ...state, images }, assetIds) };
    };

    const startBrief = async () => {
        setGenError('');
        if (mode === 'ai_recent' && !promptOk) {
            setGenError(`Add a focus prompt of at least ${MIN_PROMPT_LEN} characters first.`);
            return;
        }
        if (mode === 'product_images' && imageCount < 1) {
            setGenError('Upload at least one product image first.');
            return;
        }
        setBriefing(true);
        setModalPhase('brief');
        setBriefMessages([]);
        setBriefStack([]);
        setBriefReady(false);
        setAwaitingAccept(false);
        setUnderstanding('');
        setConfirmCards([]);
        setCardChoices({});
        setBriefAgents(null);
        setModalOpen(true);
        try {
            const { payload } = await prepareAssets();
            const { data } = await api.post('/agent/campaigns/example/brief', { ...payload, messages: [] });
            applyBriefResponse(data);
        } catch (e) {
            setGenError(walletErrorMessage(e, 'Could not start the briefing. Try again.'));
            setModalOpen(false);
        } finally {
            setBriefing(false);
        }
    };

    const onBriefPrev = () => {
        setGenError('');
        if (modalPhase === 'preview') {
            setModalPhase('brief');
            return;
        }
        if (briefStack.length === 0) return;
        const prev = briefStack[briefStack.length - 1];
        setBriefStack((stack) => stack.slice(0, -1));
        setBriefMessages(prev);
        setBriefReady(false);
        setAwaitingAccept(false);
    };

    const acceptUnderstanding = () => {
        if (generating || briefing) return;
        if (!canGenerateFromCards) {
            setGenError('Answer each post decision card first (Confirm or Deny).');
            return;
        }
        completeBrief();
    };

    /** Save brief + process decisions — no sample tease. Required before Continue / Launch. */
    const completeBrief = () => {
        if (generating || briefing) return;
        if (!canGenerateFromCards) {
            setGenError('Answer each post decision card first (Confirm or Deny).');
            return;
        }
        const fromCards = notesFromCards();
        const accepted = fromCards
            || understanding
            || briefMessages.filter((m) => m.role === 'assistant').slice(-1)[0]?.content
            || '';
        onChange({
            briefComplete: true,
            briefNotes: accepted,
            planMeta: {
                ...(state.planMeta || {}),
                understanding: accepted || understanding || null,
                confirm_cards: confirmCards,
                card_choices: cardChoices,
                brief_notes: accepted,
            },
        });
        setAwaitingAccept(false);
        setModalOpen(false);
        setModalPhase('brief');
        onBriefComplete?.();
    };

    const generateExample = async (messagesForBrief = briefMessages) => {
        setGenError('');
        if (!canGenerateFromCards && awaitingAccept) {
            setGenError('Answer each post decision card first (Confirm or Deny).');
            return;
        }
        setGenerating(true);
        try {
            const { images, assetIds, payload } = await prepareAssets();
            const fromCards = notesFromCards();
            const accepted = fromCards
                || understanding
                || messagesForBrief.filter((m) => m.role === 'assistant').slice(-1)[0]?.content
                || '';
            // Persist brief even if tease fails mid-way.
            onChange({
                briefComplete: true,
                briefNotes: accepted,
                planMeta: {
                    ...(state.planMeta || {}),
                    understanding: accepted,
                    confirm_cards: confirmCards,
                    card_choices: cardChoices,
                    brief_notes: accepted,
                },
            });
            const { data } = await api.post('/agent/campaigns/example', {
                ...payload,
                brief_messages: messagesForBrief,
                brief_notes: accepted,
                plan_meta: {
                    ...(state.planMeta || {}),
                    understanding: accepted,
                    confirm_cards: confirmCards,
                    card_choices: cardChoices,
                    tease_caption: null,
                },
            }, { timeout: 180000 });
            const agentMeta = data.agents || briefAgents;
            const approver = data.approver || null;
            onChange({
                images,
                briefComplete: true,
                briefNotes: accepted,
                planMeta: {
                    ...(state.planMeta || {}),
                    ...(data.plan_meta || {}),
                    understanding: accepted,
                    confirm_cards: confirmCards,
                    card_choices: cardChoices,
                    brief_notes: accepted,
                    tease_caption: data.caption || null,
                },
                examplePreview: {
                    title: data.title || '',
                    caption: data.caption,
                    hashtags: data.hashtags || [],
                    meta: data.meta || 'Tease of what you will get across the campaign days.',
                    image_url: data.image_url || null,
                    image_size: data.image_size || 'square_hd',
                    platform: data.platform || null,
                    image_job_id: data.image_job_id || null,
                    image_error: data.image_error || null,
                    agents: agentMeta,
                    approver,
                    plan_meta: {
                        ...(state.planMeta || {}),
                        ...(data.plan_meta || {}),
                        understanding: accepted,
                        confirm_cards: confirmCards,
                        card_choices: cardChoices,
                        brief_notes: accepted,
                        tease_caption: data.caption || null,
                    },
                },
            });
            if (agentMeta) setBriefAgents(agentMeta);
            setModalPhase('preview');
            setModalOpen(true);
            setAwaitingAccept(false);
            // Brief is saved; wizard Continue advances after the optional tease modal is closed.
        } catch (e) {
            setGenError(walletErrorMessage(e, 'Could not generate an example. Try again.'));
        } finally {
            setGenerating(false);
        }
    };

    useImperativeHandle(ref, () => ({
        openBrief: () => startBrief(),
        isBriefComplete: () => Boolean(state.briefComplete),
    }), [state.briefComplete, mode, promptOk, imageCount]);

    const onDrop = useCallback((accepted) => {
        if (!accepted?.length) return;
        const added = accepted.map((file) => ({
            id: `local-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
            url: URL.createObjectURL(file),
            name: file.name,
            file,
        }));
        onChange({ images: [...(state.images || []), ...added], briefComplete: false });
    }, [onChange, state.images]);

    const { getRootProps, getInputProps, isDragActive } = useDropzone({
        onDrop,
        accept: { 'image/*': ['.png', '.jpg', '.jpeg', '.webp'] },
        disabled: mode !== 'product_images',
    });

    const removeImage = (id) => {
        onChange({
            briefComplete: false,
            images: (state.images || []).filter((row) => {
                if (row.id === id && row.url?.startsWith('blob:')) {
                    URL.revokeObjectURL(row.url);
                }
                return row.id !== id;
            }),
        });
    };

    const busy = generating || briefing;

    const preview = state.examplePreview;
    const jobId = preview?.image_job_id;
    const needsPoll = Boolean(modalOpen && jobId && !preview?.image_url && !preview?.image_error);
    const previewSplit = splitCaptionAndTags(preview?.caption, preview?.hashtags);

    useEffect(() => {
        if (!needsPoll) {
            setImagePolling(false);
            return undefined;
        }
        let cancelled = false;
        setImagePolling(true);
        const tick = async () => {
            try {
                const { data } = await api.get(`/agent/image-jobs/${jobId}`);
                const job = data?.job;
                if (cancelled || !job) return;
                const current = previewRef.current || {};
                if (job.status === 'completed' && job.image_url) {
                    onChangeRef.current({
                        examplePreview: {
                            ...current,
                            image_url: job.image_url,
                            image_size: job.image_size || current.image_size,
                            image_job_id: null,
                            image_error: null,
                        },
                    });
                    setImagePolling(false);
                    return;
                }
                if (job.status === 'failed') {
                    onChangeRef.current({
                        examplePreview: {
                            ...current,
                            image_job_id: null,
                            image_error: job.error || 'Image generation failed.',
                        },
                    });
                    setImagePolling(false);
                }
            } catch {
                // keep polling
            }
        };
        tick();
        const timer = setInterval(tick, 2500);
        return () => {
            cancelled = true;
            clearInterval(timer);
        };
    }, [needsPoll, jobId]);

    useEffect(() => {
        if (modalPhase !== 'brief' || !threadRef.current) return;
        threadRef.current.scrollTop = threadRef.current.scrollHeight;
    }, [briefMessages, briefing, modalPhase]);

    const aspectClass = imageSizeAspectClass(preview?.image_size);
    const agentStrip = CAMPAIGN_TEASE_AGENTS;
    const previewAgents = preview?.agents || briefAgents;

    return (
        <div>
            <div
                role="radiogroup"
                aria-label="Content mode"
                className="flex flex-col gap-1.5 sm:flex-row sm:gap-2"
            >
                {modes.map((item) => {
                    const selected = mode === item.id;
                    return (
                        <button
                            key={item.id}
                            type="button"
                            role="radio"
                            aria-checked={selected}
                            onClick={() => onChange({
                                contentMode: item.id,
                                examplePreview: null,
                                briefComplete: false,
                                briefNotes: null,
                                planMeta: null,
                            })}
                            className={`flex min-w-0 flex-1 items-center gap-2.5 rounded-full border px-3 py-2 text-left transition ${
                                selected
                                    ? 'border-ink bg-ink/[0.04] shadow-[inset_0_0_0_1px_rgb(18_24_31_/_0.06)]'
                                    : 'border-ink/10 bg-white hover:border-ink/20'
                            }`}
                        >
                            <span
                                className={`flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${
                                    selected ? 'border-ink' : 'border-ink/25'
                                }`}
                            >
                                {selected && <span className="h-2 w-2 rounded-full bg-ink" />}
                            </span>
                            <span className={`shrink-0 ${selected ? 'text-ink' : 'text-ink/40'}`}>
                                {item.id === 'ai_recent' ? <Sparkles size={14} /> : <ImagePlus size={14} />}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className={`block truncate text-[13px] font-semibold ${selected ? 'text-ink' : 'text-ink/70'}`}>
                                    {item.title}
                                </span>
                                <span className="block truncate text-[11px] text-ink/45">
                                    {item.id === 'ai_recent' ? 'From recent channel posts' : 'From product uploads'}
                                </span>
                            </span>
                        </button>
                    );
                })}
            </div>

            <p className="mt-3 text-[12px] leading-relaxed text-ink/50">{activeMeta.request}</p>

            {mode === 'ai_recent' && (
                <div className="mt-4 rounded-lg border border-ink/8 bg-white px-3 py-2.5">
                    <label className="block">
                        <span className="text-[13px] font-semibold text-ink">Focus prompt</span>
                        <span className="mt-0.5 block text-[11px] text-ink/40">
                            Tell AI what this campaign should push. Min {MIN_PROMPT_LEN} characters.
                        </span>
                        <textarea
                            value={state.focusPrompt}
                            onChange={(e) => {
                                onChange({
                                    focusPrompt: e.target.value,
                                    examplePreview: null,
                                    briefComplete: false,
                                });
                                setGenError('');
                            }}
                            rows={4}
                            placeholder="e.g. Ramadan bundles for Biskra delivery, free shipping…"
                            className="mt-2.5 w-full resize-y rounded-lg border-0 bg-cream px-3 py-2.5 text-[13px] leading-relaxed text-ink placeholder:text-ink/30 focus:outline-none focus:ring-1 focus:ring-ink/15"
                        />
                    </label>
                    <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                        <span className={`text-[11px] font-medium tabular-nums ${promptOk ? 'text-teal' : 'text-ink/35'}`}>
                            {promptLen}/{MIN_PROMPT_LEN}+
                        </span>
                        {errors.prompt && (
                            <span className="text-[11px] font-medium text-coral" role="alert">{errors.prompt}</span>
                        )}
                    </div>
                </div>
            )}

            {mode === 'product_images' && (
                <div className="mt-4 rounded-lg border border-ink/8 bg-white px-3 py-2.5">
                    <div className="flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <p className="text-[13px] font-semibold text-ink">Product images</p>
                            <p className="mt-0.5 text-[11px] text-ink/40">
                                Need {minImages}+ for {totals.posts} posts (80%).
                            </p>
                        </div>
                        <p className={`text-[12px] font-semibold tabular-nums ${imageCount >= minImages ? 'text-teal' : 'text-coral'}`}>
                            {imageCount}/{minImages}
                        </p>
                    </div>
                    <div
                        {...getRootProps()}
                        className={`mt-3 cursor-pointer rounded-lg border border-dashed px-3 py-6 text-center transition ${
                            isDragActive ? 'border-coral bg-coral/5' : 'border-ink/15 bg-cream hover:border-ink/30'
                        }`}
                    >
                        <input {...getInputProps()} />
                        <ImagePlus className="mx-auto text-ink/40" size={20} strokeWidth={1.6} />
                        <p className="mt-1.5 text-[12px] font-medium text-ink">Drop images or browse</p>
                        <p className="mt-0.5 text-[11px] text-ink/35">PNG, JPG, WebP</p>
                    </div>
                    {imageCount > 0 && (
                        <ul className="mt-3 grid grid-cols-5 gap-1.5 sm:grid-cols-6">
                            {state.images.map((img) => (
                                <li key={img.id} className="group relative aspect-square overflow-hidden rounded-md ring-1 ring-ink/10">
                                    <img src={img.url} alt={img.name || ''} className="h-full w-full object-cover" />
                                    <button
                                        type="button"
                                        onClick={() => removeImage(img.id)}
                                        className="absolute right-0.5 top-0.5 flex h-5 w-5 items-center justify-center rounded bg-ink/70 text-white opacity-0 transition group-hover:opacity-100"
                                        aria-label="Remove image"
                                    >
                                        <X size={10} />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {errors.images && (
                        <p className="mt-2 text-[11px] font-medium text-coral" role="alert">{errors.images}</p>
                    )}
                </div>
            )}

            <div className="mt-3 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    onClick={startBrief}
                    disabled={busy || !canGenerate}
                    className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-ink/15 bg-white px-3.5 text-[12px] font-semibold text-ink disabled:opacity-40"
                >
                    {busy ? (
                        <LoaderCircle size={14} className="animate-spin" />
                    ) : (
                        <Wand2 size={14} />
                    )}
                    {busy ? 'Working…' : preview ? 'New optional tease' : 'Optional: generate tease'}
                </button>
                <span className="text-[11px] text-ink/40">
                    Continue runs the brief + Confirm/Deny cards. Tease is optional.
                </span>
            </div>
            {state.briefComplete ? (
                <p className="mt-2 text-[11px] font-medium text-teal">Brief confirmed — you can continue.</p>
            ) : errors.brief ? (
                <p className="mt-2 text-[11px] font-medium text-coral" role="alert">{errors.brief}</p>
            ) : null}
            <p className="mt-1.5 text-[10px] font-medium uppercase tracking-[0.08em] text-ink/35">
                {agentStrip.map((a) => a.id).join(' · ')}
            </p>
            {genError && !modalOpen && (
                <p className="mt-2 text-[11px] font-medium text-coral" role="alert">{genError}</p>
            )}

            {modalOpen && createPortal(
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <button
                        type="button"
                        className="absolute inset-0 bg-ink/40"
                        aria-label="Close"
                        onClick={() => setModalOpen(false)}
                    />
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="campaign-example-title"
                        className="relative z-10 flex max-h-[90vh] w-full max-w-md flex-col rounded-xl bg-white shadow-xl"
                    >
                        <div className="flex items-start justify-between gap-3 border-b border-ink/8 px-4 py-3">
                            <div>
                                <h3 id="campaign-example-title" className="text-[14px] font-semibold text-ink">
                                    {modalPhase === 'brief' ? 'Brief the tease' : 'Campaign tease'}
                                </h3>
                                <p className="mt-0.5 text-[11px] text-ink/40">
                                    {modalPhase === 'brief'
                                        ? 'Answer post decisions if needed — then Creative + CaptionApprover run.'
                                        : (preview?.platform || '')}
                                </p>
                                <p className="mt-1 text-[10px] font-medium text-ink/30">
                                    {agentStrip.map((a, idx) => (
                                        <span key={a.id}>
                                            {idx > 0 ? ' → ' : ''}
                                            {a.role}: {a.id}
                                        </span>
                                    ))}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setModalOpen(false)}
                                className="flex h-7 w-7 items-center justify-center rounded-md text-ink/50 hover:bg-ink/5"
                                aria-label="Close"
                            >
                                <X size={14} />
                            </button>
                        </div>

                        {modalPhase === 'brief' ? (
                            <>
                                <div ref={threadRef} className="min-h-[220px] flex-1 space-y-2.5 overflow-y-auto px-4 py-3">
                                    {briefing && briefMessages.length === 0 ? (
                                        <div className="flex items-center gap-2 py-8 text-[12px] text-ink/45">
                                            <LoaderCircle size={16} className="animate-spin" />
                                            Reading images…
                                        </div>
                                    ) : (
                                        briefMessages.map((m, i) => {
                                            const fromOwner = m.role === 'user';
                                            const dir = textDirection(m.content);
                                            return (
                                                <div
                                                    key={`${m.role}-${i}`}
                                                    className={`flex ${fromOwner ? 'justify-end' : 'justify-start'}`}
                                                >
                                                    <div
                                                        dir={dir}
                                                        lang={dir === 'rtl' ? 'ar' : undefined}
                                                        style={{ unicodeBidi: 'plaintext' }}
                                                        className={`max-w-[85%] rounded-2xl px-3 py-2 text-[13px] leading-[1.7] whitespace-pre-wrap break-words [overflow-wrap:anywhere] ${
                                                            fromOwner
                                                                ? 'rounded-br-md bg-ink text-white'
                                                                : 'rounded-bl-md bg-cream text-ink'
                                                        } ${dir === 'rtl' ? 'text-right' : 'text-left'}`}
                                                    >
                                                        {m.content}
                                                    </div>
                                                </div>
                                            );
                                        })
                                    )}
                                    {briefing && briefMessages.length > 0 && (
                                        <div className="flex justify-start">
                                            <div className="inline-flex items-center gap-1.5 rounded-2xl rounded-bl-md bg-cream px-3 py-2 text-[12px] text-ink/45">
                                                <LoaderCircle size={14} className="animate-spin" />
                                                Thinking…
                                            </div>
                                        </div>
                                    )}
                                    {awaitingAccept && !briefing && !generating && confirmCards.length > 0 && (
                                        <div className="space-y-3 pt-1">
                                            <div className="flex items-center justify-between gap-2">
                                                <p className="text-[10px] font-semibold uppercase tracking-[0.1em] text-ink/40">
                                                    Posts process
                                                </p>
                                                <p className={`text-[10px] font-semibold ${
                                                    processCardsDecided ? 'text-teal' : 'text-ink/40'
                                                }`}>
                                                    {answeredCount}/{confirmCards.length} answered
                                                </p>
                                            </div>
                                            <div className="space-y-2">
                                                {confirmCards.map((card) => {
                                                    const cid = String(card.id);
                                                    const choice = cardChoices[cid];
                                                    const dir = textDirection(card.text);
                                                    return (
                                                        <div
                                                            key={cid}
                                                            className={`rounded-xl border-2 px-3 py-2.5 transition ${
                                                                choice === 'confirm'
                                                                    ? 'border-teal bg-teal/10 shadow-sm'
                                                                    : choice === 'deny'
                                                                        ? 'border-coral bg-coral/10 shadow-sm'
                                                                        : 'border-ink/15 bg-white'
                                                            }`}
                                                        >
                                                            <p
                                                                dir={dir}
                                                                lang={dir === 'rtl' ? 'ar' : undefined}
                                                                style={{ unicodeBidi: 'plaintext' }}
                                                                className={`text-[12px] leading-snug text-ink ${
                                                                    dir === 'rtl' ? 'text-right' : 'text-left'
                                                                }`}
                                                            >
                                                                {card.text}
                                                            </p>
                                                            {choice ? (
                                                                <p className={`mt-1.5 text-[10px] font-semibold uppercase tracking-[0.08em] ${
                                                                    choice === 'confirm' ? 'text-teal' : 'text-coral'
                                                                }`}>
                                                                    {choice === 'confirm' ? 'Confirmed' : 'Denied'}
                                                                </p>
                                                            ) : (
                                                                <p className="mt-1.5 text-[10px] font-medium text-ink/35">
                                                                    Choose Confirm or Deny
                                                                </p>
                                                            )}
                                                            <div className="mt-2 flex gap-1.5">
                                                                <button
                                                                    type="button"
                                                                    disabled={busy}
                                                                    aria-pressed={choice === 'confirm'}
                                                                    onClick={(e) => {
                                                                        e.preventDefault();
                                                                        e.stopPropagation();
                                                                        setCardChoice(cid, 'confirm');
                                                                    }}
                                                                    className={`inline-flex h-8 flex-1 items-center justify-center gap-1 rounded-lg text-[11px] font-semibold transition ${
                                                                        choice === 'confirm'
                                                                            ? 'bg-teal text-white ring-2 ring-teal/30'
                                                                            : 'bg-ink/5 text-ink/70 hover:bg-ink/10'
                                                                    }`}
                                                                >
                                                                    <Check size={12} />
                                                                    Confirm
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    disabled={busy}
                                                                    aria-pressed={choice === 'deny'}
                                                                    onClick={(e) => {
                                                                        e.preventDefault();
                                                                        e.stopPropagation();
                                                                        setCardChoice(cid, 'deny');
                                                                    }}
                                                                    className={`inline-flex h-8 flex-1 items-center justify-center gap-1 rounded-lg text-[11px] font-semibold transition ${
                                                                        choice === 'deny'
                                                                            ? 'bg-coral text-white ring-2 ring-coral/30'
                                                                            : 'bg-ink/5 text-ink/70 hover:bg-ink/10'
                                                                    }`}
                                                                >
                                                                    <Ban size={12} />
                                                                    Deny
                                                                </button>
                                                            </div>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                            <button
                                                type="button"
                                                onClick={completeBrief}
                                                disabled={busy || !canGenerateFromCards}
                                                className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-ink text-[13px] font-semibold text-white disabled:cursor-not-allowed disabled:opacity-35"
                                            >
                                                <Check size={15} />
                                                {canGenerateFromCards
                                                    ? 'Continue'
                                                    : `Answer all decisions (${answeredCount}/${confirmCards.length})`}
                                            </button>
                                            {canGenerateFromCards ? (
                                                <button
                                                    type="button"
                                                    onClick={generateExample}
                                                    disabled={busy}
                                                    className="inline-flex h-9 w-full items-center justify-center gap-2 rounded-xl border border-ink/15 bg-white text-[12px] font-semibold text-ink disabled:opacity-40"
                                                >
                                                    <Wand2 size={14} />
                                                    Optional: generate tease
                                                </button>
                                            ) : null}
                                        </div>
                                    )}
                                    {awaitingAccept && !briefing && !generating && confirmCards.length === 0 && (
                                        <div className="mx-auto flex w-full max-w-sm flex-col gap-3 rounded-2xl border border-teal/25 bg-teal/[0.07] px-4 py-4">
                                            <p className="text-[10px] font-semibold uppercase tracking-[0.1em] text-teal">
                                                Ready to proceed
                                            </p>
                                            {(understanding || briefMessages.filter((m) => m.role === 'assistant').slice(-1)[0]?.content) && (
                                                <p
                                                    dir={textDirection(understanding || '')}
                                                    lang={textDirection(understanding || '') === 'rtl' ? 'ar' : undefined}
                                                    style={{ unicodeBidi: 'plaintext' }}
                                                    className={`whitespace-pre-wrap break-words text-[13px] leading-[1.65] text-ink [overflow-wrap:anywhere] ${
                                                        textDirection(understanding || '') === 'rtl' ? 'text-right' : 'text-left'
                                                    }`}
                                                >
                                                    {understanding
                                                        || briefMessages.filter((m) => m.role === 'assistant').slice(-1)[0]?.content}
                                                </p>
                                            )}
                                            <p className="text-[11px] leading-snug text-ink/50">
                                                Nothing else to ask — confirm this understanding to continue the campaign.
                                            </p>
                                            <button
                                                type="button"
                                                onClick={completeBrief}
                                                disabled={busy}
                                                className="inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-ink text-[14px] font-semibold text-white shadow-sm hover:opacity-90 disabled:opacity-40"
                                            >
                                                <Check size={16} />
                                                Continue without tease
                                            </button>
                                            <button
                                                type="button"
                                                onClick={generateExample}
                                                disabled={busy}
                                                className="inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl border border-ink/15 bg-white text-[13px] font-semibold text-ink disabled:opacity-40"
                                            >
                                                <Wand2 size={15} />
                                                Optional: generate tease
                                            </button>
                                        </div>
                                    )}
                                    {generating && modalPhase === 'brief' && (
                                        <div className="mx-auto flex max-w-sm flex-col items-center gap-2 rounded-2xl bg-ink/[0.04] px-4 py-3 text-center">
                                            <LoaderCircle size={18} className="animate-spin text-ink/50" />
                                            <p className="text-[12px] font-semibold text-ink/70">
                                                Waiting for the post tease…
                                            </p>
                                            <p className="text-[11px] leading-snug text-ink/45">
                                                Writing caption from confirmed cards only.
                                            </p>
                                        </div>
                                    )}
                                    {genError && (
                                        <p className="text-[11px] font-medium text-coral" role="alert">{genError}</p>
                                    )}
                                </div>

                                <div className="border-t border-ink/8 px-3 py-2.5">
                                    <div className="flex items-center justify-between gap-2">
                                        <button
                                            type="button"
                                            onClick={onBriefPrev}
                                            disabled={busy || (briefStack.length === 0 && modalPhase === 'brief')}
                                            className="inline-flex h-8 items-center gap-1 rounded-lg px-2 text-[12px] font-medium text-ink/60 hover:bg-ink/5 disabled:opacity-30"
                                        >
                                            <ArrowLeft size={14} />
                                            Previous
                                        </button>
                                        {awaitingAccept && !generating ? (
                                            confirmCards.length === 0 ? (
                                                <div className="flex flex-wrap items-center justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={generateExample}
                                                        disabled={busy || !canGenerateFromCards}
                                                        className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-ink/15 bg-white px-3 text-[11px] font-semibold text-ink disabled:opacity-40"
                                                    >
                                                        <Wand2 size={14} />
                                                        Optional tease
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={completeBrief}
                                                        disabled={busy || !canGenerateFromCards}
                                                        className="inline-flex h-10 items-center gap-1.5 rounded-lg bg-ink px-4 text-[12px] font-semibold text-white disabled:opacity-40"
                                                    >
                                                        <Check size={14} />
                                                        Continue
                                                    </button>
                                                </div>
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={completeBrief}
                                                    disabled={busy || !canGenerateFromCards}
                                                    className="inline-flex h-8 items-center gap-1.5 rounded-lg bg-ink px-3 text-[11px] font-semibold text-white disabled:cursor-not-allowed disabled:opacity-35"
                                                >
                                                    {canGenerateFromCards
                                                        ? 'Continue'
                                                        : `${answeredCount}/${confirmCards.length} answered`}
                                                </button>
                                            )
                                        ) : generating ? (
                                            <span className="inline-flex h-8 items-center gap-1.5 text-[11px] font-medium text-teal">
                                                <LoaderCircle size={13} className="animate-spin" />
                                                Building sample…
                                            </span>
                                        ) : (
                                            <span className="text-[11px] text-ink/35">
                                                {briefReady
                                                    ? (confirmCards.length ? 'Answer post decisions to continue' : 'Ready to continue')
                                                    : 'Waiting for image reading…'}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </>
                        ) : (
                            <>
                                <div className="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                                    <div className={`relative mx-auto w-full max-w-[240px] overflow-hidden rounded-lg bg-cream ring-1 ring-ink/10 ${aspectClass}`}>
                                        {preview?.image_url ? (
                                            <img src={preview.image_url} alt="" className="h-full w-full object-cover" />
                                        ) : (
                                            <div className="flex h-full min-h-[120px] flex-col items-center justify-center gap-2 px-3 text-center">
                                                {(imagePolling || generating) && (
                                                    <LoaderCircle size={18} className="animate-spin text-ink/40" />
                                                )}
                                                <p className="text-[11px] text-ink/45">
                                                    {preview?.image_error
                                                        || (imagePolling
                                                            ? `Rendering with ${preview?.image_model || 'your shop'} image model…`
                                                            : (generating ? 'Waiting for caption + image…' : 'Image will appear here'))}
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                    {preview?.title ? (
                                        <p className="mt-3 text-[12px] font-semibold text-ink">
                                            <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-ink/40">Idea · </span>
                                            {preview.title}
                                        </p>
                                    ) : null}
                                    <p
                                        dir={textDirection(previewSplit.body)}
                                        style={{ unicodeBidi: 'plaintext' }}
                                        className={`mt-2 whitespace-pre-wrap break-words text-[13px] leading-[1.7] text-ink [overflow-wrap:anywhere] ${
                                            textDirection(previewSplit.body) === 'rtl' ? 'text-right' : 'text-left'
                                        }`}
                                    >
                                        {previewSplit.body}
                                    </p>
                                    {previewSplit.tags.length > 0 && (
                                        <p className="mt-2 text-[12px] font-medium text-accent">
                                            {previewSplit.tags.join(' ')}
                                        </p>
                                    )}
                                    <p className="mt-3 text-[11px] text-ink/45">
                                        {preview?.meta || 'Tease of what you will get across the campaign days.'}
                                    </p>
                                    {previewAgents && (
                                        <p className="mt-2 text-[10px] font-medium text-ink/30">
                                            {[
                                                previewAgents.brief || 'CampaignBriefAgent',
                                                previewAgents.writer || 'CampaignCreativeAgent',
                                                previewAgents.approver || 'CaptionApprover',
                                            ].filter(Boolean).join(' → ')}
                                            {preview?.approver?.approved === true ? ' · approved' : ''}
                                            {preview?.approver?.needs_owner_edit === true ? ' · needs your edit' : ''}
                                        </p>
                                    )}
                                </div>
                                <div className="flex items-center justify-between gap-2 border-t border-ink/8 px-4 py-3">
                                    <button
                                        type="button"
                                        onClick={onBriefPrev}
                                        disabled={busy}
                                        className="inline-flex h-8 items-center gap-1 rounded-lg px-2.5 text-[12px] font-medium text-ink/60 hover:bg-ink/5 disabled:opacity-30"
                                    >
                                        <ArrowLeft size={14} />
                                        Previous
                                    </button>
                                    <div className="flex gap-2">
                                        <button
                                            type="button"
                                            onClick={startBrief}
                                            disabled={busy}
                                            className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-ink/10 px-3 text-[12px] font-semibold text-ink disabled:opacity-40"
                                        >
                                            {busy ? <LoaderCircle size={13} className="animate-spin" /> : <Wand2 size={13} />}
                                            Redo briefing
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setModalOpen(false)}
                                            className="inline-flex h-8 items-center rounded-lg bg-ink px-3 text-[12px] font-semibold text-white"
                                        >
                                            Close
                                        </button>
                                    </div>
                                </div>
                            </>
                        )}
                    </div>
                </div>,
                document.body,
            )}
        </div>
    );
});
