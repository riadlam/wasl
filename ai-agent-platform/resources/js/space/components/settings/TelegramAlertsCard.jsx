import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../api';
import { queryKeys } from '../../query';
import { FormCard } from '../form';
import { createEcho } from '../../../echo';
import { useSpace } from '../../context';

const TOGGLES = [
    ['enabled', 'Telegram alerts on', 'Master switch for all Telegram merchant alerts.'],
    ['notify_new_orders', 'New orders', 'When the AI captures a confirmed order.'],
    ['notify_ai_needs_human', 'AI needs you', 'When a conversation is handed to a human.'],
    ['notify_post_events', 'Posts & campaigns', 'Pending confirm cards (Approve/Deny) and when a post is scheduled.'],
];

export default function TelegramAlertsCard({ setError }) {
    const { me } = useSpace();
    const queryClient = useQueryClient();
    const [busy, setBusy] = useState(false);
    const [linkInfo, setLinkInfo] = useState(null);
    const [awaitingLink, setAwaitingLink] = useState(false);
    const [savingKey, setSavingKey] = useState(null);
    const businessId = me?.business?.id;

    const query = useQuery({
        queryKey: queryKeys.telegram,
        queryFn: async () => (await api.get('/telegram')).data,
        refetchInterval: awaitingLink ? 2000 : false,
    });

    const data = query.data || {};

    useEffect(() => {
        if (data.linked && awaitingLink) {
            setAwaitingLink(false);
            setLinkInfo(null);
        }
    }, [data.linked, awaitingLink]);

    useEffect(() => {
        if (!businessId || !awaitingLink) return undefined;
        const echo = createEcho();
        if (!echo) return undefined;
        const channel = echo.private(`business.${businessId}`);
        channel.listen('.telegram.linked', (payload) => {
            queryClient.setQueryData(queryKeys.telegram, (old) => ({
                ...(old || {}),
                ...payload,
                linked: true,
                configured: true,
                bot_username: old?.bot_username,
            }));
            setAwaitingLink(false);
            setLinkInfo(null);
        });
        return () => {
            try {
                echo.leave(`business.${businessId}`);
            } catch {
                /* ignore */
            }
        };
    }, [businessId, awaitingLink, queryClient]);

    const refresh = (next) => {
        queryClient.setQueryData(queryKeys.telegram, (old) => ({ ...(old || {}), ...next }));
    };

    const connect = async () => {
        setBusy(true);
        setLinkInfo(null);
        try {
            const { data: link } = await api.post('/telegram/link');
            setLinkInfo(link);
            setAwaitingLink(true);
            if (link.deep_link) {
                window.open(link.deep_link, '_blank', 'noopener,noreferrer');
            }
        } catch (err) {
            setError(err.response?.data?.message || 'Could not create Telegram link.');
        } finally {
            setBusy(false);
        }
    };

    const unlink = async () => {
        if (!window.confirm('Disconnect Telegram from this shop?')) return;
        setBusy(true);
        try {
            const { data: next } = await api.delete('/telegram');
            refresh(next);
            setLinkInfo(null);
            setAwaitingLink(false);
        } catch (err) {
            setError(err.response?.data?.message || 'Could not unlink Telegram.');
        } finally {
            setBusy(false);
        }
    };

    const toggle = async (key, checked) => {
        setSavingKey(key);
        try {
            const { data: next } = await api.put('/telegram', { [key]: checked });
            refresh(next);
        } catch (err) {
            setError(err.response?.data?.message || 'Could not update Telegram settings.');
        } finally {
            setSavingKey(null);
        }
    };

    return (
        <FormCard
            title="Telegram alerts"
            hint="Link your phone to the Wasl bot. One message per pending post updates when you approve or deny — from Telegram or the dashboard."
        >
            {!data.configured && (
                <p className="rounded-lg border border-line bg-bubble px-3 py-2 text-[12px] text-muted">
                    Telegram bot is not configured on the server yet (TELEGRAM_BOT_TOKEN / TELEGRAM_BOT_USERNAME).
                </p>
            )}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-[13px] font-medium text-ink">
                        {data.linked
                            ? `Linked${data.telegram_username ? ` @${data.telegram_username}` : ''}`
                            : awaitingLink
                              ? 'Waiting for Telegram Start…'
                              : 'Not linked'}
                    </p>
                    <p className="text-[12px] text-muted">
                        {data.bot_username ? `Bot ${data.bot_username}` : 'Set TELEGRAM_BOT_USERNAME in .env'}
                        {data.linked_at ? ` · since ${new Date(data.linked_at).toLocaleString()}` : ''}
                    </p>
                </div>
                <div className="flex gap-2">
                    {data.linked ? (
                        <button
                            type="button"
                            onClick={unlink}
                            disabled={busy}
                            className="h-9 rounded-lg border border-line px-3 text-[12px] font-semibold text-ink hover:bg-bubble disabled:opacity-50"
                        >
                            Unlink
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={connect}
                            disabled={busy || !data.configured}
                            className="h-9 rounded-lg bg-ink px-3 text-[12px] font-semibold text-white disabled:opacity-50"
                        >
                            {awaitingLink ? 'Open link again' : 'Connect Telegram'}
                        </button>
                    )}
                </div>
            </div>
            {linkInfo?.deep_link && !data.linked && (
                <div className="rounded-lg border border-teal/40 bg-teal/5 p-3">
                    <p className="text-[12px] font-medium text-ink">Open Telegram and tap Start — this page updates automatically.</p>
                    <a
                        href={linkInfo.deep_link}
                        target="_blank"
                        rel="noreferrer"
                        className="mt-1 block break-all text-[12px] font-medium text-teal-dark underline"
                    >
                        {linkInfo.deep_link}
                    </a>
                </div>
            )}
            <div className="divide-y divide-line">
                {TOGGLES.map(([key, label, hint]) => (
                    <label key={key} className="flex cursor-pointer items-start justify-between gap-3 py-2.5">
                        <div className="min-w-0">
                            <p className="text-[13px] font-medium text-ink">{label}</p>
                            <p className="text-[12px] text-muted">{hint}</p>
                        </div>
                        <input
                            type="checkbox"
                            className="mt-1 h-4 w-4 accent-ink"
                            checked={Boolean(data[key])}
                            disabled={savingKey === key || (!data.linked && key !== 'enabled')}
                            onChange={(e) => toggle(key, e.target.checked)}
                        />
                    </label>
                ))}
            </div>
        </FormCard>
    );
}
