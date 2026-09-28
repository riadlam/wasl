import { useCallback } from 'react';
import { useDropzone } from 'react-dropzone';
import { ImagePlus, Sparkles, X } from 'lucide-react';
import {
    CONTENT_MODES,
    MIN_PROMPT_LEN,
    campaignTotals,
    minImagesForPosts,
} from './campaignDefaults';

/**
 * Content step — pick mode + prompt or uploads.
 * Campaign strategy (angles / matrix) is planned at launch; no brief modal or Confirm/Deny cards.
 */
export default function StepContent({ state, onChange, errors }) {
    const mode = state.contentMode;
    const totals = campaignTotals(state.days);
    const minImages = minImagesForPosts(totals.posts);
    const modes = [CONTENT_MODES.ai_recent, CONTENT_MODES.product_images];
    const activeMeta = CONTENT_MODES[mode] || CONTENT_MODES.ai_recent;
    const promptLen = state.focusPrompt?.trim().length || 0;
    const promptOk = promptLen >= MIN_PROMPT_LEN;
    const imageCount = (state.images || []).length;

    const onDrop = useCallback((accepted) => {
        const added = (accepted || []).map((file, i) => ({
            id: `local-${Date.now()}-${i}-${file.name}`,
            file,
            url: URL.createObjectURL(file),
            name: file.name,
            assetId: null,
        }));
        if (added.length === 0) return;
        onChange({ images: [...(state.images || []), ...added] });
    }, [onChange, state.images]);

    const { getRootProps, getInputProps, isDragActive } = useDropzone({
        onDrop,
        accept: { 'image/*': ['.png', '.jpg', '.jpeg', '.webp'] },
        multiple: true,
    });

    const removeImage = (id) => {
        const next = (state.images || []).filter((img) => {
            if (img.id !== id) return true;
            if (img.url?.startsWith('blob:')) URL.revokeObjectURL(img.url);
            return false;
        });
        onChange({ images: next });
    };

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
                                briefComplete: true,
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
                            onChange={(e) => onChange({
                                focusPrompt: e.target.value,
                                examplePreview: null,
                            })}
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

            <p className="mt-3 text-[11px] leading-relaxed text-ink/40">
                At launch, AI plans distinct content angles for every post, then drafts them one by one for your approval.
            </p>
        </div>
    );
}
