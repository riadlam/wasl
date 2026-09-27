import { useEffect, useState } from 'react';
import { motion, useReducedMotion } from 'framer-motion';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api, apiErrorMessage } from '../api';
import { useSpace } from '../context';
import { queryKeys } from '../query';
import PageFrame from './PageFrame';
import FinishConnectModal from './channels/FinishConnectModal';
import DashboardConnectModal from './channels/DashboardConnectModal';
import { SOCIALAPI_PLATFORMS, platformMeta } from './channels/platforms';

const statusTone = {
    connected: 'bg-teal/10 text-teal-dark',
    reconnect_required: 'bg-info text-ink',
    disconnected: 'bg-bubble text-muted',
};

const TRAINING_STATUSES = new Set([
    'onboarding_waiting_ai_training',
    'onboarding_phaseone',
    'onboarding_ai_profile',
]);

function normalizePending(payload) {
    const root = payload?.pending ?? payload?.data ?? payload ?? {};
    const pages = payload?.pages ?? root.pages ?? root.data?.pages ?? [];
    const profiles = payload?.profiles ?? root.profiles ?? root.data?.profiles ?? [];
    return {
        ...root,
        pages: Array.isArray(pages) ? pages : [],
        profiles: Array.isArray(profiles) ? profiles : [],
    };
}

function PlatformMark({ platform, size = 18 }) {
    const meta = platformMeta(platform);
    const Icon = meta.Icon;
    return (
        <div
            className="flex shrink-0 items-center justify-center rounded-xl"
            style={{ width: size + 18, height: size + 18, backgroundColor: `${meta.color}14`, color: meta.color }}
        >
            <Icon size={size} />
        </div>
    );
}

