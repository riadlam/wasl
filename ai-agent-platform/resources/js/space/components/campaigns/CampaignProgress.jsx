import { Check } from 'lucide-react';
import { CAMPAIGN_STEPS } from './campaignDefaults';

/** Compact step rail — professional, not playful. */
export default function CampaignProgress({ step, onGoStep, canJumpTo }) {
    return (
        <nav aria-label="Campaign steps" className="w-full">
            <ol className="flex items-center gap-1">
                {CAMPAIGN_STEPS.map((item, index) => {
                    const done = index < step;
                    const current = index === step;
                    const jumpable = typeof canJumpTo === 'function' ? canJumpTo(index) : index <= step;
                    const isLast = index === CAMPAIGN_STEPS.length - 1;

                    return (
                        <li key={item.id} className="flex min-w-0 flex-1 items-center gap-1">
                            <button
                                type="button"
                                disabled={!jumpable}
                                onClick={() => jumpable && onGoStep?.(index)}
                                className={`flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-1.5 text-left transition ${
                                    current
                                        ? 'bg-ink text-white'
                                        : done
                                            ? 'text-ink hover:bg-ink/5'
                                            : 'text-ink/35'
                                } disabled:cursor-default`}
                                aria-current={current ? 'step' : undefined}
                                aria-label={item.label}
                            >
                                <span
                                    className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold ${
                                        current
                                            ? 'bg-white/15 text-white'
                                            : done
                                                ? 'bg-ink text-white'
                                                : 'bg-ink/8 text-ink/40'
                                    }`}
                                >
                                    {done && !current ? <Check size={11} strokeWidth={2.5} /> : index + 1}
                                </span>
                                <span className="truncate text-[12px] font-medium tracking-tight">
                                    {item.label}
                                </span>
                            </button>
                            {!isLast && (
                                <span aria-hidden className={`h-px w-2 shrink-0 sm:w-3 ${done ? 'bg-ink/30' : 'bg-ink/10'}`} />
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
