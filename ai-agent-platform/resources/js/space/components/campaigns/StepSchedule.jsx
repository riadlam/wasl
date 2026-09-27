import { useState } from 'react';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import { Pencil, Plus, X } from 'lucide-react';
import { MAX_CAMPAIGN_DAYS, campaignTotals, dateForCampaignDay, defaultStartsOn, resizeScheduleDays, todayDateInput } from './campaignDefaults';

export default function StepSchedule({ state, onChange, errors }) {
    const reduceMotion = useReducedMotion();
    const hasDays = (state.dayCount || 0) >= 1 && (state.days || []).length > 0;
    const [phase, setPhase] = useState(hasDays ? 'configure' : 'pick');
    const totals = campaignTotals(state.days);
    const dayOptions = Array.from({ length: MAX_CAMPAIGN_DAYS }, (_, i) => i + 1);
    const startsOn = state.startsOn || defaultStartsOn();
    const minDate = todayDateInput();

    const pickDays = (count) => {
        onChange({
            dayCount: count,
            days: resizeScheduleDays(count, state.days),
            startsOn: state.startsOn || defaultStartsOn(),
        });
        setPhase('configure');
    };

    const setStartsOn = (value) => {
        onChange({ startsOn: value || defaultStartsOn() });
    };

    const updateDay = (index, patch) => {
        const days = state.days.map((day, i) => (i === index ? { ...day, ...patch } : day));
        onChange({ days });
    };

    const addTime = (index) => {
        const day = state.days[index];
        updateDay(index, { times: [...(day.times || []), '14:00'] });
    };

    const removeTime = (dayIndex, timeIndex) => {
        const day = state.days[dayIndex];
        updateDay(dayIndex, {
            times: (day.times || []).filter((_, i) => i !== timeIndex),
        });
    };

    const setTime = (dayIndex, timeIndex, value) => {
        const day = state.days[dayIndex];
        const times = [...(day.times || [])];
        times[timeIndex] = value;
        updateDay(dayIndex, { times });
    };

    return (
        <div>
            <AnimatePresence mode="wait">
                {phase === 'pick' ? (
                    <motion.div
                        key="pick"
                        initial={reduceMotion ? false : { opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={reduceMotion ? {} : { opacity: 0, y: -6 }}
                        transition={{ duration: 0.18 }}
                    >
                        <h2 className="cw-display text-[15px] font-semibold text-ink">
                            How many days should AI run?
                        </h2>
                        <p className="mt-1 max-w-md text-[13px] leading-relaxed text-ink/50">
                            Choose the start date and campaign length (1–{MAX_CAMPAIGN_DAYS} days). You’ll set posts and stories for each day next.
                        </p>

                        <label className="mt-5 block max-w-xs">
                            <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-ink/40">
                                Start date
                            </span>
                            <input
                                type="date"
                                value={startsOn}
                                min={minDate}
                                onChange={(e) => setStartsOn(e.target.value)}
                                className="mt-1.5 h-10 w-full rounded-lg border-0 bg-cream px-3 text-[13px] font-semibold tabular-nums text-ink ring-1 ring-ink/10 focus:ring-2 focus:ring-ink/20"
                            />
                        </label>
                        {errors.startsOn && (
                            <p className="mt-2 text-[12px] font-medium text-coral" role="alert">
                                {errors.startsOn}
                            </p>
                        )}

                        <div className="mt-5 grid grid-cols-4 gap-2 sm:grid-cols-7">
                            {dayOptions.map((n) => {
                                const selected = state.dayCount === n;
                                return (
                                    <button
                                        key={n}
                                        type="button"
                                        onClick={() => pickDays(n)}
                                        className={`flex h-14 flex-col items-center justify-center rounded-lg transition ${
                                            selected
                                                ? 'bg-ink text-white'
                                                : 'bg-white text-ink ring-1 ring-ink/10 hover:ring-ink/25'
                                        }`}
                                    >
                                        <span className="text-[16px] font-semibold tabular-nums">{n}</span>
                                        <span className={`text-[10px] font-medium ${selected ? 'text-white/50' : 'text-ink/40'}`}>
                                            {n === 1 ? 'day' : 'days'}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>

                        {(errors.dayCount || errors.schedule) && (
                            <p className="mt-3 text-[12px] font-medium text-coral" role="alert">
                                {errors.dayCount || errors.schedule}
                            </p>
                        )}
                    </motion.div>
                ) : (
                    <motion.div
                        key="configure"
                        initial={reduceMotion ? false : { opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={reduceMotion ? {} : { opacity: 0, y: -6 }}
                        transition={{ duration: 0.2 }}
                    >
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 className="cw-display text-[15px] font-semibold text-ink">
                                    Posts & stories per day
                                </h2>
                                <p className="mt-1 text-[13px] leading-relaxed text-ink/50">
                                    Set how much content AI should prepare for each day.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setPhase('pick')}
                                className="inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-[12px] font-medium text-ink/55 ring-1 ring-ink/10 hover:bg-ink/5 hover:text-ink"
                            >
                                <Pencil size={12} />
                                {state.dayCount} day{state.dayCount === 1 ? '' : 's'}
                            </button>
                        </div>

                        <label className="mt-4 block max-w-xs">
                            <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-ink/40">
                                Start date
                            </span>
                            <input
                                type="date"
                                value={startsOn}
                                min={minDate}
                                onChange={(e) => setStartsOn(e.target.value)}
                                className="mt-1.5 h-10 w-full rounded-lg border-0 bg-cream px-3 text-[13px] font-semibold tabular-nums text-ink ring-1 ring-ink/10 focus:ring-2 focus:ring-ink/20"
                            />
                        </label>
                        {errors.startsOn && (
                            <p className="mt-2 text-[12px] font-medium text-coral" role="alert">
                                {errors.startsOn}
                            </p>
                        )}

                        <div className="mt-3 flex gap-3 text-[12px] font-medium text-ink/50">
                            <span><span className="tabular-nums text-ink">{totals.posts}</span> posts total</span>
                            <span><span className="tabular-nums text-ink">{totals.stories}</span> stories total</span>
                        </div>

                        {(errors.schedule || errors.dayCount) && (
                            <p className="mt-2 text-[12px] font-medium text-coral" role="alert">
                                {errors.schedule || errors.dayCount}
                            </p>
                        )}

                        <ul className="mt-4 space-y-2">
                            <AnimatePresence initial={!reduceMotion}>
                                {state.days.map((day, index) => {
                                    const dayErr = errors[`day${index}times`];
                                    return (
                                        <motion.li
                                            key={`${state.dayCount}-${day.dayIndex}`}
                                            initial={reduceMotion ? false : { opacity: 0, y: 12 }}
                                            animate={{ opacity: 1, y: 0 }}
                                            transition={
                                                reduceMotion
                                                    ? { duration: 0 }
                                                    : { duration: 0.22, delay: index * 0.05, ease: 'easeOut' }
                                            }
                                            className="rounded-xl border border-ink/8 bg-white px-3.5 py-3.5"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <h3 className="text-[13px] font-semibold text-ink">
                                                    Day {day.dayIndex}
                                                    <span className="ms-2 text-[11px] font-medium text-ink/35">
                                                        {dateForCampaignDay(startsOn, day.dayIndex)
                                                            || `of ${state.dayCount}`}
                                                    </span>
                                                </h3>
                                                <div className="flex items-center gap-3">
                                                    <InlineCount
                                                        label="Posts"
                                                        value={day.posts}
                                                        onChange={(v) => updateDay(index, { posts: v })}
                                                    />
                                                    <InlineCount
                                                        label="Stories"
                                                        value={day.stories}
                                                        onChange={(v) => updateDay(index, { stories: v })}
                                                    />
                                                </div>
                                            </div>

                                            <div className="mt-3">
                                                <span className="text-[11px] font-medium text-ink/40">Posting hours</span>
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    {(day.times || []).map((time, ti) => (
                                                        <div
                                                            key={`${index}-${ti}`}
                                                            className="flex items-center gap-1 rounded-lg border border-ink/12 bg-white px-1.5 py-1 shadow-[0_1px_2px_rgb(18_24_31_/_0.04)]"
                                                        >
                                                            <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-cream text-[10px] font-semibold tabular-nums text-ink/45">
                                                                {ti + 1}
                                                            </span>
                                                            <input
                                                                type="time"
                                                                value={time}
                                                                onChange={(e) => setTime(index, ti, e.target.value)}
                                                                className="min-w-[6.5rem] border-0 bg-transparent py-1.5 pe-1 ps-1 text-[13px] font-semibold tabular-nums text-ink focus:ring-0"
                                                            />
                                                            <button
                                                                type="button"
                                                                onClick={() => removeTime(index, ti)}
                                                                className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-ink/30 transition hover:bg-cream hover:text-ink"
                                                                aria-label="Remove time"
                                                            >
                                                                <X size={13} />
                                                            </button>
                                                        </div>
                                                    ))}
                                                    <button
                                                        type="button"
                                                        onClick={() => addTime(index)}
                                                        className="inline-flex h-[38px] items-center gap-1 rounded-lg border border-dashed border-ink/15 bg-cream/50 px-3 text-[12px] font-semibold text-ink/50 transition hover:border-coral/40 hover:bg-coral/5 hover:text-coral"
                                                    >
                                                        <Plus size={13} strokeWidth={2.5} />
                                                        Add hour
                                                    </button>
                                                </div>
                                            </div>
                                            {dayErr && (
                                                <p className="mt-2 text-[11px] font-medium text-coral">{dayErr}</p>
                                            )}
                                        </motion.li>
                                    );
                                })}
                            </AnimatePresence>
                        </ul>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}

function InlineCount({ label, value, onChange }) {
    return (
        <label className="flex items-center gap-1.5 text-[11px] font-medium text-ink/45">
            {label}
            <input
                type="number"
                min={0}
                max={20}
                value={value}
                onChange={(e) => onChange(Math.max(0, Number(e.target.value) || 0))}
                className="h-7 w-12 rounded-md border-0 bg-cream px-1.5 text-center text-[12px] font-semibold tabular-nums text-ink ring-1 ring-ink/8 focus:ring-1 focus:ring-ink/20"
            />
        </label>
    );
}
