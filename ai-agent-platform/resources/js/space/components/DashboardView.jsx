import { useSpace } from '../context';
import { SpaceHeader } from './ui';
import { openConversations, weekActivity } from '../setup';

export default function DashboardView() {
    const { conversations, shopSignals, openInboxConversation, setView } = useSpace();
    const open = openConversations(conversations);
    const hot = open.filter((c) => c.lifecycle === 'hot');
    const unreplied = open.filter((c) => c.unreplied);
    const pending = shopSignals?.pendingOrders || [];
    const week = weekActivity(conversations);
    const weekMax = Math.max(0, ...week.map((d) => d.value));
    const showWeek = week.some((d) => d.value > 0);
    const recent = [...open].sort((a, b) => (b.sortAt || 0) - (a.sortAt || 0)).slice(0, 6);

    return (
        <div className="flex h-full min-h-0 flex-col bg-white">
            <div className="min-h-0 flex-1 overflow-y-auto">
                <SpaceHeader title="Today" subtitle="Chats, hot leads, and what still needs a human." />
                <div className="px-4 py-4 sm:px-6">
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Stat label="Open chats" value={open.length} />
                        <Stat label="Unreplied" value={unreplied.length} accent />
                        <Stat label="Hot" value={hot.length} />
                        <Stat label="Pending orders" value={pending.length} />
                    </div>

                    {showWeek && (
                        <section className="mt-5 rounded-xl border border-line bg-white p-4">
                            <h2 className="text-sm font-semibold text-ink">Inbox this week</h2>
                            <div className="mt-4 flex h-32 items-end gap-2">
                                {week.map((d) => (
                                    <div key={d.key} className="flex flex-1 flex-col items-center gap-2">
                                        <div className="flex h-24 w-full items-end">
                                            <div
                                                className="w-full rounded-t-md bg-accent/80"
                                                style={{ height: `${weekMax ? Math.max(8, (d.value / weekMax) * 100) : 8}%` }}
                                            />
                                        </div>
                                        <span className="text-[11px] font-medium text-muted">{d.day}</span>
                                    </div>
                                ))}
                            </div>
                        </section>
                    )}

                    <div className="mt-5 grid gap-5 lg:grid-cols-2">
                        <section>
                            <h2 className="mb-2 text-sm font-semibold text-ink">Needs attention</h2>
                            {unreplied.length === 0 ? (
                                <p className="rounded-xl border border-line px-4 py-8 text-center text-[13px] text-muted">Caught up. No unreplied chats.</p>
                            ) : (
                                <ThreadList
                                    items={unreplied.slice(0, 8)}
                                    onOpen={openInboxConversation}
                                />
                            )}
                        </section>
                        <section>
                            <h2 className="mb-2 text-sm font-semibold text-ink">Recent</h2>
                            {recent.length === 0 ? (
                                <div className="rounded-xl border border-line px-4 py-8 text-center">
                                    <p className="text-[13px] text-muted">No conversations yet.</p>
                                    <button
                                        type="button"
                                        onClick={() => setView('inbox')}
                                        className="mt-3 h-9 rounded-lg bg-coral px-3 text-[13px] font-semibold text-white"
                                    >
                                        Open inbox
                                    </button>
                                </div>
                            ) : (
                                <ThreadList items={recent} onOpen={openInboxConversation} />
                            )}
                        </section>
                    </div>
                </div>
            </div>
        </div>
    );
}

function ThreadList({ items, onOpen }) {
    return (
        <div className="overflow-hidden rounded-xl border border-line bg-white">
            {items.map((c) => (
                <button
                    key={c.id}
                    type="button"
                    onClick={() => onOpen(c.id, c.status)}
                    className="flex w-full items-center justify-between gap-3 border-b border-line px-4 py-3 text-left last:border-0 hover:bg-bubble"
                >
                    <span className="min-w-0">
                        <span className="block truncate text-[13px] font-medium text-ink">{c.name}</span>
                        <span className="block truncate text-[12px] text-muted">{c.preview || '—'}</span>
                    </span>
                    <span className="shrink-0 text-[11px] text-muted">{c.time}</span>
                </button>
            ))}
        </div>
    );
}

function Stat({ label, value, accent }) {
    return (
        <div className={`rounded-xl border px-3 py-2 ${accent ? 'border-coral/30 bg-coral/5' : 'border-line bg-white'}`}>
            <div className="text-[10px] font-semibold uppercase tracking-wide text-muted">{label}</div>
            <div className="text-lg font-semibold text-ink">{value}</div>
        </div>
    );
}
