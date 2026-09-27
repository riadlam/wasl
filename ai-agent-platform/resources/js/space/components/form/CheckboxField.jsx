import { Checkbox } from '@headlessui/react';
import { Check } from 'lucide-react';

export default function CheckboxField({
    label,
    checked,
    onChange,
    disabled = false,
    trailing,
}) {
    return (
        <label className={`flex cursor-pointer items-center gap-2.5 rounded-lg px-1 py-1.5 text-[13px] font-medium text-ink hover:bg-bubble ${disabled ? 'opacity-50' : ''}`}>
            <Checkbox
                checked={Boolean(checked)}
                onChange={onChange}
                disabled={disabled}
                className="group flex h-4 w-4 shrink-0 items-center justify-center rounded border border-line bg-white data-checked:border-coral data-checked:bg-coral data-disabled:cursor-not-allowed"
            >
                <Check size={11} strokeWidth={3} className="hidden text-white group-data-checked:block" />
            </Checkbox>
            <span className="min-w-0 flex-1 truncate">{label}</span>
            {trailing}
        </label>
    );
}
