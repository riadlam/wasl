import { useMemo, useRef, useState } from 'react';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import { ArrowLeft, ArrowRight, LoaderCircle, Rocket } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../../api';
import { useSpace } from '../../context';
import { queryKeys } from '../../query';
import { connectedAccounts } from '../../setup';
import CampaignProgress from './CampaignProgress';
import StepChannels from './StepChannels';
import StepSchedule from './StepSchedule';
import StepContent from './StepContent';
import StepReview from './StepReview';
import {
    CAMPAIGN_STEPS,
    campaignPayload,
    contentBasicsValid,
    initialCampaignState,
    isStepValid,
    validateStep,
    walletErrorMessage,
} from './campaignDefaults';

const stepMotion = {
    initial: { opacity: 0, y: 6 },
    animate: { opacity: 1, y: 0 },
    exit: { opacity: 0, y: -4 },
};

export default function CampaignWizard({ onBack, onLaunched }) {
    const { socialAccounts, setView, setError } = useSpace();
    const queryClient = useQueryClient();
    const reduceMotion = useReducedMotion();
    const [step, setStep] = useState(0);
    const [state, setState] = useState(initialCampaignState);
    const [touched, setTouched] = useState(false);
    const [launchNote, setLaunchNote] = useState('');
    const [launching, setLaunching] = useState(false);
    const contentRef = useRef(null);
    const pendingBriefAdvance = useRef(false);

    const accounts = useMemo(() => connectedAccounts(socialAccounts), [socialAccounts]);

    const errors = useMemo(() => validateStep(state, step), [state, step]);
    const stepValid = isStepValid(state, step);
    const allValid = [0, 1, 2].every((i) => isStepValid(state, i));

    const patchState = (patch) => setState((prev) => ({ ...prev, ...patch }));

    const canJumpTo = (index) => {
        if (index <= step) return true;
        for (let i = 0; i < index; i += 1) {
            if (!isStepValid(state, i)) return false;
        }
        return true;
    };

    const goStep = (index) => {
        if (!canJumpTo(index)) return;
        pendingBriefAdvance.current = false;
        setTouched(false);
        setStep(index);
    };

    const goNext = () => {
        setTouched(true);
        if (step === 2) {
            if (!contentBasicsValid(state)) return;
            if (!state.briefComplete) {
                pendingBriefAdvance.current = true;
                contentRef.current?.openBrief();
                return;
            }
            pendingBriefAdvance.current = false;
            setTouched(false);
            setStep(3);
            return;
        }
        if (!stepValid) return;
        pendingBriefAdvance.current = false;
        setTouched(false);
        setStep((s) => Math.min(s + 1, CAMPAIGN_STEPS.length - 1));
    };

    const onBriefComplete = () => {
        if (!pendingBriefAdvance.current) return;
        pendingBriefAdvance.current = false;
        setTouched(false);
        setStep(3);
    };

    const goBack = () => {
        pendingBriefAdvance.current = false;
        setTouched(false);
        setStep((s) => Math.max(s - 1, 0));
    };

    const onLaunch = async () => {
        if (!allValid || launching) {
            setTouched(true);
            if (!allValid) setError('Complete all steps before launching.');
            return;
        }
        setLaunching(true);
        setError('');
        setLaunchNote('');
        try {
            let images = state.images || [];
            let assetIds = images.map((row) => row.assetId).filter(Boolean);
            if (state.contentMode === 'product_images') {
                const next = [];
                for (const row of images) {
                    if (row.assetId) {
                        next.push(row);
                        continue;
                    }
                    const body = new FormData();
                    body.append('file', row.file);
                    const { data } = await api.post('/agent/assets', body, {
                        headers: { 'Content-Type': 'multipart/form-data' },
                    });
                    if (!data?.asset?.id) throw new Error('Upload failed');
                    if (row.url?.startsWith('blob:')) URL.revokeObjectURL(row.url);
                    next.push({
                        ...row,
                        id: data.asset.id,
                        assetId: data.asset.id,
                        url: data.asset.url || row.url,
                        file: null,
                    });
                }
                images = next;
                assetIds = next.map((row) => row.assetId);
                patchState({ images: next });
            }
            const { data } = await api.post('/agent/campaigns', campaignPayload({ ...state, images }, assetIds));
            const label = data?.campaign?.name || 'Campaign';
            setLaunchNote(
                `${label} is live. Knowledge is in memory — posts draft one-by-one right away; schedule times only control SocialAPI publish. Each draft waits for Telegram Accept / Cancel / Regen before the next.`,
            );
            queryClient.invalidateQueries({ queryKey: queryKeys.campaigns });
            if (data?.campaign?.id) onLaunched?.(data.campaign);
        } catch (e) {
            setError(walletErrorMessage(e, 'Could not launch the campaign.'));
        } finally {
            setLaunching(false);
        }
    };

    const showErrors = touched && !(step === 2 ? contentBasicsValid(state) : stepValid);
    const contentErrors = step === 2 && touched ? errors : (showErrors ? errors : {});

    return (
        <div className="campaign-wizard h-full overflow-y-auto bg-white">
            <div className="mx-auto w-full max-w-3xl px-4 py-5 sm:px-6 sm:py-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        {onBack ? (
                            <button
                                type="button"
                                onClick={onBack}
                                className="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-coral hover:underline"
                            >
                                <ArrowLeft size={12} />
                                Campaigns
                            </button>
                        ) : (
                            <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-coral">Wasl</p>
                        )}
                        <h1 className="cw-display mt-0.5 text-[1.35rem] font-semibold tracking-tight text-ink">
                            New campaign
                        </h1>
                    </div>
                    <div className="w-full sm:max-w-lg">
                        <CampaignProgress
                            step={step}
                            onGoStep={goStep}
                            canJumpTo={canJumpTo}
                        />
                    </div>
                </div>

                <div className="mt-5">
                    <AnimatePresence mode="wait">
                        <motion.div
                            key={step}
                            initial={reduceMotion ? false : stepMotion.initial}
                            animate={stepMotion.animate}
                            exit={reduceMotion ? {} : stepMotion.exit}
                            transition={reduceMotion ? { duration: 0 } : { duration: 0.15, ease: 'easeOut' }}
                        >
                            {step === 0 && (
                                <StepChannels
                                    accounts={accounts}
                                    selectedIds={state.channelIds}
                                    campaignName={state.name || ''}
                                    onNameChange={(name) => patchState({ name })}
                                    onToggle={(id) => {
                                        setState((prev) => {
                                            const ids = prev.channelIds.includes(id)
                                                ? prev.channelIds.filter((x) => x !== id)
                                                : [...prev.channelIds, id];
                                            return { ...prev, channelIds: ids };
                                        });
                                    }}
                                    error={showErrors ? errors.channels : ''}
                                    onGoChannels={() => setView('channels')}
                                />
                            )}
                            {step === 1 && (
                                <StepSchedule
                                    state={state}
                                    onChange={patchState}
                                    errors={showErrors ? errors : {}}
                                />
                            )}
                            {step === 2 && (
                                <StepContent
                                    ref={contentRef}
                                    state={state}
                                    onChange={patchState}
                                    errors={contentErrors}
                                    onBriefComplete={onBriefComplete}
                                />
                            )}
                            {step === 3 && (
                                <StepReview
                                    state={state}
                                    accounts={accounts}
                                    launchNote={launchNote}
                                />
                            )}
                        </motion.div>
                    </AnimatePresence>
                </div>

                <div className="mt-8 flex items-center justify-between gap-3 border-t border-ink/8 pt-4 pb-6">
                    <button
                        type="button"
                        onClick={goBack}
                        disabled={step === 0}
                        className="inline-flex h-9 items-center gap-1.5 rounded-lg px-3 text-[13px] font-medium text-ink/55 transition hover:bg-ink/5 disabled:opacity-25"
                    >
                        <ArrowLeft size={15} />
                        Back
                    </button>
                    {step < CAMPAIGN_STEPS.length - 1 ? (
                        <button
                            type="button"
                            onClick={goNext}
                            className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-ink px-4 text-[13px] font-semibold text-white"
                        >
                            Continue
                            <ArrowRight size={15} />
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={onLaunch}
                            disabled={!allValid || launching}
                            className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-coral px-4 text-[13px] font-semibold text-white disabled:opacity-45"
                        >
                            {launching ? <LoaderCircle size={15} className="animate-spin" /> : <Rocket size={15} />}
                            {launching ? 'Launching…' : 'Launch'}
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}
