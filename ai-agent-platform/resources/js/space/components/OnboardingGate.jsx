import { useEffect, useMemo, useRef, useState } from 'react';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import {
    Brain,
    Check,
    ImageIcon,
    MessageCircle,
    MessagesSquare,
    Sparkles,
} from 'lucide-react';
import { useSpace } from '../context';
import { api } from '../api';

const GATED_VIEWS = new Set([
    'inbox',
    'dm_automations',
    'posts',
    'schedule',
    'leads',
    'dashboard',
    'contacts',
    'products',
    'delivery',
    'orders',
    'agents',
    'team',
    'broadcasts',
    'workflows',
    'reports',
]);

const OPEN_VIEWS = new Set(['channels', 'settings']);

const TRAINING_STATUSES = new Set([
    'onboarding_waiting_ai_training',
    'onboarding_phaseone',
    'onboarding_ai_profile',
]);

const GATE_STATUSES = new Set(['onboarding', ...TRAINING_STATUSES]);

export default function OnboardingGate({ children }) {
    const { view, setView, me, setMe } = useSpace();
    const status = me?.business?.onboarding_status || 'onboarding_done';
    const training = me?.business?.onboarding_training || {};
    const [whyOpen, setWhyOpen] = useState(false);
    const [showSuccess, setShowSuccess] = useState(false);
    const prevStatus = useRef(status);

    const gated = useMemo(
        () => GATED_VIEWS.has(view) && GATE_STATUSES.has(status),
        [view, status],
    );

    useEffect(() => {
        const previous = prevStatus.current;
        prevStatus.current = status;
        if (TRAINING_STATUSES.has(previous) && status === 'onboarding_done') {
            setShowSuccess(true);
        }
    }, [status]);

    useEffect(() => {
        if (!showSuccess) {
            return undefined;
        }
        const id = window.setTimeout(() => {
            setShowSuccess(false);
            if (typeof setView === 'function') {
                setView('inbox');
            }
        }, 2000);
        return () => window.clearTimeout(id);
    }, [showSuccess, setView]);

    useEffect(() => {
        if (!TRAINING_STATUSES.has(status) || typeof setMe !== 'function') {
            return undefined;
        }
        let cancelled = false;
        const tick = async () => {
            try {
                const { data } = await api.get('/me');
                if (!cancelled && data) {
                    setMe(data);
                }
            } catch {
                // keep waiting
            }
        };
        const id = window.setInterval(tick, 4000);
        tick();
        return () => {
            cancelled = true;
            window.clearInterval(id);
        };
    }, [status, setMe]);

    if (showSuccess) {
        return <TrainingSuccessPanel />;
    }

    if (!gated || OPEN_VIEWS.has(view)) {
        return children;
    }

    if (status === 'onboarding_waiting_ai_training') {
        return <TrainingPanel training={training} onOpenChannels={() => setView('channels')} />;
    }

    if (status === 'onboarding_phaseone' || status === 'onboarding_ai_profile') {
        return <ProfilePanel training={training} onOpenChannels={() => setView('channels')} />;
    }

    return (
        <ConnectChannelPanel
            whyOpen={whyOpen}
            setWhyOpen={setWhyOpen}
            onConnect={() => setView('channels')}
        />
    );
}

function TrainingSuccessPanel() {
    const reduceMotion = useReducedMotion();

    return (
        <div className="flex h-full min-h-0 flex-col items-center justify-center overflow-auto bg-[radial-gradient(ellipse_at_top,_#fff8f3_0%,_#ffffff_55%,_#f6f3ee_100%)] px-4 py-8">
            <motion.div
                initial={reduceMotion ? false : { opacity: 0, y: 16, scale: 0.96 }}
                animate={{ opacity: 1, y: 0, scale: 1 }}
                transition={{ duration: 0.45, ease: 'easeOut' }}
                className="w-full max-w-sm rounded-2xl border border-line bg-white/90 px-5 py-6 text-center shadow-sm"
            >
                <div className="relative mx-auto mb-4 w-full max-w-[220px] overflow-hidden rounded-2xl border border-line/70 bg-bubble/40">
                    <img
                        src="/images/onboarding/training-success.png"
                        alt="Training complete"
                        className="h-auto w-full object-cover"
                        width={512}
                        height={512}
                        loading="eager"
                    />
                    <motion.div
                        initial={reduceMotion ? false : { scale: 0.6, opacity: 0 }}
                        animate={{ scale: 1, opacity: 1 }}
                        transition={{ delay: 0.2, type: 'spring', stiffness: 260, damping: 18 }}
                        className="absolute bottom-3 right-3 flex h-11 w-11 items-center justify-center rounded-full bg-teal text-white shadow-md"
                    >
                        <Check size={22} strokeWidth={2.6} />
                    </motion.div>
                </div>
                <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-teal-dark">Ready</p>
                <h1 className="mt-1 text-xl font-extrabold tracking-tight text-ink">
                    Your AI is ready
                </h1>
                <p className="mt-2 text-xs leading-relaxed text-muted">
                    Channel identity is set. Opening your inbox…
                </p>
                <motion.div
                    className="mx-auto mt-4 h-1 w-28 overflow-hidden rounded-full bg-line"
                    aria-hidden
                >
                    <motion.div
                        className="h-full rounded-full bg-coral"
                        initial={{ width: '0%' }}
                        animate={{ width: '100%' }}
                        transition={{ duration: 2, ease: 'linear' }}
                    />
                </motion.div>
            </motion.div>
        </div>
    );
}

