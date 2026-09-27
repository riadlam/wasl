import { Languages, Sparkles, Store, TrendingUp } from 'lucide-react';
import { why } from '../data';
import { Reveal } from './ui';

const icons = [Store, Languages, Sparkles, TrendingUp];

export default function WhyWasl() {
    return (
        <section id="why" className="scroll-mt-24 bg-white py-16 md:py-24">
            <div className="mx-auto max-w-[1320px] px-5 md:px-[60px]">
                <Reveal className="max-w-2xl">
                    <h2 className="text-3xl font-extrabold tracking-tight md:text-4xl">
                        Here’s how Wasl sets you up
                    </h2>
                    <p className="mt-4 text-base font-medium text-ink/65 md:text-lg">
                        Same rhythm as the platforms you already envy — rebuilt for Algerian founders who sell in the DMs.
                    </p>
                </Reveal>

                <div className="mt-12 grid gap-5 md:grid-cols-2">
                    {why.map((item, i) => {
                        const Icon = icons[i];
                        return (
                            <Reveal key={item.title} delay={i * 0.06}>
                                <div className="h-full rounded-2xl border border-ink/8 bg-cream p-6 transition hover:-translate-y-0.5 hover:shadow-[0_24px_60px_-24px_rgb(31_42_55_/_0.18)]">
                                    <div className="mb-4 flex h-11 w-11 items-center justify-center rounded-xl bg-white text-coral shadow-sm">
                                        <Icon size={20} />
                                    </div>
                                    <h3 className="text-xl font-bold">{item.title}</h3>
                                    <p className="mt-2 text-sm font-medium leading-relaxed text-ink/65 md:text-base">
                                        {item.body}
                                    </p>
                                </div>
                            </Reveal>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}
