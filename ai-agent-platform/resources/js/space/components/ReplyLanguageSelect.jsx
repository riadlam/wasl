import { useQuery, useQueryClient } from '@tanstack/react-query';
import { motion, useReducedMotion } from 'framer-motion';
import { api } from '../api';
import { useSpace } from '../context';
import { queryKeys } from '../query';

const OPTIONS = [
    { value: 'Darija', short: 'DZ', label: 'Darija' },
    { value: 'French', short: 'FR', label: 'Français' },
];

export default function ReplyLanguageSelect({ className = '', compact = false }) {
    const { can, setError } = useSpace();
    const queryClient = useQueryClient();
    const reduceMotion = useReducedMotion();
    const canView = can('agents.view') || can('agents.manage');
    const canManage = can('agents.manage');

    const agentQuery = useQuery({
        queryKey: queryKeys.agent,
        queryFn: async () => {
            const { data } = await api.get('/agent');
            return data;
        },
        enabled: canView,
        staleTime: 30_000,
    });

    if (!canView) {
        return null;
    }

    const current = agentQuery.data?.settings?.language || agentQuery.data?.agent?.language || 'Darija';
    const value = String(current).toLowerCase().includes('fr') ? 'French' : 'Darija';
    const busy = !canManage || agentQuery.isLoading;

    const onPick = async (language) => {
        if (busy || language === value) return;
        try {
            const { data } = await api.put('/agent', { language });
            queryClient.setQueryData(queryKeys.agent, (prev) => ({
                ...(prev || {}),
                agent: data.agent,
                settings: data.settings,
            }));
        } catch (err) {
            setError(err.response?.data?.message || 'Could not update reply language');
        }
    };

    return (
        <div className={className}>
            {!compact && (
                <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-muted/80">
                    Reply language
                </p>
            )}
            <div
                role="group"
                aria-label="Reply language"
                className={`relative grid grid-cols-2 gap-0.5 rounded-xl bg-bubble p-0.5 ring-1 ring-line/80 ${
                    busy ? 'opacity-70' : ''
                }`}
            >
                {OPTIONS.map((option) => {
                    const active = value === option.value;
                    return (
                        <button
                            key={option.value}
                            type="button"
                            disabled={busy}
                            aria-pressed={active}
                            title={option.label}
                            onClick={() => onPick(option.value)}
                            className={`relative z-0 flex h-8 items-center justify-center rounded-[0.625rem] px-2 text-[11px] font-semibold tracking-wide transition-colors disabled:cursor-not-allowed ${
                                active ? 'text-ink' : 'text-muted hover:text-ink'
                            }`}
                        >
                            {active && (
                                <motion.span
                                    layoutId={compact ? 'reply-lang-pill-mobile' : 'reply-lang-pill'}
                                    className="absolute inset-0 -z-10 rounded-[0.625rem] bg-white shadow-[0_1px_2px_rgba(18,24,31,0.08)] ring-1 ring-line/60"
                                    transition={reduceMotion
                                        ? { duration: 0 }
                                        : { type: 'spring', stiffness: 420, damping: 32 }}
                                />
                            )}
                            <span className="relative flex items-center gap-1.5">
                                <span className={`tabular-nums ${active ? 'text-coral' : 'text-muted/70'}`}>
                                    {option.short}
                                </span>
                                <span className={compact ? 'sr-only' : ''}>{option.label}</span>
                            </span>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
