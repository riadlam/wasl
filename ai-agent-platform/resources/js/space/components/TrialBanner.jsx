import { Info } from 'lucide-react';
import { shop } from '../data';
import { useSpace } from '../context';

export default function TrialBanner() {
    const { openModal } = useSpace();

    return (
        <div className="flex flex-col gap-3 border-b border-line bg-info px-3 py-2 sm:flex-row sm:items-center sm:justify-between sm:px-4">
            <p className="flex items-start gap-2 text-[13px] font-medium text-ink">
                <Info size={15} className="mt-0.5 shrink-0 text-accent" />
                <span>
                    Growth trial ends in {shop.trialDays} days ({shop.trialEnds}).
                </span>
            </p>
            <div className="flex shrink-0 gap-2">
                <button
                    type="button"
                    onClick={() => openModal('checklist')}
                    className="h-7 rounded-lg border border-line bg-white px-3 text-[12px] font-semibold text-ink"
                >
                    Onboarding
                </button>
                <button
                    type="button"
                    onClick={() => openModal('upgrade')}
                    className="h-7 rounded-lg bg-coral px-3 text-[12px] font-semibold text-white"
                >
                    Upgrade
                </button>
            </div>
        </div>
    );
}