function ConnectChannelPanel({ whyOpen, setWhyOpen, onConnect }) {
    return (
        <div className="flex h-full min-h-0 flex-col items-center justify-center overflow-auto bg-[radial-gradient(ellipse_at_top,_#fff8f3_0%,_#ffffff_55%,_#f6f3ee_100%)] px-6 py-12">
            <div className="w-full max-w-xl text-center">
                <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.45 }}
                    className="mx-auto mb-7 w-full max-w-md overflow-hidden rounded-3xl border border-line/70 bg-white/60 shadow-[0_24px_60px_-28px_rgba(18,24,31,0.28)]"
                >
                    <img
                        src="/images/onboarding/connect-channel-unlock.png"
                        alt="Connect Facebook or Instagram to unlock your Wasl workspace"
                        className="h-auto w-full object-cover"
                        width={1280}
                        height={720}
                        loading="eager"
                    />
                </motion.div>
                <p className="text-[11px] font-semibold uppercase tracking-[0.2em] text-coral">Wasl setup</p>
                <h1 className="mt-2 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">
                    Connect a channel to unlock your workspace
                </h1>
                <p className="mx-auto mt-3 max-w-md text-sm leading-relaxed text-muted">
                    Inbox, posts, contacts, and AI need a real Facebook or Instagram page first.
                    Link one channel and we&apos;ll train your assistant on your page.
                </p>
                <div className="mt-8 flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
                    <button
                        type="button"
                        onClick={onConnect}
                        className="inline-flex min-w-[200px] items-center justify-center rounded-xl bg-ink px-5 py-3 text-sm font-semibold text-white transition hover:bg-ink/90"
                    >
                        Connect a channel
                    </button>
                    <button
                        type="button"
                        onClick={() => setWhyOpen((v) => !v)}
                        className="text-sm font-medium text-muted underline-offset-2 hover:text-ink hover:underline"
                    >
                        Why do I need this?
                    </button>
                </div>
                {whyOpen && (
                    <div className="mt-6 rounded-2xl border border-line bg-white/80 px-4 py-3 text-left text-sm text-muted shadow-sm">
                        Wasl replies and automations run on the pages you connect. Without a channel there is nothing
                        to listen to — so we keep the rest of the workspace locked until your first page is linked.
                    </div>
                )}
            </div>
        </div>
    );
}

function TrainingPanel({ training, onOpenChannels }) {
    const posts = training?.posts ?? 0;
    const comments = training?.comments ?? 0;
    const chats = training?.chats ?? 0;
    const activeStep = trainingActiveStep(posts, comments, chats);

    return (
        <div className="flex h-full min-h-0 flex-col items-center justify-center overflow-auto bg-[radial-gradient(ellipse_at_top,_#fff8f3_0%,_#ffffff_55%,_#f6f3ee_100%)] px-4 py-8">
            <div className="w-full max-w-sm rounded-2xl border border-line bg-white/90 px-5 py-6 shadow-sm">
                <TrainingProcessVisual activeStep={activeStep} posts={posts} comments={comments} chats={chats} compact />
                <p className="mt-3 text-center text-[10px] font-semibold uppercase tracking-[0.18em] text-coral">Training</p>
                <h1 className="mt-1 text-center text-xl font-extrabold tracking-tight text-ink">
                    Training your AI on your page…
                </h1>
                <p className="mt-2 text-center text-xs leading-relaxed text-muted">
                    Reading posts, comments, and chats (with photos). Safe to refresh — this runs on the server.
                </p>

                <div className="mt-4 space-y-2">
                    <ProgressRow label="Posts" icon={ImageIcon} value={posts} max={20} active={activeStep === 'posts'} done={posts >= 20 || (posts > 0 && comments > 0)} compact />
                    <ProgressRow label="Comments" icon={MessageCircle} value={comments} max={null} active={activeStep === 'comments'} done={comments > 0 && chats > 0} compact />
                    <ProgressRow label="Chats" icon={MessagesSquare} value={chats} max={20} active={activeStep === 'chats'} done={chats >= 20} compact />
                </div>

                <ManageChannelsLink onClick={onOpenChannels} />
            </div>
        </div>
    );
}

