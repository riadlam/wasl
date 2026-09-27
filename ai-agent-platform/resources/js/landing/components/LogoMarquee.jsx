import { channels } from '../data';

export default function LogoMarquee() {
    const items = [...channels, ...channels];

    return (
        <section className="overflow-hidden border-y border-ink/8 bg-white py-8">
            <p className="mb-5 text-center text-sm font-medium text-ink/45">
                Meet customers where Algerian shops already sell
            </p>
            <div className="relative overflow-hidden">
                <div className="pointer-events-none absolute inset-y-0 left-0 z-10 w-16 bg-linear-to-r from-white to-transparent" />
                <div className="pointer-events-none absolute inset-y-0 right-0 z-10 w-16 bg-linear-to-l from-white to-transparent" />
                <div className="animate-marquee flex w-max gap-10 pr-10">
                    {items.map((name, i) => (
                        <span
                            key={`${name}-${i}`}
                            className="flex items-center gap-2 text-lg font-bold tracking-tight text-ink/35"
                        >
                            <span className="h-1.5 w-1.5 rounded-full bg-coral/70" />
                            {name}
                        </span>
                    ))}
                </div>
            </div>
        </section>
    );
}
