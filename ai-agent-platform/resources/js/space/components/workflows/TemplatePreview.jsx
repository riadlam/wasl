import { useMemo, useState } from 'react';
import { ChevronLeft } from 'lucide-react';
import FlowCanvas from './FlowCanvas';
import WorkflowBriefing, { dmKeywordBriefing, engagementBriefing, leadBriefing } from './WorkflowBriefing';
import { triggerLabelFor, triggerOptionsFor, withTriggerSteps } from './TriggerPicker';

export default function TemplatePreview({ template, onClose, onUse }) {
    const isEngagement = template.kind === 'engagement' || template.id === 'post_comment';
    const isDm = template.kind === 'dm' || template.id === 'dm_keyword';
    const isCanvasTemplate = isEngagement || isDm;
    const options = triggerOptionsFor(template);
    const [field, setField] = useState(template.default_config?.trigger_field || 'phone');
    const [hint, setHint] = useState('');
    const [requireClearMatch, setRequireClearMatch] = useState(true);
    const [activate, setActivate] = useState(!isCanvasTemplate);
    const [busy, setBusy] = useState(false);
    const steps = useMemo(
        () => (isCanvasTemplate ? (template.steps || []) : withTriggerSteps(template.steps || [], field, hint)),
        [template.steps, field, hint, isCanvasTemplate],
    );
    const canUse = isCanvasTemplate || field !== 'custom' || hint.trim() !== '';
    const signalLabel = field === 'custom' && hint.trim()
        ? hint.trim()
        : triggerLabelFor(field, template);

    const briefing = isDm
        ? {
            ...dmKeywordBriefing(),
            bullets: template.benefits?.length ? template.benefits : dmKeywordBriefing().bullets,
            how: template.how?.length ? template.how : dmKeywordBriefing().how,
            summary: template.summary || dmKeywordBriefing().summary,
        }
        : isEngagement
            ? {
                ...engagementBriefing(),
                bullets: template.benefits?.length ? template.benefits : engagementBriefing().bullets,
                how: template.how?.length ? template.how : engagementBriefing().how,
                summary: template.summary || engagementBriefing().summary,
            }
            : {
                ...leadBriefing({ signalLabel, isHot: template.id === 'hot_lead' }),
                bullets: template.benefits?.length ? template.benefits : leadBriefing({ signalLabel }).bullets,
                how: template.how?.length ? template.how : leadBriefing({ signalLabel }).how,
                summary: template.summary || leadBriefing({ signalLabel }).summary,
            };

    const useTemplate = async () => {
        if (!canUse || busy) {
            return;
        }
        setBusy(true);
        try {
            if (isCanvasTemplate) {
                await onUse({}, activate);
            } else {
                await onUse({
                    trigger_field: field,
                    trigger_hint: hint.trim() || null,
                    require_clear_match: requireClearMatch,
                }, activate);
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="absolute inset-0 z-30 flex flex-col overflow-hidden bg-white lg:flex-row">
            <section className="flex max-h-[42%] w-full shrink-0 flex-col border-b border-line bg-white lg:max-h-none lg:h-full lg:w-[min(42%,420px)] lg:border-b-0 lg:border-e">
                <header className="flex items-center justify-between gap-3 border-b border-line px-3 py-2">
                    <div className="flex min-w-0 items-center gap-2">
                        <button
                            type="button"
                            aria-label="Back to templates"
                            onClick={onClose}
                            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-ink hover:bg-bubble"
                        >
                            <ChevronLeft size={18} />
                        </button>
                        <h1 className="truncate text-base font-semibold text-ink">{template.title}</h1>
                    </div>
                    <button
                        type="button"
                        disabled={!canUse || busy}
                        onClick={useTemplate}
                        className="h-8 shrink-0 rounded-lg bg-coral px-3.5 text-sm font-semibold text-white disabled:opacity-60"
                    >
                        {isCanvasTemplate ? 'Open canvas' : 'Use Template'}
                    </button>
                </header>
                <div className="min-h-0 flex-1 overflow-y-auto p-5">
                    <WorkflowBriefing
                        eyebrow={briefing.eyebrow}
                        title={briefing.title}
                        summary={briefing.summary}
                        bullets={briefing.bullets}
                        how={briefing.how}
                        status={!isCanvasTemplate ? (
                            <label className="mt-3 flex items-center justify-between gap-3 border-t border-line/70 pt-3 text-sm font-semibold text-ink">
                                Activate now
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked={activate}
                                    onClick={() => setActivate((value) => !value)}
                                    className={`relative h-5 w-9 rounded-full transition ${activate ? 'bg-accent' : 'bg-line'}`}
                                >
                                    <span className={`absolute top-0.5 h-4 w-4 rounded-full bg-white transition ${activate ? 'start-4' : 'start-0.5'}`} />
                                </button>
                            </label>
                        ) : null}
                    />
                </div>
            </section>
            <section className="min-h-[58dvh] w-full flex-1 lg:h-full lg:min-h-0 lg:w-auto">
                <FlowCanvas
                    steps={steps}
                    editable={!isCanvasTemplate}
                    options={options}
                    field={field}
                    hint={hint}
                    onField={setField}
                    onHint={setHint}
                    requireClearMatch={requireClearMatch}
                    onRequireClearMatch={setRequireClearMatch}
                />
            </section>
        </div>
    );
}
