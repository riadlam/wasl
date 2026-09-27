import { weekly } from '../data';
import { useSpace } from '../context';
import PageFrame from './PageFrame';

export default function ReportsView() {
    const { conversations } = useSpace();
    const open = conversations.filter((c) => !c.blocked);
    const byChannel = ['WhatsApp', 'Instagram', 'Facebook', 'TikTok'].map((channel) => ({
        channel,
        count: open.filter((c) => c.channel === channel).length,
    }));
    const max = Math.max(...weekly.map((d) => d.value));

    return (
        <PageFrame title="Reports" subtitle="A quiet read of the week — not a spreadsheet.">
            <div className="grid gap-3 sm:grid-cols-2">
                {byChannel.map((row) => (
                    <div key={row.channel} className="rounded-2xl border border-line bg-white p-4">
                        <div className="text-sm font-semibold text-muted">{row.channel}</div>
                        <div className="mt-1 text-3xl font-extrabold text-ink">{row.count}</div>
                        <div className="text-xs text-muted">open conversations</div>
                    </div>
                ))}
            </div>
            <section className="mt-5 rounded-2xl border border-line bg-white p-4">
                <h2 className="text-sm font-bold">Volume</h2>
                <div className="mt-4 flex h-32 items-end gap-2">
                    {weekly.map((d) => (
                        <div key={d.day} className="flex flex-1 flex-col items-center gap-2">
                            <div className="flex h-24 w-full items-end">
                                <div className="w-full rounded-t-md bg-teal/70" style={{ height: `${(d.value / max) * 100}%` }} />
                            </div>
                            <span className="text-[11px] font-semibold text-muted">{d.day}</span>
                        </div>
                    ))}
                </div>
            </section>
        </PageFrame>
    );
}
