import { useEffect, useState } from 'react';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import { Quote } from 'lucide-react';
import { stories } from '../data';
import { Reveal } from './ui';

export default function Stories() {
    const [active, setActive] = useState(0);
    const reduce = useReducedMotion();
    const story = stories[active];

    useEffect(() => {
        if (reduce) {
            return undefined;
        }
        const id = setInterval(() => setActive((i) => (i + 1) % stories.length), 7000);
        return () => clearInterval(id);
    }, [reduce, active]);

    return (
        <section id="stories" className="scroll-mt-24 bg-cream-deep py-16 md:py-24">
            <div className="mx-auto max-w-[960px] px-5 text-center md:px-[60px]">
                <Reveal>
                    <h2 className="text-3xl font-extrabold tracking-tight md:text-4xl">
                        Shops like yours, already in the chat
                    </h2>
                </Reveal>

                <div className="relative mt-10 min-h-[220px]">
                    <Quote className="mx-auto mb-4 text-coral/40" size={36} />
                    <AnimatePresence mode="wait">
                        <motion.blockquote
                            key={story.name}
                            initial={reduce ? false : { opacity: 0, y: 12 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={reduce ? undefined : { opacity: 0, y: -8 }}
                            className="text-xl font-medium leading-relaxed md:text-2xl"
                        >
                            “{story.quote}”
                            <footer className="mt-6 text-base font-semibold">
                                {story.name}
                                <span className="block text-sm font-medium text-ink/50">{story.role}</span>
                            </footer>
                        </motion.blockquote>
                    </AnimatePresence>
                </div>

                <div className="mt-8 flex justify-center gap-2">
                    {stories.map((s, i) => (
                        <button
                            key={s.name}
                            type="button"
                            aria-label={s.name}
                            onClick={() => setActive(i)}
                            className={`h-2.5 rounded-full transition-all ${i === active ? 'w-8 bg-coral' : 'w-2.5 bg-ink/15'}`}
                        />
                    ))}
                </div>
            </div>
        </section>
    );
}
