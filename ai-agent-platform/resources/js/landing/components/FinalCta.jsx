import { ArrowRight } from 'lucide-react';
import { finalCta } from '../data';
import { useWaitlist } from '../waitlist-context';
import { CtaButton, Reveal } from './ui';

export default function FinalCta() {
    const { openWaitlist } = useWaitlist();

    return (
        <section id="cta" className="scroll-mt-24 px-5 py-16 md:px-[60px] md:py-20">
            <Reveal>
                <div className="cta-band mx-auto max-w-[1320px] overflow-hidden rounded-3xl px-6 py-14 text-center text-white md:px-16 md:py-20">
                    <h2 className="text-3xl font-extrabold tracking-tight md:text-5xl">{finalCta.heading}</h2>
                    <p className="mx-auto mt-4 max-w-xl text-base font-medium text-white/85 md:text-lg">
                        {finalCta.body}
                    </p>
                    <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
                        <CtaButton variant="onband" href="#agent">
                            {finalCta.secondary}
                        </CtaButton>
                        <CtaButton variant="light" onClick={openWaitlist}>
                            {finalCta.primary}
                            <ArrowRight size={16} />
                        </CtaButton>
                    </div>
                </div>
            </Reveal>
        </section>
    );
}
