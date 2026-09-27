import { X } from 'lucide-react';
import { FileDrop, SwitchField, TextArea } from '../form';
import { ListSkeleton } from '../inboxSkeletons';
import TriggerPicker, { triggerLabelFor } from './TriggerPicker';

export default function NodeEditor({
    step,
    options,
    field,
    hint,
    onField,
    onHint,
    requireClearMatch,
    onRequireClearMatch,
    engagement = null,
    dm = null,
    onClose,
}) {
    if (!step) {
        return null;
    }

    const kind = step.kind;
    const title = step.title || step.label || 'Step';
    const key = step.key;
    const isLead = !engagement && !dm;

    return (
        <div
            role="dialog"
            aria-label={`Edit ${title}`}
            className="absolute start-1/2 top-4 z-20 w-[min(100%-1.5rem,24rem)] -translate-x-1/2 rounded-2xl border border-line bg-white p-4 shadow-[0_18px_50px_-18px_rgba(31,42,55,0.35)]"
            onClick={(e) => e.stopPropagation()}
            onPointerDown={(e) => e.stopPropagation()}
        >
            <div className="mb-3 flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-muted">
                        {kind === 'trigger' ? 'Trigger' : kind === 'chip' ? 'Result' : 'Action'}
                    </p>
                    <h3 className="truncate text-sm font-semibold text-ink">{title}</h3>
                </div>
                <button type="button" aria-label="Close" onClick={onClose} className="rounded-lg p-1 text-ink hover:bg-bubble">
                    <X size={16} />
                </button>
            </div>

            {engagement && key === 'posts' && (
                <PostPickerPanel engagement={engagement} />
            )}

            {engagement && key === 'public_reply' && (
                <PublicReplyPanel engagement={engagement} />
            )}

            {engagement && key === 'private_dm' && (
                <PrivateDmPanel engagement={engagement} />
            )}

            {dm && key === 'keywords' && (
                <DmKeywordsPanel dm={dm} />
            )}

            {dm && key === 'dm_reply' && (
                <DmReplyPanel dm={dm} />
            )}

            {isLead && kind === 'trigger' && (
                <TriggerPicker
                    options={options}
                    field={field}
                    hint={hint}
                    onField={onField}
                    onHint={onHint}
                    title="Lead signal"
                />
            )}

            {isLead && kind === 'action' && isClassifyStep(step) && (
                <div className="space-y-3">
                    <p className="text-[13px] text-muted">
                        The agent reads the full inbox thread and only continues when the signal is clearly the client’s — not an order id or shop number.
                    </p>
                    <label className="flex items-center justify-between gap-3 text-sm font-semibold text-ink">
                        Require a clear match
                        <button
                            type="button"
                            role="switch"
                            aria-checked={requireClearMatch}
                            onClick={() => onRequireClearMatch?.(!requireClearMatch)}
                            className={`relative h-5 w-9 shrink-0 rounded-full transition ${requireClearMatch ? 'bg-accent' : 'bg-line'}`}
                        >
                            <span className={`absolute top-0.5 h-4 w-4 rounded-full bg-white transition ${requireClearMatch ? 'start-4' : 'start-0.5'}`} />
                        </button>
                    </label>
                </div>
            )}

            {isLead && kind === 'action' && isMarkStep(step) && (
                <div className="space-y-3">
                    <p className="text-[13px] text-muted">
                        When the trigger matches, this step updates the contact’s lifecycle.
                    </p>
                    <div className="rounded-xl border border-line bg-bubble px-3 py-2.5">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-muted">Marks as</p>
                        <p className="mt-0.5 text-sm font-semibold text-ink">
                            {title.toLowerCase().includes('hot') ? 'Hot lead' : 'New lead'}
                        </p>
                    </div>
                    <div className="rounded-xl border border-line bg-bubble px-3 py-2.5">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-muted">Watches for</p>
                        <p className="mt-0.5 text-sm font-semibold text-ink">
                            {field === 'custom' && hint.trim()
                                ? hint.trim()
                                : triggerLabelFor(field, { trigger_options: options })}
                        </p>
                    </div>
                </div>
            )}

            {isLead && kind === 'chip' && (
                <p className="text-[13px] text-muted">
                    End of the flow — the contact appears in Leads when the steps above succeed.
                </p>
            )}

            {engagement && kind === 'chip' && (
                <p className="text-[13px] text-muted">
                    Comments on the selected posts run public reply then Auto DM in order.
                </p>
            )}

            {dm && kind === 'chip' && (
                <p className="text-[13px] text-muted">
                    Matching DMs get your reply instantly. Other messages keep normal inbox AI settings.
                </p>
            )}

            {isLead && kind === 'message' && (
                <p className="text-[13px] text-muted">
                    Optional reply step. Lead workflows classify quietly; turn on auto-reply in AI settings if you want a message sent back.
                </p>
            )}

            {isLead && kind === 'action' && !isClassifyStep(step) && !isMarkStep(step) && (
                <p className="text-[13px] text-muted">
                    {step.body || 'This action runs after the trigger matches.'}
                </p>
            )}

            <div className="mt-4 flex justify-end">
                <button type="button" onClick={onClose} className="h-8 rounded-lg bg-coral px-3 text-sm font-semibold text-white">
                    Done
                </button>
            </div>
        </div>
    );
}

