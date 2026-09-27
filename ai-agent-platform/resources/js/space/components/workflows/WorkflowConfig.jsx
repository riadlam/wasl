import { useMemo, useState } from 'react';
import { X } from 'lucide-react';
import { useSpace } from '../../context';
import { SwitchField } from '../form';
import FlowCanvas from './FlowCanvas';
import WorkflowBriefing, { leadBriefing } from './WorkflowBriefing';
import { triggerLabelFor, triggerOptionsFor, withTriggerSteps } from './TriggerPicker';

export default function WorkflowConfig({ workflow, onClose, onSave }) {
    const { setError } = useSpace();
    const options = triggerOptionsFor(workflow);
    const [field, setField] = useState(workflow.config?.trigger_field || 'phone');
    const [hint, setHint] = useState(workflow.config?.trigger_hint || '');
    const [requireClearMatch, setRequireClearMatch] = useState(
        workflow.config?.require_clear_match !== false,
    );
    const [active, setActive] = useState(workflow.status === 'active');
    const [busy, setBusy] = useState(false);
    const steps = useMemo(
        () => withTriggerSteps(workflow.steps || [], field, hint),
        [workflow.steps, field, hint],
    );
    const canSave = field !== 'custom' || hint.trim() !== '';
    const signalLabel = field === 'custom' && hint.trim()
        ? hint.trim()
        : triggerLabelFor(field, workflow);
    const isHot = workflow.template_key === 'hot_lead';
    const briefing = leadBriefing({ signalLabel, isHot });

    const save = async () => {
        if (!canSave) {
            return;
        }
        setBusy(true);
        try {
            await onSave({
                status: active ? 'active' : 'paused',
                config: {
                    trigger_field: field,
                    trigger_hint: hint.trim() || null,
                    require_clear_match: requireClearMatch,
                },
            });
            onClose();
        } catch (err) {
            setError(err.response?.data?.message || 'Could not save workflow.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="absolute inset-0 z-30 flex flex-col overflow-hidden bg-white lg:flex-row">
            <section className="flex max-h-[42%] w-full shrink-0 flex-col border-b border-line bg-white lg:max-h-none lg:h-full lg:w-[min(42%,420px)] lg:border-b-0 lg:border-e">
                <header className="flex items-center justify-between gap-3 border-b border-line px-3 py-2">
                    <div className="min-w-0">
                        <h1 className="truncate text-base font-semibold text-ink">{workflow.title || workflow.name}</h1>
                        <p className="text-[12px] text-muted">Click canvas steps to adjust settings</p>
                    </div>
                    <button
                        type="button"
                        aria-label="Close"
                        onClick={onClose}
                        className="rounded-lg p-1 text-ink hover:bg-bubble"
                    >
                        <X size={18} />
                    </button>
                </header>
                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
                    <WorkflowBriefing
                        eyebrow={briefing.eyebrow}
                        title={briefing.title}
                        summary={briefing.summary}
                        bullets={briefing.bullets}
                        how={briefing.how}
                        status={(
                            <div className="mt-3 border-t border-line/70 pt-3">
                                <SwitchField
                                    label="Active"
                                    description={active ? 'Running when the signal matches' : 'Paused / draft — not running'}
                                    checked={active}
                                    onChange={setActive}
                                />
                            </div>
                        )}
                    />
                    <div className="flex justify-end gap-2 border-t border-line pt-4">
                        <button
                            type="button"
                            onClick={onClose}
                            className="h-9 rounded-lg border border-line px-3 text-sm font-semibold text-ink hover:bg-bubble"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            disabled={busy || !canSave}
                            onClick={save}
                            className="h-9 rounded-lg bg-coral px-3.5 text-sm font-semibold text-white disabled:opacity-60"
                        >
                            Save
                        </button>
                    </div>
                </div>
            </section>
            <section className="min-h-[58dvh] w-full flex-1 lg:h-full lg:min-h-0">
                <FlowCanvas
                    steps={steps}
                    editable
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
