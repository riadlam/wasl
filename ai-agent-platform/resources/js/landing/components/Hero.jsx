import { ArrowRight } from 'lucide-react';
import { hero } from '../data';
import { useWaitlist } from '../waitlist-context';
import { CtaButton, Reveal } from './ui';
import ProductMock from './ProductMock';

export default function Hero() {
    const { openWaitlist } = useWaitlist();

    return (
        <section className="hero-mesh relative overflow-hidden pb-8 pt-10 md:pb-16 md:pt-16">
            <div className="mx-auto max-w-[1320px] px-5 md:px-[60px]">
                <Reveal immediate className="mx-auto max-w-3xl text-center">
                    <span className="inline-flex items-center rounded-full border border-teal/20 bg-teal/8 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-teal-dark">
                        {hero.eyebrow}
                    </span>
                    <h1 className="mt-5 text-[2rem] font-extrabold leading-[1.12] tracking-tight sm:text-4xl md:text-[56px] md:leading-[1.05]">
                        {hero.headline}
                    </h1>
                    <p className="mx-auto mt-5 max-w-2xl text-base font-medium text-ink/65 md:text-lg">
                        {hero.sub}
                    </p>
                    <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row sm:flex-wrap">
                        <CtaButton variant="outline" className="w-full sm:w-auto" href="#journey">
                            {hero.secondary}
                        </CtaButton>
                        <CtaButton className="w-full sm:w-auto" onClick={openWaitlist}>
                            {hero.primary}
                            <ArrowRight size={16} />
                        </CtaButton>
                    </div>
                </Reveal>

                <Reveal delay={0.15} className="relative mt-12 md:mt-16">
                    <div className="pointer-events-none absolute -left-2 top-8 hidden animate-float md:block">
                        <Bubble from="Instagram" text="Kayn f 38? Nbghi nconfirmi today 🧡" />
                    </div>
                    <div className="pointer-events-none absolute -right-2 top-24 hidden animate-float-slow md:block">
                        <Bubble from="WhatsApp" text="Livraison 58 wilaya? COD ok?" accent />
                    </div>
                    <ProductMock />
                </Reveal>
            </div>
        </section>
    );
}

function Bubble({ from, text, accent = false }) {
    return (
        <div
            className={`max-w-[230px] rounded-2xl border px-4 py-3 shadow-lg ${
                accent
                    ? 'border-teal/20 bg-white text-ink'
                    : 'border-coral/20 bg-white text-ink'
            }`}
        >
            <div className={`text-[11px] font-semibold ${accent ? 'text-teal' : 'text-coral'}`}>{from}</div>
            <p className="mt-1 text-sm font-medium">{text}</p>
        </div>
    );
}
