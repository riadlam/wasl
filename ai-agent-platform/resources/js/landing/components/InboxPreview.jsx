import { inbox } from '../data';
import { Reveal } from './ui';

export default function InboxPreview() {
    return (
        <section id="inbox" className="scroll-mt-24 bg-white py-16 md:py-24">
            <div className="mx-auto grid max-w-[1320px] items-center gap-12 px-5 md:px-[60px] lg:grid-cols-2">
                <Reveal>
                    <h2 className="text-3xl font-extrabold tracking-tight md:text-4xl">{inbox.heading}</h2>
                    <p className="mt-4 text-base font-medium text-ink/65 md:text-lg">{inbox.body}</p>
                    <div className="mt-8 flex flex-wrap gap-2">
                        {['WhatsApp', 'Instagram', 'Facebook', 'TikTok'].map((ch) => (
                            <span
                                key={ch}
                                className="rounded-full border border-ink/10 bg-cream px-3 py-1.5 text-sm font-semibold"
                            >
                                {ch}
                            </span>
                        ))}
                    </div>
                </Reveal>

                <Reveal delay={0.1}>
                    <div className="rounded-2xl border border-ink/10 bg-cream p-5 shadow-[0_24px_60px_-24px_rgb(31_42_55_/_0.18)]">
                        <div className="mb-4 flex items-center gap-3">
                            <div className="h-11 w-11 rounded-full bg-linear-to-br from-coral to-apricot" />
                            <div>
                                <div className="font-bold">Sara · one customer</div>
                                <div className="text-xs text-ink/50">IG yesterday · WhatsApp today</div>
                            </div>
                        </div>
                        <div className="space-y-3">
                            <Note channel="Instagram" text="Loved the story — is the green set still available?" />
                            <Note channel="WhatsApp" text="Voice note: nbghi ncommandid l Constantine, COD." right />
                            <Note channel="Wasl agent" text="Green set is in stock. COD to Constantine is 650 DA, arrives in 48h. Shall I confirm?" agent />
                        </div>
                    </div>
                </Reveal>
            </div>
        </section>
    );
}

function Note({ channel, text, right = false, agent = false }) {
    return (
        <div className={`flex ${right ? 'justify-end' : ''}`}>
            <div
                className={`max-w-[85%] rounded-2xl px-3.5 py-2.5 text-sm ${
                    agent
                        ? 'rounded-tl-md bg-teal text-white'
                        : right
                          ? 'rounded-tr-md bg-white shadow-sm'
                          : 'rounded-tl-md bg-white shadow-sm'
                }`}
            >
                <div className={`text-[11px] font-semibold ${agent ? 'text-white/80' : 'text-coral'}`}>{channel}</div>
                <p className="mt-0.5">{text}</p>
            </div>
        </div>
    );
}
