import { Building2, Mail, MapPin, Phone, Sparkles, UserRound } from 'lucide-react';
import { TextArea } from '../form';

export const DEFAULT_TRIGGER_OPTIONS = [
    { id: 'phone', label: 'Phone number', hint: 'They typed their mobile in the chat' },
    { id: 'wilaya', label: 'Wilaya / city', hint: 'They named where they live' },
    { id: 'commune', label: 'Commune', hint: 'They named their commune' },
    { id: 'email', label: 'Email', hint: 'They shared an email address' },
    { id: 'name', label: 'Full name', hint: 'They gave a name for the order' },
    { id: 'custom', label: 'Something else', hint: 'Describe what the agent should look for' },
];

const ICONS = {
    phone: Phone,
    wilaya: MapPin,
    commune: Building2,
    email: Mail,
    name: UserRound,
    custom: Sparkles,
};

const STEP_BODIES = {
    phone: 'Client shares a phone in chat',
    wilaya: 'Client names their wilaya',
    commune: 'Client names their commune',
    email: 'Client shares an email',
    name: 'Client gives their name',
};

export function triggerOptionsFor(source) {
    return source?.trigger_options?.length ? source.trigger_options : DEFAULT_TRIGGER_OPTIONS;
}

export function triggerLabelFor(field, source) {
    return triggerOptionsFor(source).find((option) => option.id === field)?.label || 'Phone number';
}

export function triggerStepBody(field, hint) {
    if (field === 'custom') {
        return hint?.trim() || 'The custom signal you describe';
    }

    return STEP_BODIES[field] || 'The signal you pick';
}

export function withTriggerSteps(steps = [], field, hint) {
    return steps.map((step) => (
        step.kind === 'trigger'
            ? { ...step, body: triggerStepBody(field, hint) }
            : step
    ));
}

export default function TriggerPicker({
    options,
    field,
    hint,
    onField,
    onHint,
    title = 'What should the agent look for?',
}) {
    const items = options?.length ? options : DEFAULT_TRIGGER_OPTIONS;

    return (
        <section className="space-y-3">
            <div>
                <h2 className="text-sm font-semibold text-ink">{title}</h2>
                <p className="mt-0.5 text-[13px] text-muted">Phone, city, email, or something else you describe.</p>
            </div>
            <div className="grid grid-cols-2 gap-2">
                {items.map((option) => {
                    const Icon = ICONS[option.id] || Sparkles;
                    const selected = field === option.id;
                    return (
                        <button
                            key={option.id}
                            type="button"
                            onClick={() => {
                                onField(option.id);
                                if (option.id !== 'custom') {
                                    onHint('');
                                }
                            }}
                            className={`rounded-xl border px-3 py-2.5 text-start transition ${
                                selected ? 'border-coral bg-coral/5 shadow-[0_0_0_1px_#e85d4c]' : 'border-line bg-white hover:bg-bubble'
                            }`}
                        >
                            <span className="flex items-center gap-2">
                                <Icon size={15} className={selected ? 'text-coral' : 'text-muted'} />
                                <span className="text-[13px] font-semibold text-ink">{option.label}</span>
                            </span>
                            <span className="mt-1 block text-[12px] leading-snug text-muted">{option.hint}</span>
                        </button>
                    );
                })}
            </div>
            {field === 'custom' && (
                <TextArea
                    label="Tell the agent what to look for"
                    value={hint}
                    onChange={(e) => onHint(e.target.value)}
                    rows={3}
                    maxLength={240}
                    placeholder="Example: they asked for a quote, or said they want to pay COD"
                />
            )}
        </section>
    );
}