export default function ChannelsView() {
    const { can, setError, refreshMe, setView } = useSpace();
    const queryClient = useQueryClient();
    const reduceMotion = useReducedMotion();
    const [busy, setBusy] = useState('');
    const [banner, setBanner] = useState('');
    const [bannerKind, setBannerKind] = useState('ok');
    const [pending, setPending] = useState(null);
    const [selectedPages, setSelectedPages] = useState([]);
    const [selectOpen, setSelectOpen] = useState(false);
    const [pendingConnectionId, setPendingConnectionId] = useState('');
    const [dashHelpOpen, setDashHelpOpen] = useState(false);
    const [dashPlatform, setDashPlatform] = useState('whatsapp');
    const [showMore, setShowMore] = useState(false);

    const params = new URLSearchParams(window.location.search);
    const socialStatus = params.get('socialapi');
    const connectionId = params.get('connection_id') || '';
    const pendingPlatform = params.get('platform') || '';

    const accountsQuery = useQuery({
        queryKey: queryKeys.socialAccounts,
        queryFn: async () => {
            const { data } = await api.get('/social-accounts');
            return data;
        },
    });

    const accounts = accountsQuery.data?.accounts || [];
    const configured = accountsQuery.data?.configured !== false;

    useEffect(() => {
        if (accountsQuery.error) {
            setError(apiErrorMessage(accountsQuery.error, 'Could not load channels.'));
        }
    }, [accountsQuery.error, setError]);

    const load = async () => {
        await queryClient.invalidateQueries({ queryKey: queryKeys.socialAccounts });
    };

    /** Refresh shop status and surface training / profile gate without a full page reload. */
    const afterChannelsConnected = async (message = 'Channel connected and saved for this shop.') => {
        setBanner(message);
        setBannerKind('ok');
        await load();
        try {
            const profile = typeof refreshMe === 'function' ? await refreshMe() : null;
            const status = profile?.business?.onboarding_status;
            if (status && TRAINING_STATUSES.has(status) && typeof setView === 'function') {
                setView('inbox');
            }
        } catch {
            // accounts already reloaded; gate will catch up on next /me
        }
    };

    const loadPending = async (id) => {
        if (!id) return;
        setBusy('pending');
        setPendingConnectionId(id);
        setSelectOpen(true);
        try {
            const { data } = await api.get(`/social-accounts/pending/${encodeURIComponent(id)}`);
            const normalized = normalizePending(data);
            setPending(normalized);
            const pages = (normalized.pages || []).filter((p) => p.assignable !== false);
            const profiles = normalized.profiles || [];
            if (pages.length > 0) {
                setSelectedPages(pages.slice(0, 1).map((p) => p.platform_page_id));
            } else if (profiles.length > 0) {
                setSelectedPages([profiles[0].platform_account_id]);
            } else {
                setSelectedPages([]);
            }
        } catch (err) {
            setError(apiErrorMessage(err, 'Could not load Facebook Pages.'));
            setPending({ pages: [], profiles: [], error: true });
        } finally {
            setBusy('');
        }
    };

    useEffect(() => {
        if (socialStatus === 'connected') {
            window.history.replaceState({}, '', '/space/channels');
            afterChannelsConnected();
        } else if (socialStatus === 'error') {
            setBanner(params.get('reason') === 'fetch_failed'
                ? 'Connected, but we could not save the account. Try again.'
                : 'Connect was cancelled or failed. Try again.');
            setBannerKind('warn');
            window.history.replaceState({}, '', '/space/channels');
        } else if (socialStatus === 'select' && connectionId) {
            loadPending(connectionId);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- boot from URL params once
    }, []);

    const connect = async (platform) => {
        if (!can('settings.manage') || busy) return;
        const meta = platformMeta(platform);

        if (meta.mode === 'embedded' || meta.mode === 'dashboard') {
            setDashPlatform(platform);
            setDashHelpOpen(true);
            return;
        }

        setBusy(platform);
        setError('');
        try {
            const { data } = await api.post('/social-accounts/connect', { platform });
            if (data.auth_url) {
                window.location.assign(data.auth_url);
                return;
            }
            if (data.metadata && !data.auth_url) {
                setDashPlatform(platform);
                setDashHelpOpen(true);
                return;
            }
            setError('Could not start connect for this platform. Try again.');
        } catch (err) {
            setError(apiErrorMessage(err, 'Could not start connect.'));
        } finally {
            setBusy('');
        }
    };

    const disconnect = async (id) => {
        if (!can('settings.manage') || busy) return;
        setBusy(`off-${id}`);
        try {
            await api.delete(`/social-accounts/${id}`);
            await load();
            try {
                if (typeof refreshMe === 'function') {
                    await refreshMe();
                }
            } catch {
                // channel list already reloaded; gate catches up on next /me
            }
            setBanner('Channel disconnected.');
            setBannerKind('ok');
        } catch (err) {
            setError(apiErrorMessage(err, 'Could not disconnect.'));
        } finally {
            setBusy('');
        }
    };

    const uploadLogo = async (id, file) => {
        if (!can('settings.manage') || busy || !file) return;
        setBusy(`logo-${id}`);
        try {
            const body = new FormData();
            body.append('file', file);
            await api.post(`/social-accounts/${id}/logo`, body, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            await queryClient.invalidateQueries({ queryKey: queryKeys.socialAccounts });
            setBanner('Page logo saved — AI agents will use it on creatives when needed.');
            setBannerKind('ok');
        } catch (err) {
            setError(apiErrorMessage(err, 'Could not upload page logo.'));
        } finally {
            setBusy('');
        }
    };

    const clearLogo = async (id) => {
        if (!can('settings.manage') || busy) return;
        setBusy(`logo-clear-${id}`);
        try {
            await api.delete(`/social-accounts/${id}/logo`);
            await queryClient.invalidateQueries({ queryKey: queryKeys.socialAccounts });
            setBanner('Custom logo removed — using SocialAPI page picture when available.');
            setBannerKind('ok');
        } catch (err) {
            setError(apiErrorMessage(err, 'Could not clear logo.'));
        } finally {
            setBusy('');
        }
    };

    const refreshAvatar = async (id) => {
        if (!can('settings.manage') || busy) return;
        setBusy(`avatar-${id}`);
        try {
            const { data } = await api.post(`/social-accounts/${id}/refresh-avatar`);
            await queryClient.invalidateQueries({ queryKey: queryKeys.socialAccounts });
            setBanner(
                data?.refreshed_from_api
                    ? 'Page picture refreshed from SocialAPI.'
                    : 'SocialAPI had no picture — upload a logo instead.',
            );
            setBannerKind(data?.refreshed_from_api ? 'ok' : 'warn');
        } catch (err) {
            setError(apiErrorMessage(err, 'Could not refresh page picture.'));
        } finally {
            setBusy('');
        }
    };

    const closeSelect = () => {
        setSelectOpen(false);
        setPending(null);
        setPendingConnectionId('');
        window.history.replaceState({}, '', '/space/channels');
    };

    const finishSelect = async (e) => {
        e.preventDefault();
        const id = pendingConnectionId || connectionId;
        if (!id || busy) return;

        const profileId = pending?.profiles?.[0]?.platform_account_id;
        const payload = { connection_id: id };
        const pages = pending?.pages || [];

        if (pages.length || pendingPlatform === 'facebook' || pending?.platform === 'facebook') {
            if (selectedPages.length !== 1) {
                setError('Select exactly one Facebook Page for this channel.');
                return;
            }
            payload.page_ids = [selectedPages[0]];
            if (pending?.login_id) {
                payload.login_id = pending.login_id;
            }
        } else if (profileId || pending?.profiles?.length) {
            payload.platform_account_id = selectedPages[0] || profileId;
        }

        setBusy('select');
        try {
            await api.post('/social-accounts/pending/select', payload);
            closeSelect();
            await afterChannelsConnected('Page connected — starting AI training for this shop.');
        } catch (err) {
            setError(apiErrorMessage(err, 'Could not finish connect.'));
        } finally {
            setBusy('');
        }
    };

    const live = accounts.filter((a) => a.provider === 'socialapi' && a.status !== 'disconnected');
    const simulator = accounts.find((a) => a.platform === 'simulator');
    const pages = pending?.pages || [];
    const profiles = pending?.profiles || [];
    const inboxPlatforms = SOCIALAPI_PLATFORMS.filter((p) => p.group === 'inbox');
    const morePlatforms = SOCIALAPI_PLATFORMS.filter((p) => p.group === 'more');

    const fade = reduceMotion
        ? {}
        : { initial: { opacity: 0, y: 10 }, animate: { opacity: 1, y: 0 } };

    const renderPlatformCard = (platform, i) => {
        const Icon = platform.Icon;
        const connected = live.some((a) => a.platform === platform.id);
        // One Facebook Page per shop — no second Page / "Connect another".
        const oneSlotFilled = platform.id === 'facebook' && connected;
        return (
            <motion.article
                key={platform.id}
                {...fade}
                transition={{ duration: 0.3, delay: reduceMotion ? 0 : i * 0.04 }}
                className="group flex flex-col rounded-2xl border border-line bg-white p-4 transition hover:border-ink/20 hover:shadow-card"
            >
                <div className="flex items-start justify-between gap-2">
                    <div
                        className="flex h-11 w-11 items-center justify-center rounded-2xl"
                        style={{ backgroundColor: `${platform.color}14`, color: platform.color }}
                    >
                        <Icon size={22} />
                    </div>
                    {connected && (
                        <span className="rounded-md bg-teal/10 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-teal-dark">
                            Linked
                        </span>
                    )}
                </div>
                <h3 className="mt-3 text-sm font-bold text-ink">{platform.label}</h3>
                <p className="mt-1 flex-1 text-xs leading-relaxed text-muted">{platform.blurb}</p>
                {can('settings.manage') ? (
                    oneSlotFilled ? (
                        <p className="mt-4 text-xs font-semibold text-muted">
                            One Page linked. Disconnect it to connect a different Page.
                        </p>
                    ) : (
                        <button
                            type="button"
                            disabled={Boolean(busy) || !configured}
                            onClick={() => connect(platform.id)}
                            className="mt-4 rounded-xl bg-ink px-3 py-2 text-sm font-semibold text-white transition hover:bg-ink/90 disabled:opacity-50"
                        >
                            {busy === platform.id ? 'Opening…' : connected ? 'Connect another' : 'Connect'}
                        </button>
                    )
                ) : (
                    <p className="mt-4 text-xs font-semibold text-muted">Owner only</p>
                )}
            </motion.article>
        );
    };

    return (
        <PageFrame
            title="Channels"
            subtitle="Connect platforms for this shop only. Click a channel avatar to upload a page logo for AI posts (or use the picture from SocialAPI)."
        >
            <div className="mx-auto max-w-4xl space-y-8">
                {!configured && (
                    <p className="rounded-xl bg-bubble px-4 py-3 text-sm font-semibold text-ink">
                        Channel connect is not configured yet. Ask your Wasl admin to finish setup.
                    </p>
                )}
                {banner && (
                    <p className={`rounded-xl px-4 py-3 text-sm font-semibold ${bannerKind === 'warn' ? 'bg-bubble text-ink' : 'bg-teal/10 text-teal-dark'}`}>
                        {banner}
                    </p>
                )}

                <motion.section {...fade} transition={{ duration: 0.35 }} className="space-y-3">
                    <div className="flex items-end justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-bold tracking-tight text-ink">Connected</h2>
                            <p className="mt-0.5 text-sm text-muted">Live accounts wired to this shop’s inbox.</p>
                        </div>
                    </div>
                    {live.length === 0 ? (
                        <div className="rounded-2xl border border-dashed border-line bg-bubble/60 px-5 py-8 text-center">
                            <img
                                src="/images/onboarding/channels-empty.png"
                                alt=""
                                className="mx-auto mb-3 h-20 w-20 object-contain"
                                width={80}
                                height={80}
                                loading="lazy"
                            />
                            <p className="text-sm font-semibold text-ink">No channels yet</p>
                            <p className="mx-auto mt-1 max-w-sm text-sm text-muted">
                                Connect Instagram or Facebook below, or link WhatsApp when you are ready.
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-2">
                            {live.map((account) => {
                                const tone = statusTone[account.status] || statusTone.disconnected;
                                const logoSrc = account.logo_url || account.avatar_url;
                                return (
                                    <div key={account.id} className="flex items-center justify-between gap-3 rounded-2xl border border-line bg-white px-3 py-3">
                                        <div className="flex min-w-0 items-center gap-3">
                                            <label
                                                className={`relative flex h-10 w-10 shrink-0 cursor-pointer overflow-hidden rounded-xl ${
                                                    can('settings.manage') ? 'ring-1 ring-line hover:ring-accent' : ''
                                                }`}
                                                title={can('settings.manage') ? 'Upload page logo for AI posts' : 'Page logo'}
                                            >
                                                {logoSrc ? (
                                                    <img src={logoSrc} alt="" className="h-10 w-10 object-cover" />
                                                ) : (
                                                    <div className="flex h-10 w-10 items-center justify-center bg-bubble">
                                                        <PlatformMark platform={account.platform} size={18} />
                                                    </div>
                                                )}
                                                {can('settings.manage') && (
                                                    <input
                                                        type="file"
                                                        accept="image/jpeg,image/png,image/webp"
                                                        className="absolute inset-0 cursor-pointer opacity-0"
                                                        aria-label={`Upload logo for ${account.name || 'channel'}`}
                                                        onChange={(e) => {
                                                            const file = e.target.files?.[0];
                                                            e.target.value = '';
                                                            if (file) uploadLogo(account.id, file);
                                                        }}
                                                    />
                                                )}
                                            </label>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-semibold">
                                                    {account.name || account.username || 'Account'}
                                                </p>
                                                <p className="truncate text-xs capitalize text-muted">
                                                    {platformMeta(account.platform).label}
                                                    {account.username ? ` · @${account.username}` : ''}
                                                    {account.has_custom_logo ? ' · custom logo' : (account.avatar_url ? ' · from Facebook' : '')}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            {can('settings.manage') && (
                                                <>
                                                    {!account.has_custom_logo && (
                                                        <button
                                                            type="button"
                                                            disabled={busy === `avatar-${account.id}`}
                                                            onClick={() => refreshAvatar(account.id)}
                                                            className="text-xs font-semibold text-accent hover:underline disabled:opacity-40"
                                                            title="Re-fetch page picture from SocialAPI"
                                                        >
                                                            {busy === `avatar-${account.id}` ? '…' : 'Refresh pic'}
                                                        </button>
                                                    )}
                                                    {account.has_custom_logo && (
                                                        <button
                                                            type="button"
                                                            disabled={busy === `logo-clear-${account.id}`}
                                                            onClick={() => clearLogo(account.id)}
                                                            className="text-xs font-semibold text-muted hover:underline disabled:opacity-40"
                                                        >
                                                            Use API pic
                                                        </button>
                                                    )}
                                                </>
                                            )}
                                            <span className={`rounded-md px-2 py-1 text-[11px] font-semibold ${tone}`}>
                                                {String(account.status || '').replaceAll('_', ' ')}
                                            </span>
                                            {can('settings.manage') && (
                                                <button
                                                    type="button"
                                                    disabled={busy === `off-${account.id}`}
                                                    onClick={() => disconnect(account.id)}
                                                    className="text-sm font-semibold text-coral-dark hover:underline"
                                                >
                                                    Disconnect
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                    {simulator && (
                        <p className="text-xs text-muted">Simulator stays available for local tests without a live page.</p>
                    )}
                </motion.section>

                <section>
                    <h2 className="text-sm font-bold tracking-tight text-ink">Inbox platforms</h2>
                    <p className="mt-1 text-sm text-muted">Authorize on the platform, then return here to finish.</p>
                    <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {inboxPlatforms.map((platform, i) => renderPlatformCard(platform, i))}
                    </div>
                </section>

                <section>
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-bold tracking-tight text-ink">More platforms</h2>
                            <p className="mt-1 text-sm text-muted">Same connect flow — TikTok, YouTube, LinkedIn, X, Google, and more.</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => setShowMore((v) => !v)}
                            className="text-sm font-semibold text-accent hover:underline"
                        >
                            {showMore ? 'Hide' : 'Show'}
                        </button>
                    </div>
                    {showMore && (
                        <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {morePlatforms.map((platform, i) => renderPlatformCard(platform, i))}
                        </div>
                    )}
                </section>
            </div>

            <FinishConnectModal
                open={selectOpen && can('settings.manage')}
                platform={pendingPlatform || pending?.platform}
                pages={pages}
                profiles={profiles}
                selectedPages={selectedPages}
                setSelectedPages={setSelectedPages}
                busy={busy}
                error={Boolean(pending?.error)}
                onClose={closeSelect}
                onSubmit={finishSelect}
            />

            <DashboardConnectModal
                open={dashHelpOpen}
                platformId={dashPlatform}
                onClose={() => setDashHelpOpen(false)}
            />
        </PageFrame>
    );
}