function PostPickerPanel({ engagement }) {
    const selected = new Set((engagement.posts || []).map((p) => `${p.account_id}:${p.platform_post_id}`));
    const catalog = engagement.catalog || [];

    return (
        <div className="space-y-3">
            <p className="text-[13px] text-muted">Choose which posts trigger this automation when someone comments.</p>
            {engagement.loadingPosts ? (
                <ListSkeleton rows={4} />
            ) : catalog.length === 0 ? (
                <p className="text-[12px] text-muted">No posts loaded yet. Publish posts from linked pages first.</p>
            ) : (
                <div className="max-h-56 space-y-2 overflow-y-auto">
                    {catalog.map((post) => {
                        const key = `${post.account_id}:${post.platform_post_id}`;
                        const on = selected.has(key);
                        return (
                            <button
                                key={key}
                                type="button"
                                onClick={() => engagement.onTogglePost?.(post)}
                                className={`flex w-full items-center gap-2.5 rounded-xl border px-2 py-2 text-start transition ${
                                    on ? 'border-coral bg-coral/5' : 'border-line bg-white hover:bg-bubble'
                                }`}
                            >
                                <span className="h-12 w-12 shrink-0 overflow-hidden rounded-lg bg-bubble">
                                    {post.thumbnail ? (
                                        <img src={post.thumbnail} alt="" className="h-full w-full object-cover" />
                                    ) : null}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="line-clamp-2 text-[12px] font-medium text-ink">{post.caption || 'No caption'}</span>
                                    <span className="mt-0.5 block text-[11px] text-muted">{post.account_name || post.platform}</span>
                                </span>
                                <span className={`h-4 w-4 shrink-0 rounded border ${on ? 'border-coral bg-coral' : 'border-line'}`} />
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

function PublicReplyPanel({ engagement }) {
    const step = (engagement.steps || []).find((s) => s.type === 'public_reply') || {};
    const enabled = step.enabled !== false;
    const mode = step.mode === 'fixed' ? 'fixed' : 'agent';

    return (
        <div className="space-y-3">
            <SwitchField
                label="Send public reply"
                description="Reply on the comment thread"
                checked={enabled}
                onChange={(value) => engagement.onUpdateStep?.('public_reply', { enabled: value })}
            />
            {enabled && (
                <>
                    <ModeSegment
                        value={mode}
                        onChange={(value) => engagement.onUpdateStep?.('public_reply', { mode: value })}
                        options={[
                            { value: 'agent', label: 'AI agent' },
                            { value: 'fixed', label: 'Fixed reply' },
                        ]}
                    />
                    {mode === 'agent' ? (
                        <p className="rounded-xl bg-bubble px-3 py-2.5 text-[12px] text-muted">
                            The business agent drafts a contextual reply to each comment.
                        </p>
                    ) : (
                        <div className="space-y-3">
                            <TextArea
                                label="Reply message"
                                rows={3}
                                value={step.text || ''}
                                onChange={(e) => engagement.onUpdateStep?.('public_reply', { text: e.target.value })}
                                placeholder="Thanks for commenting!"
                            />
                            {step.image_url || step.image_path ? (
                                <div className="relative overflow-hidden rounded-xl border border-line">
                                    <img src={step.image_url || step.image_path} alt="" className="h-28 w-full object-cover" />
                                    <button
                                        type="button"
                                        onClick={() => engagement.onClearImage?.()}
                                        className="absolute end-2 top-2 rounded-lg bg-white/95 px-2 py-1 text-[11px] font-semibold text-ink ring-1 ring-line"
                                    >
                                        Remove
                                    </button>
                                </div>
                            ) : (
                                <FileDrop
                                    multiple={false}
                                    disabled={engagement.uploading}
                                    onFiles={engagement.onUploadImage}
                                    label={engagement.uploading ? 'Uploading…' : 'Optional image'}
                                    hint="JPG, PNG or WEBP"
                                />
                            )}
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

function PrivateDmPanel({ engagement }) {
    const step = (engagement.steps || []).find((s) => s.type === 'private_dm') || {};
    const enabled = step.enabled !== false;
    const mode = step.mode === 'fixed' ? 'fixed' : 'agent';

    return (
        <div className="space-y-3">
            <SwitchField
                label="Send Auto DM"
                description="Private reply to the commenter"
                checked={enabled}
                onChange={(value) => engagement.onUpdateStep?.('private_dm', { enabled: value })}
            />
            {enabled && (
                <>
                    <ModeSegment
                        value={mode}
                        onChange={(value) => engagement.onUpdateStep?.('private_dm', { mode: value })}
                        options={[
                            { value: 'agent', label: 'Leave to agent' },
                            { value: 'fixed', label: 'Custom payload' },
                        ]}
                    />
                    {mode === 'agent' ? (
                        <p className="rounded-xl bg-bubble px-3 py-2.5 text-[12px] text-muted">
                            Uses the default private-reply message for commenters.
                        </p>
                    ) : (
                        <TextArea
                            label="DM message"
                            rows={3}
                            value={step.text || ''}
                            onChange={(e) => engagement.onUpdateStep?.('private_dm', { text: e.target.value })}
                            placeholder="Hi! Thanks for your comment — how can we help?"
                        />
                    )}
                </>
            )}
        </div>
    );
}

function DmKeywordsPanel({ dm }) {
    const selected = new Set(dm.selectedPlatforms || []);

    return (
        <div className="space-y-3">
            <div>
                <p className="mb-2 text-[12px] font-semibold text-muted">Platforms</p>
                <p className="mb-2 text-[12px] text-muted">Select one or more. Save needs at least one.</p>
                <div className="flex flex-wrap gap-1.5">
                    {(dm.platforms || []).map((row) => {
                        const on = selected.has(row.id);
                        return (
                            <button
                                key={row.id}
                                type="button"
                                onClick={() => dm.onTogglePlatform?.(row.id)}
                                className={`h-8 rounded-lg px-2.5 text-[12px] font-semibold transition ${
                                    on ? 'bg-ink text-white' : 'border border-line bg-white text-ink hover:bg-bubble'
                                }`}
                            >
                                {row.label}
                            </button>
                        );
                    })}
                </div>
                {selected.size === 0 && (
                    <p className="mt-2 text-[11px] font-medium text-coral">Pick at least one platform to save.</p>
                )}
            </div>
            <div>
                <p className="mb-2 text-[12px] font-semibold text-muted">Keywords / phrases</p>
                <div className="flex gap-2">
                    <input
                        value={dm.keywordInput || ''}
                        onChange={(e) => dm.onKeywordInput?.(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                dm.onAddKeyword?.();
                            }
                        }}
                        placeholder="e.g. price"
                        className="h-9 min-w-0 flex-1 rounded-lg border border-line bg-white px-3 text-[13px] text-ink outline-none focus:border-ink"
                    />
                    <button
                        type="button"
                        onClick={() => dm.onAddKeyword?.()}
                        className="h-9 shrink-0 rounded-lg bg-ink px-3 text-[12px] font-semibold text-white"
                    >
                        Add
                    </button>
                </div>
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {(dm.keywords || []).map((word) => (
                        <button
                            key={word}
                            type="button"
                            onClick={() => dm.onRemoveKeyword?.(word)}
                            className="inline-flex items-center gap-1 rounded-full border border-line bg-bubble px-2.5 py-1 text-[11px] font-semibold text-ink"
                            title="Remove"
                        >
                            {word}
                            <X size={11} className="text-muted" />
                        </button>
                    ))}
                </div>
                <p className="mt-2 text-[11px] text-muted">Message must contain the word (not case sensitive).</p>
            </div>
        </div>
    );
}

function DmReplyPanel({ dm }) {
    const reply = dm.reply || {};
    const mode = reply.mode === 'agent' ? 'agent' : 'fixed';

    return (
        <div className="space-y-3">
            <ModeSegment
                value={mode}
                onChange={(value) => dm.onReply?.({ mode: value })}
                options={[
                    { value: 'fixed', label: 'Fixed message' },
                    { value: 'agent', label: 'AI agent' },
                ]}
            />
            {mode === 'agent' ? (
                <p className="rounded-xl bg-bubble px-3 py-2.5 text-[12px] leading-relaxed text-muted">
                    When a keyword matches, the business agent drafts a contextual DM reply.
                </p>
            ) : (
                <div className="space-y-3">
                    <TextArea
                        label="Reply message"
                        rows={3}
                        value={reply.text || ''}
                        onChange={(e) => dm.onReply?.({ text: e.target.value })}
                        placeholder="Our prices start at…"
                    />
                    {reply.image_url || reply.image_path ? (
                        <div className="relative overflow-hidden rounded-xl border border-line">
                            <img src={reply.image_url || reply.image_path} alt="" className="h-28 w-full object-cover" />
                            <button
                                type="button"
                                onClick={() => dm.onClearImage?.()}
                                className="absolute end-2 top-2 rounded-lg bg-white/95 px-2 py-1 text-[11px] font-semibold text-ink ring-1 ring-line"
                            >
                                Remove
                            </button>
                        </div>
                    ) : (
                        <FileDrop
                            multiple={false}
                            disabled={dm.uploading}
                            onFiles={dm.onUploadImage}
                            label={dm.uploading ? 'Uploading…' : 'Optional image'}
                            hint="JPG, PNG or WEBP"
                        />
                    )}
                </div>
            )}
        </div>
    );
}

function ModeSegment({ value, onChange, options }) {
    return (
        <div className="grid grid-cols-2 gap-1 rounded-xl bg-bubble p-1">
            {options.map((option) => {
                const active = value === option.value;
                return (
                    <button
                        key={option.value}
                        type="button"
                        onClick={() => onChange(option.value)}
                        className={`h-9 rounded-lg text-[12px] font-semibold transition ${
                            active ? 'bg-ink text-white shadow-sm' : 'text-muted hover:bg-white hover:text-ink'
                        }`}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}

function isClassifyStep(step) {
    const title = (step.title || '').toLowerCase();
    return title.includes('classif') || title.includes('ai');
}

function isMarkStep(step) {
    const title = (step.title || '').toLowerCase();
    return title.includes('mark') || title.includes('lead') || title.includes('hot');
}