function ProfilePanel({ training, onOpenChannels }) {
    return (
        <div className="flex h-full min-h-0 flex-col items-center justify-center overflow-auto bg-[radial-gradient(ellipse_at_top,_#fff8f3_0%,_#ffffff_55%,_#f6f3ee_100%)] px-4 py-8">
            <div className="w-full max-w-sm rounded-2xl border border-line bg-white/90 px-5 py-6 shadow-sm">
                <ProfileProcessVisual compact />
                <p className="mt-3 text-center text-[10px] font-semibold uppercase tracking-[0.18em] text-coral">Identity</p>
                <h1 className="mt-1 text-center text-xl font-extrabold tracking-tight text-ink">
                    Building your AI profile…
                </h1>
                <p className="mt-2 text-center text-xs leading-relaxed text-muted">
                    Turning your page into a channel identity. Usually finishes in one or two minutes.
                </p>

                <div className="mt-4 grid grid-cols-4 gap-1.5 text-center text-[10px] font-semibold text-ink">
                    {['Voice', 'Catalog', 'Audience', 'Visuals'].map((label, i) => (
                        <motion.div
                            key={label}
                            animate={{ opacity: [0.45, 1, 0.45] }}
                            transition={{ duration: 2.2, repeat: Infinity, delay: i * 0.3, ease: 'easeInOut' }}
                            className="rounded-lg border border-line bg-bubble/60 px-1 py-2"
                        >
                            {label}
                        </motion.div>
                    ))}
                </div>

                {training?.error && (
                    <p className="mt-4 text-center text-[11px] text-muted">
                        Still polishing the identity JSON. You can leave this open.
                    </p>
                )}

                <ManageChannelsLink onClick={onOpenChannels} />
            </div>
        </div>
    );
}

function trainingActiveStep(posts, comments, chats) {
    if (posts < 1 || (posts < 20 && comments < 1)) {
        return 'posts';
    }
    if (comments < 1 || chats < 1) {
        return 'comments';
    }
    return 'chats';
}

function TrainingProcessVisual({ activeStep, posts, comments, chats, compact = false }) {
    const reduceMotion = useReducedMotion();
    const steps = [
        { id: 'posts', Icon: ImageIcon },
        { id: 'comments', Icon: MessageCircle },
        { id: 'chats', Icon: MessagesSquare },
    ];
    const iconBox = compact ? 'h-10 w-10 rounded-xl' : 'h-14 w-14 rounded-2xl';
    const iconSize = compact ? 18 : 22;
    const brainBox = compact ? 'h-12 w-12 rounded-xl' : 'h-16 w-16 rounded-2xl';

    return (
        <div className="relative w-full">
            <div className="flex items-center justify-between gap-1.5">
                {steps.map((step, index) => {
                    const active = activeStep === step.id;
                    const done = (step.id === 'posts' && (posts >= 20 || comments > 0))
                        || (step.id === 'comments' && comments > 0 && chats > 0)
                        || (step.id === 'chats' && chats >= 20);
                    return (
                        <div key={step.id} className="flex flex-1 items-center gap-1.5">
                            <motion.div
                                animate={reduceMotion ? undefined : { scale: active ? [1, 1.05, 1] : 1 }}
                                transition={{ duration: 1.6, repeat: active ? Infinity : 0, ease: 'easeInOut' }}
                                className={`relative flex shrink-0 items-center justify-center border ${iconBox} ${
                                    active
                                        ? 'border-coral bg-coral/10 text-coral'
                                        : done
                                            ? 'border-teal/30 bg-teal/10 text-teal-dark'
                                            : 'border-line bg-white text-muted'
                                }`}
                            >
                                <step.Icon size={iconSize} strokeWidth={2.1} />
                            </motion.div>
                            {index < steps.length - 1 && (
                                <div className="relative h-0.5 flex-1 overflow-hidden rounded-full bg-line">
                                    <motion.div
                                        className="absolute inset-y-0 left-0 rounded-full bg-coral"
                                        animate={{ width: done || active ? '100%' : '18%' }}
                                        transition={{ duration: 0.5 }}
                                    />
                                </div>
                            )}
                        </div>
                    );
                })}
                <motion.div
                    animate={reduceMotion ? undefined : { scale: [1, 1.04, 1] }}
                    transition={{ duration: 2.4, repeat: Infinity, ease: 'easeInOut' }}
                    className={`ml-1 flex items-center justify-center bg-ink text-white ${brainBox}`}
                >
                    <Brain size={compact ? 20 : 28} strokeWidth={1.8} />
                </motion.div>
            </div>

            <AnimatePresence mode="wait">
                <motion.p
                    key={activeStep}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    exit={{ opacity: 0 }}
                    className="mt-3 text-center text-[11px] font-semibold text-ink"
                >
                    {activeStep === 'posts' && 'Collecting posts and photos…'}
                    {activeStep === 'comments' && 'Reading comments…'}
                    {activeStep === 'chats' && 'Importing chats…'}
                </motion.p>
            </AnimatePresence>
        </div>
    );
}

