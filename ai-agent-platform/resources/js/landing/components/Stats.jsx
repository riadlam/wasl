import { useEffect, useRef, useState } from 'react';
import { stats } from '../data';
import { Reveal } from './ui';

export default function Stats() {
    return (
        <section className="bg-white py-16 md:py-20">
            <div className="mx-auto grid max-w-[1320px] gap-8 px-5 sm:grid-cols-2 md:px-[60px] lg:grid-cols-4">
                {stats.map((item, i) => (
                    <Reveal key={item.label} delay={i * 0.08} className="text-center lg:text-left">
                        <div className="text-5xl font-extrabold tracking-tight text-coral">
                            <CountUp value={item.value} />
                            {item.suffix}
                        </div>
                        <p className="mt-2 text-sm font-medium text-ink/60">{item.label}</p>
                    </Reveal>
                ))}
            </div>
        </section>
    );
}

function CountUp({ value }) {
    const [n, setN] = useState(0);
    const ref = useRef(null);
    const started = useRef(false);

    useEffect(() => {
        const el = ref.current?.parentElement;
        if (!el) {
            return undefined;
        }

        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const io = new IntersectionObserver(
            ([entry]) => {
                if (!entry.isIntersecting || started.current) {
                    return;
                }
                started.current = true;
                if (reduce) {
                    setN(value);
                    return;
                }
                const start = performance.now();
                const tick = (now) => {
                    const t = Math.min(1, (now - start) / 900);
                    setN(Math.round(value * t));
                    if (t < 1) {
                        requestAnimationFrame(tick);
                    }
                };
                requestAnimationFrame(tick);
            },
            { threshold: 0.4 },
        );

        io.observe(el);
        return () => io.disconnect();
    }, [value]);

    return <span ref={ref}>{n}</span>;
}
