import { useState } from 'react';
import { Bot, Images, MessageCircle, ChartColumn } from 'lucide-react';
import { agent } from '../data';
import { Reveal } from './ui';

const icons = [Images, ChartColumn, MessageCircle];

export default function AgentSection() {
    const [active, setActive] = useState(0);
    const chip = agent.chips[active];

    return (
        <section id="agent" className="scroll-mt-24 bg-cream py-16 md:py-24">
            <div className="mx-auto max-w-[1320px] px-5 md:px-[60px]">
                <Reveal className="mx-auto max-w-2xl text-center">
                    <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-linear-to-br from-coral to-teal text-white">
                        <Bot size={22} />
                    </div>
                    <h2 className="text-3xl font-extrabold tracking-tight md:text-4xl">{agent.heading}</h2>
                    <p className="mt-4 text-base font-medium text-ink/65 md:text-lg">{agent.body}</p>
                </Reveal>

                <Reveal delay={0.1} className="mx-auto mt-10 max-w-3xl">
                    <div className="flex flex-wrap justify-center gap-2">
                        {agent.chips.map((c, i) => {
                            const Icon = icons[i] || Bot;
                            return (
                                <button
                                    key={c.label}
                                    type="button"
                                    onClick={() => setActive(i)}
                                    className={`inline-flex max-w-full items-center gap-2 rounded-full border px-4 py-2 text-left text-sm font-semibold transition ${
                                        i === active
                                            ? 'border-coral bg-white text-ink shadow-sm'
                                            : 'border-ink/10 bg-white/60 text-ink/70 hover:bg-white'
                                    }`}
                                >
                                    <Icon size={15} className={i === active ? 'text-coral' : ''} />
                                    {c.label}
                                </button>
                            );
                        })}
                    </div>

                    <div className="mt-6 rounded-2xl border border-ink/10 bg-white p-6 shadow-[0_24px_60px_-24px_rgb(31_42_55_/_0.18)]">
                        <div className="mb-3 flex items-center gap-2 text-sm font-semibold text-teal-dark">
                            <Bot size={16} />
                            Wasl agent
                        </div>
                        <p className="text-base font-medium leading-relaxed text-ink/80">{chip.reply}</p>
                    </div>
                </Reveal>
            </div>
        </section>
    );
}
