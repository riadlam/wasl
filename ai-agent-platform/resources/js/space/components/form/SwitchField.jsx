import { Switch } from '@headlessui/react';

export default function SwitchField({
    label,
    description,
    checked,
    onChange,
    disabled = false,
}) {
    return (
        <div className="flex items-center justify-between gap-3 py-1">
            <span className="min-w-0">
                <span className="block text-[13px] font-medium text-ink">{label}</span>
                {description && <span className="mt-0.5 block text-[12px] font-normal text-muted">{description}</span>}
            </span>
            <Switch
                checked={Boolean(checked)}
                onChange={onChange}
                disabled={disabled}
                className="group relative inline-flex h-5 w-9 shrink-0 items-center rounded-full bg-ink/15 transition data-checked:bg-coral data-disabled:opacity-50"
            >
                <span className="inline-block size-3.5 translate-x-0.5 rounded-full bg-white shadow-sm transition group-data-checked:translate-x-[18px] rtl:-translate-x-0.5 rtl:group-data-checked:-translate-x-[18px]" />
            </Switch>
        </div>
    );
}
