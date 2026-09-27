import { useEffect, useState } from 'react';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import { Check } from 'lucide-react';
import { journey } from '../data';
import { Reveal } from './ui';

export default function JourneyTabs() {
    const [active, setActive] = useState(0);
    const reduce = useReducedMotion();
    const tab = journey[active];

    useEffect(() => {
        if (reduce) {
            return undefined;
        }

        const id = setInterval(() => {
            setActive((i) => (i + 1) % journey.length);
        }, 8000);

        return () => clearInterval(id);
    }, [reduce, active]);

    return (
        <section id="journey" className="scroll-mt-24 bg-cream py-16 md:py-24">
            <div className="mx-auto max-w-[1320px] px-5 md:px-[60px]">
                <Reveal className="max-w-2xl">
                    <h2 className="text-3xl font-extrabold tracking-tight md:text-4xl">
                        Grow the shop with every conversation
                    </h2>
                    <p className="mt-4 text-base font-medium text-ink/65 md:text-lg">
                        When DMs multiply, old phone inboxes break. Wasl keeps capture, conversion, and follow-up in one calm workflow — even on a busy Aid week.
                    </p>
                </Reveal>

                <div className="mt-12 grid gap-10 lg:grid-cols-2 lg:gap-16">
                    <div className="grid grid-cols-[auto_1fr] gap-5">
                        <div className="flex flex-col pt-1">
                            {journey.map((item, i) => (
                                <button
                                    key={item.id}
                                    type="button"
                                    onClick={() => setActive(i)}
                                    className="relative flex h-[88px] w-1.5 justify-center"
                                    aria-label={item.title}
                                >
                                    <span className="absolute inset-0 rounded-full bg-ink/10" />
                                    {i === active && (
                                        <motion.span
                                            key={active}
                                            className="absolute inset-x-0 top-0 origin-top rounded-full bg-coral"
                                            initial={{ scaleY: 0 }}
                                            animate={{ scaleY: 1 }}
                                            transition={{ duration: reduce ? 0 : 8, ease: 'linear' }}
                                            style={{ height: '100%' }}
                                        />
                                    )}
                                </button>
                            ))}
                        </div>

                        <div>
                            {journey.map((item, i) => (
                                <button
                                    key={item.id}
                                    type="button"
                                    onClick={() => setActive(i)}
                                    className={`w-full rounded-2xl p-4 text-left transition ${
                                        i === active ? 'bg-white shadow-sm' : 'hover:bg-white/50'
                                    }`}
                                >
                                    <div className={`text-sm font-bold ${i === active ? 'text-coral' : 'text-ink/40'}`}>
                                        {item.title}
                                    </div>
                                    {i === active && (
                                        <div className="mt-2">
                                            <h3 className="text-xl font-bold md:text-2xl">{item.heading}</h3>
                                            <p className="mt-2 text-sm font-medium text-ink/65 md:text-base">{item.body}</p>
                                            <ul className="mt-3 space-y-1.5">
                                                {item.points.map((p) => (
                                                    <li key={p} className="flex items-start gap-2 text-sm text-ink/80">
                                                        <Check size={16} className="mt-0.5 shrink-0 text-teal" />
                                                        {p}
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}
                                </button>
                            ))}
                        </div>
                    </div>

                    <Reveal delay={0.1}>
                        <AnimatePresence mode="wait">
                            <motion.div
                                key={tab.id}
                                initial={reduce ? false : { opacity: 0, y: 16 }}
                                animate={{ opacity: 1, y: 0 }}
                                exit={reduce ? undefined : { opacity: 0, y: -12 }}
                                transition={{ duration: 0.35 }}
                            >
                                <JourneyVisual kind={tab.mock} />
                            </motion.div>
                        </AnimatePresence>
                    </Reveal>
                </div>
            </div>
        </section>
    );
}

function JourneyVisual({ kind }) {
    if (kind === 'leads') {
        return (
            <Panel title="Lead queue">
                {[
                    ['Amira · IG', 'Hot · 92', true],
                    ['Karim · WA', 'Hot · 88', true],
                    ['Sara · FB', 'Warm · 61', false],
                    ['Nadir · IG', 'Browse · 34', false],
                ].map(([name, score, hot]) => (
                    <div key={name} className="flex items-center justify-between rounded-xl bg-cream px-3 py-3">
                        <span className="font-semibold">{name}</span>
                        <span className={`rounded-full px-2.5 py-1 text-xs font-bold ${hot ? 'bg-coral/10 text-coral' : 'bg-ink/6 text-ink/50'}`}>
                            {score}
                        </span>
                    </div>
                ))}
            </Panel>
        );
    }

    if (kind === 'content') {
        return (
            <Panel title="Content studio">
                <div className="grid grid-cols-5 gap-2">
                    {['Hook', 'Look 1', 'Look 2', 'Look 3', 'CTA'].map((slide, i) => (
                        <div
                            key={slide}
                            className="flex aspect-3/4 flex-col justify-end rounded-xl bg-linear-to-br from-coral/20 via-apricot/30 to-teal/20 p-2 text-[10px] font-bold"
                            style={{ transform: `translateY(${i % 2 === 0 ? 0 : 8}px)` }}
                        >
                            {slide}
                        </div>
                    ))}
                </div>
                <p className="rounded-xl bg-cream px-3 py-3 text-sm text-ink/70">
                    “Carousel ready — 5 slides, French caption, WhatsApp link on the last frame.”
                </p>
            </Panel>
        );
    }

    return (
        <Panel title="Incoming">
            {[
                ['WhatsApp', 'Voice note · Constantine'],
                ['Instagram', 'Story reply · Algiers'],
                ['Facebook', 'Comment on new drop'],
                ['Messenger', 'Asked for COD'],
            ].map(([ch, meta]) => (
                <div key={ch} className="flex items-center gap-3 rounded-xl bg-cream px-3 py-3">
                    <span className="flex h-9 w-9 items-center justify-center rounded-full bg-teal/15 text-xs font-bold text-teal-dark">
                        {ch.slice(0, 2)}
                    </span>
                    <div>
                        <div className="font-semibold">{ch}</div>
                        <div className="text-xs text-ink/50">{meta}</div>
                    </div>
                </div>
            ))}
        </Panel>
    );
}

function Panel({ title, children }) {
    return (
        <div className="rounded-2xl border border-ink/10 bg-white p-5 shadow-[0_24px_60px_-24px_rgb(31_42_55_/_0.18)]">
            <div className="mb-4 text-sm font-semibold text-ink/45">{title}</div>
            <div className="space-y-2.5">{children}</div>
        </div>
    );
}
