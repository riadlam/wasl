import { useQuery } from '@tanstack/react-query';
import { api } from '../../api';
import { platformMeta } from '../channels/platforms';
import { CONTENT_MODES, campaignPayload, campaignTotals, channelLabel, minImagesForPosts } from './campaignDefaults';
import { formatDa } from './campaignStatus';

export default function StepReview({ state, accounts, launchNote }) {
    const payload = campaignPayload(state, []);
    const estimate = useQuery({
        queryKey: ['agent', 'campaigns', 'estimate', payload],
        queryFn: async () => (await api.post('/agent/campaigns/estimate', payload)).data?.estimate,
        staleTime: 30000,
        retry: false,
    });
    const selected = accounts.filter((a) => state.channelIds.includes(Number(a.id)));
    const totals = campaignTotals(state.days);
    const modeMeta = CONTENT_MODES[state.contentMode] || CONTENT_MODES.ai_recent;
    const minImages = minImagesForPosts(totals.posts);

    return (
        <div>
            <h2 className="cw-display text-[15px] font-semibold text-ink">Review</h2>
            <p className="mt-1 text-[13px] leading-relaxed text-ink/50">
                Confirm setup before launch.
            </p>

            {state.name?.trim() && (
                <p className="mt-3 text-[14px] font-semibold text-ink">{state.name.trim()}</p>
            )}

            {launchNote && (
                <p className="mt-3 rounded-lg bg-teal/10 px-3 py-2 text-[12px] font-medium text-teal-dark" role="status">
                    {launchNote}
                </p>
            )}

            <div className="mt-4 grid gap-2.5 sm:grid-cols-2">
                <Panel title="Channels">
                    <ul className="space-y-1.5">
                        {selected.map((account) => {
                            const meta = platformMeta(account.platform);
                            const Icon = meta.Icon;
                            return (
                                <li key={account.id} className="flex items-center gap-2 text-[12px]">
                                    <Icon size={13} style={{ color: meta.color }} />
                                    <span className="min-w-0 flex-1 truncate font-medium text-ink">
                                        {channelLabel(account)}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                </Panel>

                <Panel title="Totals">
                    <div className="flex gap-4 text-[12px]">
                        <span className="text-ink/45">
                            Starts <strong className="text-ink tabular-nums">{state.startsOn || '—'}</strong>
                        </span>
                        <span className="text-ink/45">Days <strong className="text-ink tabular-nums">{state.dayCount}</strong></span>
                        <span className="text-ink/45">Posts <strong className="text-ink tabular-nums">{totals.posts}</strong></span>
                        <span className="text-ink/45">Stories <strong className="text-ink tabular-nums">{totals.stories}</strong></span>
                    </div>
                </Panel>

                <Panel title="Estimated cost" className="sm:col-span-2">
                    {estimate.isLoading && <p className="text-[12px] text-ink/45">Calculating…</p>}
                    {estimate.isError && <p className="text-[12px] text-ink/45">Could not estimate the cost right now.</p>}
                    {estimate.data && (
                        <div>
                            <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-[12px]">
                                <span className="text-ink/45">
                                    Up to <strong className="text-[14px] text-ink tabular-nums">{formatDa(estimate.data.estimated_da)}</strong>
                                </span>
                                <span className="text-ink/45">
                                    {estimate.data.slots} slot{estimate.data.slots === 1 ? '' : 's'} × {estimate.data.platforms} platform
                                    {estimate.data.platforms === 1 ? '' : 's'} × {formatDa(estimate.data.per_slot_da / Math.max(1, estimate.data.platforms))}
                                </span>
                                <span className="text-ink/45">
                                    Wallet <strong className="text-ink tabular-nums">{formatDa(estimate.data.available_da)}</strong>
                                </span>
                            </div>
                            <p className={`mt-1.5 text-[11px] leading-relaxed ${estimate.data.affordable ? 'text-ink/45' : 'font-medium text-red-700'}`}>
                                {estimate.data.affordable
                                    ? 'Charged per post as it is written, from real usage. The final amount is usually lower.'
                                    : 'Your wallet does not cover this estimate. Top up or plan fewer posts before launching.'}
                            </p>
                        </div>
                    )}
                </Panel>

                <Panel title="Cadence" className="sm:col-span-2">
                    <div className="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
                        {state.days.map((day) => (
                            <div key={day.dayIndex} className="rounded-lg bg-cream px-2.5 py-2">
                                <p className="text-[11px] font-semibold text-ink">Day {day.dayIndex}</p>
                                <p className="mt-0.5 text-[11px] text-ink/55">
                                    {day.posts}p · {day.stories}s
                                    {(day.times || []).length > 0 ? ` · ${(day.times || []).join(', ')}` : ''}
                                </p>
                            </div>
                        ))}
                    </div>
                </Panel>

                <Panel title="Content">
                    <p className="text-[13px] font-semibold text-ink">{modeMeta.title}</p>
                    <p className="mt-1 text-[12px] leading-relaxed text-ink/50">{modeMeta.request}</p>
                    {state.contentMode === 'ai_recent' && (
                        <p className="mt-2 rounded-lg bg-cream px-2.5 py-2 text-[12px] leading-relaxed text-ink">
                            “{state.focusPrompt.trim()}”
                        </p>
                    )}
                    {state.contentMode === 'product_images' && (
                        <p className="mt-2 text-[12px] text-ink/50">
                            {state.images.length} images (min {minImages})
                        </p>
                    )}
                </Panel>

                {state.contentMode === 'product_images' && state.images.length > 0 && (
                    <Panel title="Uploads">
                        <div className="flex flex-wrap gap-1.5">
                            {state.images.slice(0, 6).map((img) => (
                                <img
                                    key={img.id}
                                    src={img.url}
                                    alt=""
                                    className="h-10 w-10 rounded-md object-cover ring-1 ring-ink/10"
                                />
                            ))}
                        </div>
                    </Panel>
                )}
            </div>
        </div>
    );
}

function Panel({ title, children, className = '' }) {
    return (
        <section className={`rounded-xl border border-ink/8 bg-white px-3.5 py-3 ${className}`}>
            <h3 className="text-[10px] font-semibold uppercase tracking-[0.1em] text-ink/40">{title}</h3>
            <div className="mt-2">{children}</div>
        </section>
    );
}
