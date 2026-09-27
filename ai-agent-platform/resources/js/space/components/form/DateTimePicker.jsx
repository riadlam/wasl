import { Fragment, useMemo, useState } from 'react';
import { Popover, PopoverButton, PopoverPanel, Transition } from '@headlessui/react';
import { DayPicker } from 'react-day-picker';
import { format, isValid, parse, setHours, setMinutes, addHours, addDays, nextMonday, startOfDay } from 'date-fns';
import { CalendarClock, ChevronLeft, ChevronRight } from 'lucide-react';
import 'react-day-picker/style.css';
import './dateTimePicker.css';
import { labelClass } from './control';

/**
 * Compact schedule picker: trigger + small popover (calendar, time, presets).
 * Value format: local "YYYY-MM-DDTHH:mm"
 */
export default function DateTimePicker({
    label = 'Publish at',
    value = '',
    onChange,
    disabled = false,
    required = false,
    placeholder = 'Choose date & time',
}) {
    const selected = useMemo(() => parseLocal(value), [value]);
    const [month, setMonth] = useState(() => selected || new Date());

    const display = selected && isValid(selected)
        ? format(selected, 'EEE, MMM d · HH:mm')
        : '';

    const timeValue = selected && isValid(selected) ? format(selected, 'HH:mm') : '09:00';

    const applyDate = (day) => {
        if (!day) return;
        const [h, m] = timeValue.split(':').map(Number);
        const next = setMinutes(setHours(day, h || 0), m || 0);
        onChange?.(toLocal(next));
    };

    const applyTime = (time) => {
        const base = selected && isValid(selected) ? selected : new Date();
        const [h, m] = String(time || '09:00').split(':').map(Number);
        onChange?.(toLocal(setMinutes(setHours(base, h || 0), m || 0)));
    };

    const applyPreset = (date) => {
        onChange?.(toLocal(date));
        setMonth(date);
    };

    return (
        <div className="block max-w-sm">
            {label && <span className={labelClass()}>{label}</span>}
            <Popover className="relative">
                {({ close }) => (
                    <>
                        <PopoverButton
                            disabled={disabled}
                            className="flex h-10 w-full items-center gap-2 rounded-lg border border-line bg-white px-3 text-start text-[13px] font-medium text-ink outline-none transition hover:border-ink/40 focus:border-ink disabled:cursor-not-allowed disabled:bg-cream-deep disabled:text-ink/45"
                        >
                            <CalendarClock size={15} className="shrink-0 text-muted" />
                            <span className={display ? 'truncate text-ink' : 'truncate text-ink/40'}>
                                {display || placeholder}
                            </span>
                            {required && !value && <input className="sr-only" required tabIndex={-1} value="" onChange={() => {}} />}
                        </PopoverButton>

                        <Transition
                            as={Fragment}
                            enter="transition duration-100 ease-out"
                            enterFrom="opacity-0 translate-y-1"
                            enterTo="opacity-100 translate-y-0"
                            leave="transition duration-75 ease-in"
                            leaveFrom="opacity-100 translate-y-0"
                            leaveTo="opacity-0 translate-y-1"
                        >
                            <PopoverPanel className="absolute start-0 z-[90] mt-1.5 w-[268px] rounded-xl border border-line bg-white p-2.5 shadow-xl">
                                <div className="mb-2 flex flex-wrap gap-1">
                                    {presets().map((item) => (
                                        <button
                                            key={item.id}
                                            type="button"
                                            onClick={() => {
                                                applyPreset(item.date);
                                                close();
                                            }}
                                            className="rounded-md bg-bubble px-2 py-1 text-[10px] font-semibold text-ink hover:bg-line/60"
                                        >
                                            {item.label}
                                        </button>
                                    ))}
                                </div>

                                <DayPicker
                                    mode="single"
                                    month={month}
                                    onMonthChange={setMonth}
                                    selected={selected && isValid(selected) ? selected : undefined}
                                    onSelect={(day) => {
                                        if (!day) return;
                                        applyDate(day);
                                    }}
                                    disabled={{ before: startOfDay(new Date()) }}
                                    weekStartsOn={1}
                                    className="wasl-daypicker"
                                    components={{
                                        Chevron: ({ orientation }) => (
                                            orientation === 'left'
                                                ? <ChevronLeft size={14} />
                                                : <ChevronRight size={14} />
                                        ),
                                    }}
                                />

                                <div className="mt-2 flex items-center gap-2 border-t border-line pt-2">
                                    <label className="flex flex-1 items-center gap-2 text-[11px] font-semibold text-muted">
                                        Time
                                        <input
                                            type="time"
                                            value={timeValue}
                                            onChange={(e) => applyTime(e.target.value)}
                                            className="h-8 flex-1 rounded-lg border border-line bg-white px-2 text-[12px] font-semibold text-ink outline-none focus:border-ink"
                                        />
                                    </label>
                                    <button
                                        type="button"
                                        onClick={close}
                                        className="h-8 rounded-lg bg-ink px-2.5 text-[11px] font-semibold text-white"
                                    >
                                        Done
                                    </button>
                                </div>
                            </PopoverPanel>
                        </Transition>
                    </>
                )}
            </Popover>
        </div>
    );
}

function presets() {
    const now = new Date();
    return [
        { id: '1h', label: 'In 1h', date: addHours(now, 1) },
        { id: 'tm9', label: 'Tomorrow 09:00', date: setMinutes(setHours(addDays(startOfDay(now), 1), 9), 0) },
        { id: 'tm18', label: 'Tomorrow 18:00', date: setMinutes(setHours(addDays(startOfDay(now), 1), 18), 0) },
        { id: 'mon9', label: 'Mon 09:00', date: setMinutes(setHours(nextMonday(now), 9), 0) },
    ];
}

function toLocal(d) {
    return format(d, "yyyy-MM-dd'T'HH:mm");
}

function parseLocal(value) {
    if (!value) return null;
    const parsed = parse(String(value), "yyyy-MM-dd'T'HH:mm", new Date());
    return isValid(parsed) ? parsed : null;
}
