import { useEffect, useState } from 'react';
import { Sparkles } from 'lucide-react';

const threads = [
    { name: 'Amira', channel: 'IG', preview: 'Kayn f beige, taille M?', hot: true, time: '2m' },
    { name: 'Karim', channel: 'WA', preview: 'COD pour Alger centre?', hot: true, time: '5m' },
    { name: 'Lina', channel: 'FB', preview: 'Prix du set orange?', hot: false, time: '12m' },
    { name: 'Yacine', channel: 'WA', preview: 'Vous livrez Constantine?', hot: false, time: '18m' },
];

const typedFull = 'Oui Amira 🌿 Le beige M est en stock à Oran. Je te le réserve jusqu’à ce soir — tu confirmes ?';

export default function ProductMock() {
    const [typed, setTyped] = useState('');

    useEffect(() => {
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduce) {
            setTyped(typedFull);
            return undefined;
        }

        let i = 0;
        const id = setInterval(() => {
            i += 1;
            setTyped(typedFull.slice(0, i));
            if (i >= typedFull.length) {
                clearInterval(id);
            }
        }, 28);

        return () => clearInterval(id);
    }, []);

    return (
        <div className="relative mx-auto max-w-[1000px]">
            <div className="min-w-0 overflow-hidden rounded-2xl border border-ink/10 bg-white shadow-[0_24px_60px_-24px_rgb(31_42_55_/_0.22)]">
                <div className="flex items-center gap-2 border-b border-ink/8 bg-cream-deep/70 px-4 py-3">
                    <span className="h-2.5 w-2.5 rounded-full bg-coral/80" />
                    <span className="h-2.5 w-2.5 rounded-full bg-apricot" />
                    <span className="h-2.5 w-2.5 rounded-full bg-teal" />
                    <div className="ml-3 hidden gap-4 text-sm font-medium text-ink/55 sm:flex">
                        <span className="text-ink">Inbox</span>
                        <span>Leads</span>
                        <span>Agent</span>
                        <span>Content</span>
                        <span>Stats</span>
                    </div>
                </div>

                <div className="grid min-h-[340px] grid-cols-1 md:grid-cols-[220px_1fr_200px]">
                    <aside className="hidden border-r border-ink/8 md:block">
                        {threads.map((t, i) => (
                            <div
                                key={t.name}
                                className={`flex gap-3 px-3 py-3 ${i === 0 ? 'bg-coral/6' : ''}`}
                            >
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-coral/80 to-apricot text-xs font-bold text-white">
                                    {t.name[0]}
                                </div>
                                <div className="min-w-0">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-sm font-semibold">{t.name}</span>
                                        <span className="text-[11px] text-ink/40">{t.time}</span>
                                    </div>
                                    <p className="truncate text-xs text-ink/55">{t.preview}</p>
                                </div>
                            </div>
                        ))}
                    </aside>

                    <div className="flex flex-col bg-cream/40 p-4">
                        <div className="mb-4 flex items-center justify-between">
                            <div>
                                <div className="font-semibold">Amira · Instagram</div>
                                <div className="text-xs text-ink/50">Oran · Fashion · Hot lead</div>
                            </div>
                            <span className="rounded-full bg-coral/10 px-2.5 py-1 text-xs font-semibold text-coral">Score 92</span>
                        </div>

                        <div className="mb-3 max-w-[80%] rounded-2xl rounded-tl-md bg-white px-3.5 py-2.5 text-sm shadow-sm">
                            Salam! Kayn f beige, taille M? Nbghi nconfirmi today 🧡
                        </div>

                        <div className="ml-auto max-w-[85%] break-words rounded-2xl rounded-tr-md bg-teal px-3.5 py-2.5 text-sm text-white shadow-sm">
                            {typed}
                            {typed.length < typedFull.length && (
                                <span className="ml-0.5 inline-block h-3 w-0.5 animate-pulse bg-white align-middle" />
                            )}
                        </div>

                        <div className="mt-auto flex items-center gap-2 rounded-xl border border-ink/8 bg-white px-3 py-2 text-sm text-ink/45">
                            <Sparkles size={14} className="text-coral" />
                            Agent is drafting a reply…
                        </div>
                    </div>

                    <aside className="hidden border-l border-ink/8 p-4 md:block">
                        <div className="text-xs font-semibold uppercase tracking-wide text-ink/40">Lead</div>
                        <div className="mt-2 space-y-2">
                            <Chip color="coral">Hot</Chip>
                            <Chip color="teal">Fashion</Chip>
                            <Chip color="apricot">Oran</Chip>
                            <Chip>Darija</Chip>
                        </div>
                        <button
                            type="button"
                            className="mt-4 w-full rounded-lg bg-coral py-2 text-sm font-semibold text-white"
                        >
                            Take over
                        </button>
                    </aside>
                </div>
            </div>
        </div>
    );
}

function Chip({ children, color }) {
    const map = {
        coral: 'bg-coral/10 text-coral',
        teal: 'bg-teal/10 text-teal-dark',
        apricot: 'bg-apricot/15 text-ink',
    };

    return (
        <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${map[color] || 'bg-ink/6 text-ink/70'}`}>
            {children}
        </span>
    );
}
