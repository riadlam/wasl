import { useQuery } from '@tanstack/react-query';
import { ChevronRight, LoaderCircle, Plus, Sparkles } from 'lucide-react';
import { api } from '../../api';
import { queryKeys } from '../../query';
import { LIVE_STATUSES, StatusPill, formatDa, formatWhen } from './campaignStatus';

export default function CampaignsList({ onNew, onOpen }) {
    const query = useQuery({
        queryKey: queryKeys.campaigns,
        queryFn: async () => (await api.get('/agent/campaigns')).data?.campaigns || [],
        refetchInterval: (q) => {
            const rows = q.state.data || [];
            return rows.some((c) => LIVE_STATUSES.includes(c.status)) ? 15000 : false;
        },
    });
    const rows = query.data || [];

    return (
        <div className="h-full overflow-y-auto bg-white">
            <div className="mx-auto w-full max-w-3xl px-4 py-5 sm:px-6 sm:py-6">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-coral">Wasl</p>
                        <h1 className="cw-display mt-0.5 text-[1.35rem] font-semibold tracking-tight text-ink">AI Campaigns</h1>
                    </div>
                    <button
                        type="button"
                        onClick={onNew}
                        className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-coral px-3.5 text-[13px] font-semibold text-white"
                    >
                        <Plus size={15} />
                        New campaign
                    </button>
                </div>

                {query.isLoading && (
                    <div className="mt-10 flex justify-center text-ink/40">
                        <LoaderCircle size={18} className="animate-spin" />
                    </div>
                )}

                {query.isError && (
                    <p className="mt-6 rounded-lg bg-red-50 px-3 py-2 text-[12px] text-red-700">Could not load campaigns.</p>
                )}

                {!query.isLoading && !query.isError && rows.length === 0 && (
                    <div className="mt-8 rounded-xl border border-dashed border-ink/15 px-6 py-10 text-center">
                        <Sparkles size={20} className="mx-auto text-coral" />
                        <p className="mt-3 text-[14px] font-semibold text-ink">No campaigns yet</p>
                        <p className="mx-auto mt-1 max-w-sm text-[12px] leading-relaxed text-ink/50">
                            Plan up to 7 days of posts and stories. Wasl writes each one shortly before it goes out,
                            using your products and recent posts.
                        </p>
                    </div>
                )}

                {rows.length > 0 && (
                    <ul className="mt-5 divide-y divide-ink/8 overflow-hidden rounded-xl border border-ink/8">
                        {rows.map((c) => {
                            const p = c.progress || {};
                            const total = p.total || 0;
                            const done = (p.scheduled || 0) + (p.failed || 0) + (p.cancelled || 0);
                            return (
                                <li key={c.id}>
                                    <button
                                        type="button"
                                        onClick={() => onOpen(c.id)}
                                        className="flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-cream/60"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="truncate text-[13px] font-semibold text-ink">{c.name || `Campaign #${c.id}`}</span>
                                                <StatusPill status={c.status} />
                                            </div>
                                            <p className="mt-0.5 text-[11px] text-ink/50">
                                                {c.day_count} day{c.day_count === 1 ? '' : 's'} from {c.starts_on}
                                                {' · '}
                                                {p.scheduled || 0}/{total} scheduled
                                                {p.failed ? ` · ${p.failed} failed` : ''}
                                                {c.next_slot_at && LIVE_STATUSES.includes(c.status) ? ` · next ${formatWhen(c.next_slot_at)}` : ''}
                                            </p>
                                            {total > 0 && (
                                                <div className="mt-1.5 h-1 w-full overflow-hidden rounded-full bg-ink/8">
                                                    <div className="h-full bg-teal" style={{ width: `${Math.round((done / total) * 100)}%` }} />
                                                </div>
                                            )}
                                        </div>
                                        <div className="hidden shrink-0 text-right sm:block">
                                            <p className="text-[12px] font-semibold tabular-nums text-ink">{formatDa(c.spent_da)}</p>
                                            <p className="text-[10px] text-ink/40">of ~{formatDa(c.estimated_da)}</p>
                                        </div>
                                        <ChevronRight size={16} className="shrink-0 text-ink/30" />
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </div>
    );
}