function ProfileProcessVisual({ compact = false }) {
    const reduceMotion = useReducedMotion();
    const size = compact ? 36 : 44;
    const chips = [
        { Icon: ImageIcon, x: compact ? -52 : -72, y: compact ? -18 : -28, delay: 0 },
        { Icon: MessageCircle, x: compact ? 54 : 78, y: compact ? -22 : -34, delay: 0.35 },
        { Icon: MessagesSquare, x: compact ? -48 : -64, y: compact ? 28 : 42, delay: 0.7 },
        { Icon: Sparkles, x: compact ? 50 : 70, y: compact ? 26 : 38, delay: 1.05 },
    ];

    return (
        <div className={`relative mx-auto flex items-center justify-center ${compact ? 'h-24 w-full max-w-xs' : 'h-36 w-full max-w-sm'}`}>
            {chips.map(({ Icon, x, y, delay }) => (
                <motion.div
                    key={`${x}-${y}`}
                    className="absolute flex items-center justify-center rounded-xl border border-line bg-white text-ink shadow-sm"
                    style={{ width: size, height: size }}
                    animate={reduceMotion ? { opacity: 0.85, x, y } : {
                        opacity: [0.35, 1, 0.35],
                        x: [x, 0, x],
                        y: [y, 0, y],
                    }}
                    transition={{ duration: 2.8, repeat: Infinity, delay, ease: 'easeInOut' }}
                >
                    <Icon size={compact ? 14 : 18} />
                </motion.div>
            ))}
            <motion.div
                animate={reduceMotion ? undefined : { scale: [1, 1.04, 1] }}
                transition={{ duration: 2.2, repeat: Infinity, ease: 'easeInOut' }}
                className={`relative z-10 flex items-center justify-center border border-coral/30 bg-white text-coral shadow-md ${
                    compact ? 'h-14 w-14 rounded-xl' : 'h-20 w-20 rounded-2xl'
                }`}
            >
                <Brain size={compact ? 22 : 30} strokeWidth={1.8} />
            </motion.div>
        </div>
    );
}

function ManageChannelsLink({ onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="mt-5 w-full text-center text-xs font-medium text-muted underline-offset-2 hover:text-ink hover:underline"
        >
            Manage channels
        </button>
    );
}

function ProgressRow({ label, icon: Icon, value, max, active = false, done = false, compact = false }) {
    const pct = max ? Math.min(100, Math.round((Number(value) / max) * 100)) : null;
    return (
        <div
            className={`rounded-xl border text-left transition ${
                compact ? 'px-3 py-2' : 'px-4 py-3'
            } ${
                active
                    ? 'border-coral/40 bg-coral/5'
                    : done
                        ? 'border-teal/25 bg-teal/5'
                        : 'border-line bg-cream/40'
            }`}
        >
            <div className="flex items-center justify-between text-xs font-semibold text-ink">
                <span className="inline-flex items-center gap-2">
                    <Icon size={compact ? 12 : 14} className={active ? 'text-coral' : done ? 'text-teal-dark' : 'text-muted'} />
                    {label}
                </span>
                <span className="tabular-nums text-muted">
                    {value}
                    {max != null ? ` / ${max}` : ''}
                </span>
            </div>
            {pct != null && (
                <div className={`overflow-hidden rounded-full bg-line ${compact ? 'mt-1.5 h-1' : 'mt-2 h-1.5'}`}>
                    <motion.div
                        className={`h-full rounded-full ${done ? 'bg-teal' : 'bg-coral'}`}
                        initial={false}
                        animate={{ width: `${pct}%` }}
                        transition={{ duration: 0.5 }}
                    />
                </div>
            )}
        </div>
    );
}
