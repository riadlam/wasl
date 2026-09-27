import { Listbox, ListboxButton, ListboxOption, ListboxOptions } from '@headlessui/react';
import { Check, ChevronDown } from 'lucide-react';
import { controlClass, hintClass, labelClass } from './control';

export default function SelectField({
    label,
    value,
    onChange,
    options = [],
    error,
    hint,
    disabled = false,
}) {
    const selected = options.find((option) => option.value === value) ?? options[0];

    return (
        <div>
            {label && <span className={labelClass()}>{label}</span>}
            <Listbox value={value} onChange={onChange} disabled={disabled}>
                <ListboxButton className={`${controlClass(Boolean(error))} flex items-center justify-between gap-2 text-start`}>
                    <span className="truncate">{selected?.label ?? 'Select'}</span>
                    <ChevronDown size={16} className="shrink-0 text-ink/50" />
                </ListboxButton>
                <ListboxOptions
                    anchor="bottom start"
                    className="z-50 mt-1 max-h-60 w-[var(--button-width)] overflow-auto rounded-lg border border-line bg-white p-1 shadow-card"
                >
                    {options.map((option) => (
                        <ListboxOption
                            key={option.value}
                            value={option.value}
                            className="flex cursor-pointer items-center justify-between gap-2 rounded-md px-3 py-2 text-[13px] font-medium text-ink data-focus:bg-bubble data-selected:text-coral"
                        >
                            {({ selected: isSelected }) => (
                                <>
                                    <span className="truncate">{option.label}</span>
                                    {isSelected && <Check size={14} className="shrink-0" />}
                                </>
                            )}
                        </ListboxOption>
                    ))}
                </ListboxOptions>
            </Listbox>
            {error ? (
                <p className={hintClass(true)}>{error}</p>
            ) : hint ? (
                <p className={hintClass()}>{hint}</p>
            ) : null}
        </div>
    );
}
