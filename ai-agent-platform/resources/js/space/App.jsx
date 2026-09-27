import { useCallback, useEffect, useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import RailNav from './components/RailNav';
import BottomNav from './components/BottomNav';
import TrialBanner from './components/TrialBanner';
import InboxView from './components/InboxView';
import PostsView from './components/PostsView';
import LeadsView from './components/LeadsView';
import DashboardView from './components/DashboardView';
import ContactsView from './components/ContactsView';
import AgentsView from './components/AgentsView';
import CampaignsView from './components/CampaignsView';
import BroadcastsView from './components/BroadcastsView';
import WorkflowsView from './components/WorkflowsView';
import ReportsView from './components/ReportsView';
import SettingsView from './components/SettingsView';
import ChannelsView from './components/ChannelsView';
import ProductsView from './components/ProductsView';
import OrdersView from './components/OrdersView';
import TeamView from './components/TeamView';
import WorkspaceModals from './components/modals/WorkspaceModals';
import OnboardingGate from './components/OnboardingGate';
import ReplyLanguageSelect from './components/ReplyLanguageSelect';
import { WorkspaceShellSkeleton } from './components/inboxSkeletons';
import { SpaceContext } from './context';
import { pushWorkspaceView, replaceWorkspaceView, viewFromLocation, workflowNavFromLocation } from './nav';
import { api, bootCsrf, can } from './api';
import { createEcho } from '../echo';
import { buildAlerts } from './setup';
import { queryKeys } from './query';

const views = {
    inbox: function InboxChats() {
        return <InboxView initialSection="chats" />;
    },
    dm_automations: function InboxAutomations() {
        return <InboxView initialSection="automations" />;
    },
    posts: PostsView,
    schedule: function ScheduleInsidePosts() {
        return <PostsView initialSection="schedule" />;
    },
    leads: LeadsView,
    dashboard: DashboardView,
    contacts: ContactsView,
    products: ProductsView,
    delivery: function DeliveryInsideProducts() {
        return <ProductsView initialSection="delivery" />;
    },
    payments: function PaymentsInsideProducts() {
        return <ProductsView initialSection="payments" />;
    },
    orders: OrdersView,
    agents: AgentsView,
    campaigns: CampaignsView,
    team: TeamView,
    broadcasts: BroadcastsView,
    workflows: WorkflowsView,
    reports: ReportsView,
    channels: ChannelsView,
    settings: SettingsView,
};

export default function App({ start = 'inbox' }) {
    const queryClient = useQueryClient();
    const [view, setView] = useState(start);
    const [workflowNav, setWorkflowNav] = useState(() => workflowNavFromLocation());
    const [modal, setModal] = useState(null);
    const [me, setMe] = useState(null);
    const [conversations, setConversations] = useState([]);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [filter, setFilter] = useState('all');
    const [sort, setSort] = useState('newest');
    const [status, setStatus] = useState('open');
    const [unrepliedOnly, setUnrepliedOnly] = useState(false);
    const [query, setQuery] = useState('');
    const [selectedId, setSelectedId] = useState(null);
    const [sidebarOpen, setSidebarOpen] = useState(true);
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [leadOpen, setLeadOpen] = useState(false);
    const [moreOpen, setMoreOpen] = useState(false);
    const [composeMode, setComposeMode] = useState('you');
    const [busy, setBusy] = useState(false);
    const [inboxReady, setInboxReady] = useState(false);
    const [inboxSyncing, setInboxSyncing] = useState(false);
    const [threadLoadingId, setThreadLoadingId] = useState(null);
    const [shopSignals, setShopSignals] = useState({
        aiEnabled: false,
        leadCount: 0,
        orderCount: 0,
        pendingOrders: [],
    });

    const navigate = (id, options = {}) => {
        if (!id) {
            setMoreOpen(false);
            setFiltersOpen(false);
            return;
        }
        setMoreOpen(false);
        setFiltersOpen(false);
        setLeadOpen(false);

        if (id === 'workflows' && options && Object.keys(options).length > 0) {
            const params = {};
            if (options.wf) params.wf = options.wf;
            if (options.create) params.create = options.create;
            if (options.account_id) params.account_id = options.account_id;
            if (options.platform_post_id) params.platform_post_id = options.platform_post_id;
            setWorkflowNav({
                wf: options.wf || null,
                create: options.create || null,
                account_id: options.account_id || null,
                platform_post_id: options.platform_post_id || null,
                posts: options.posts || null,
            });
            setView('workflows');
            pushWorkspaceView('workflows', params);
            return;
        }

        if (id === view && !options?.force) {
            return;
        }
        setWorkflowNav(null);
        setView(id);
        pushWorkspaceView(id);
    };

    const clearWorkflowNav = () => {
        setWorkflowNav(null);
        replaceWorkspaceView('workflows');
    };

    const refreshMe = async () => {
        const { data } = await api.get('/me');
        setMe(data);
        return data;
    };

    const refreshInbox = async () => {
        if (!can(me, 'inbox.view') && me) return;
        try {
            const { data } = await api.get('/conversations');
            setConversations(data.conversations || []);
        } catch {
            /* no inbox permission */
        }
    };

    const syncRemoteInbox = useCallback(async ({ withMessages = false } = {}) => {
        setInboxSyncing(true);
        setError('');
        try {
            const { data } = await api.post('/conversations/sync', { with_messages: withMessages });
            setConversations(data.conversations || []);
            if (Array.isArray(data.errors) && data.errors.length > 0) {
                setError(data.errors[0]?.message || 'Some platforms failed to sync.');
            }
            return data;
        } catch (e) {
            setError(e.response?.data?.message || 'Could not sync inbox.');
            throw e;
        } finally {
            setInboxSyncing(false);
        }
    }, []);

    const { data: socialPayload } = useQuery({
        queryKey: queryKeys.socialAccounts,
        queryFn: async () => {
            const { data } = await api.get('/social-accounts');
            return data;
        },
        enabled: Boolean(me),
    });
    const socialAccounts = socialPayload?.accounts || [];

    useEffect(() => {
        (async () => {
            try {
                await bootCsrf();
                const profile = await refreshMe();
                if ((profile.permissions || []).includes('inbox.view') || profile.user?.platform_role === 'super_admin') {
                    const { data } = await api.get('/conversations');
                    setConversations(data.conversations || []);
                    setInboxReady(true);
                    setLoading(false);
                    // Manual Sync / Echo keep inbox fresh — no provider sync on every boot.
                } else {
                    setInboxReady(true);
                }
                try {
                    await queryClient.prefetchQuery({
                        queryKey: queryKeys.socialAccounts,
                        queryFn: async () => {
                            const { data } = await api.get('/social-accounts');
                            return data;
                        },
                    });
                } catch {
                    queryClient.setQueryData(queryKeys.socialAccounts, { accounts: [], configured: false });
                }
            } catch (e) {
                if (e.response?.status === 401) {
                    window.location.assign('/login');
                    return;
                }
                setError(e.response?.data?.message || 'Could not load the workspace.');
                setInboxReady(true);
            } finally {
                setLoading(false);
            }
        })();
    }, [queryClient]);

    useEffect(() => {
        if (!me) return undefined;
        let cancelled = false;

        const loadSignals = async () => {
            const next = {
                aiEnabled: false,
                leadCount: 0,
                orderCount: 0,
                pendingOrders: [],
            };
            const jobs = [];
            if (can(me, 'agents.view')) {
                jobs.push(
                    api.get('/agent').then(({ data }) => {
                        next.aiEnabled = Boolean(data.agent?.ai_enabled);
                    }).catch(() => {}),
                );
            }
            if (can(me, 'contacts.view')) {
                jobs.push(
                    api.get('/leads').then(({ data }) => {
                        next.leadCount = data.counts?.all || 0;
                    }).catch(() => {}),
                );
            }
            if (can(me, 'orders.view')) {
                jobs.push(
                    api.get('/orders', { params: { status: 'pending' } }).then(({ data }) => {
                        next.pendingOrders = data.orders || [];
                        next.orderCount = data.counts?.all || 0;
                    }).catch(() => {}),
                );
            }
            await Promise.all(jobs);
            if (!cancelled) setShopSignals({ ...next });
        };

        loadSignals();
        return () => { cancelled = true; };
    }, [me]);

    useEffect(() => {
        const businessId = me?.business?.id;
        if (!businessId) {
            return undefined;
        }

        const applyConversation = (conversation) => {
            if (!conversation?.id) return;
            setConversations((list) => {
                const existing = list.find((c) => String(c.id) === String(conversation.id));
                const merged = {
                    ...(existing || {}),
                    ...conversation,
                    // Websocket updates omit messages to stay under frame size limits.
                    messages: Array.isArray(conversation.messages)
                        ? conversation.messages
                        : (existing?.messages || []),
                };
                const rest = list.filter((c) => String(c.id) !== String(conversation.id));
                return [merged, ...rest];
            });
        };

        const echo = createEcho();
        let channel = null;
        if (echo) {
            channel = echo.private(`business.${businessId}`);
            channel.listen('.conversation.updated', (payload) => applyConversation(payload?.conversation));
            channel.listen('.inbox.message.created', (payload) => {
                const message = payload?.message;
                if (!message?.conversation_id) return;
                setConversations((list) => list.map((c) => {
                    if (String(c.id) !== String(message.conversation_id)) return c;
                    if ((c.messages || []).some((m) => String(m.id) === String(message.id))) return c;
                    return {
                        ...c,
                        unreplied: message.from === 'them',
                        preview: previewFromMessage(message) || c.preview,
                        time: 'now',
                        sortAt: Date.now(),
                        messages: [...(c.messages || []), message],
                    };
                }));
            });
        }

        // Polling fallback — keeps inbox live when WebSockets cannot connect (e.g. HTTPS ngrok → HTTP Reverb).
        let cancelled = false;
        const poll = async () => {
            if (cancelled) return;
            try {
                const { data } = await api.get('/conversations');
                if (!cancelled && Array.isArray(data.conversations)) {
                    setConversations(data.conversations);
                }
            } catch {
                /* ignore transient poll errors */
            }
        };
        const pollMs = echo ? 12000 : 4000;
        const timer = window.setInterval(poll, pollMs);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
            if (echo && channel) {
                echo.leave(`business.${businessId}`);
                echo.disconnect();
            }
        };
    }, [me?.business?.id]);

    // Browser back / forward — stay in the SPA, swap the React view only.
    useEffect(() => {
        const onPopState = () => {
            setView(viewFromLocation());
            setWorkflowNav(workflowNavFromLocation());
            setMoreOpen(false);
            setFiltersOpen(false);
            setLeadOpen(false);
        };
        window.addEventListener('popstate', onPopState);
        return () => window.removeEventListener('popstate', onPopState);
    }, []);

    const visible = useMemo(() => {
        const q = query.trim().toLowerCase();
        const platform = filter.startsWith('platform:') ? filter.slice('platform:'.length) : null;
        return conversations
            .filter((c) => !c.blocked)
            .filter((c) => (status === 'closed' ? c.status === 'closed' : c.status !== 'closed'))
            .filter((c) => !platform || String(c.platform || c.channel || '').toLowerCase() === platform)
            .filter((c) => (unrepliedOnly ? c.unreplied : true))
            .filter((c) => !q || `${c.name} ${c.username || ''} ${c.preview} ${c.city}`.toLowerCase().includes(q))
            .sort((a, b) => (sort === 'oldest' ? a.sortAt - b.sortAt : b.sortAt - a.sortAt));
    }, [conversations, filter, unrepliedOnly, query, sort, status]);

    const selected = conversations.find((c) => String(c.id) === String(selectedId)) || null;

    useEffect(() => {
        if (selectedId && !visible.some((c) => String(c.id) === String(selectedId))) {
            setSelectedId(null);
            setLeadOpen(false);
        }
    }, [visible, selectedId]);

    const upsertConversation = (conversation) => {
        setConversations((list) => {
            const rest = list.filter((c) => String(c.id) !== String(conversation.id));
            return [conversation, ...rest];
        });
        setSelectedId(conversation.id);
    };

    const chooseFilter = (id) => {
        setFilter(id);
        setFiltersOpen(false);
    };

    const sendMessage = async (id, text) => {
        const trimmed = text.trim();
        if (!trimmed || busy) return;
        setBusy(true);
        setError('');
        try {
            if (composeMode === 'customer' || !id) {
                const { data } = await api.post('/conversations/simulate', {
                    name: selected?.name || 'Test customer',
                    phone: selected?.phone || '',
                    wilaya: selected?.city || 'Alger',
                    text: trimmed,
                });
                upsertConversation(data.conversation);
            } else {
                const { data } = await api.post(`/conversations/${id}/messages`, { text: trimmed });
                upsertConversation(data.conversation);
            }
        } catch (e) {
            setError(e.response?.data?.message || 'Could not send.');
        } finally {
            setBusy(false);
        }
    };

    const simulateNew = async (payload) => {
        setBusy(true);
        setError('');
        try {
            const { data } = await api.post('/conversations/simulate', payload);
            upsertConversation(data.conversation);
            navigate('inbox');
        } catch (e) {
            setError(e.response?.data?.message || 'Could not simulate.');
        } finally {
            setBusy(false);
        }
    };

    const toggleAi = async (id, enable) => {
        try {
            const path = enable ? 'resume-ai' : 'handoff';
            const { data } = await api.post(`/conversations/${id}/${path}`);
            upsertConversation(data.conversation);
        } catch (e) {
            setError(e.response?.data?.message || 'Could not update AI.');
        }
    };

    const loadHistory = useCallback(async (id, cursor = null) => {
        setThreadLoadingId(id);
        try {
            const { data } = await api.post(`/conversations/${id}/history`, {
                cursor: cursor || undefined,
                limit: 200,
            });
            if (data.conversation) {
                setConversations((list) => {
                    const rest = list.filter((c) => String(c.id) !== String(data.conversation.id));
                    return [data.conversation, ...rest];
                });
            }
            return data;
        } finally {
            setThreadLoadingId((current) => (String(current) === String(id) ? null : current));
        }
    }, []);

    const addContact = (payload) => {
        simulateNew({
            name: payload.name,
            phone: payload.phone || '',
            wilaya: payload.city || 'Alger',
            text: 'سلام',
        });
        setModal(null);
    };

    const openInboxConversation = (conversationId, conversationStatus) => {
        if (!conversationId) {
            setError('This has no conversation yet.');
            return;
        }
        chooseFilter('all');
        setUnrepliedOnly(false);
        setQuery('');
        setStatus(conversationStatus === 'closed' ? 'closed' : 'open');
        setLeadOpen(false);
        setSelectedId(conversationId);
        navigate('inbox');
    };

    const alerts = useMemo(
        () => buildAlerts({ conversations, pendingOrders: shopSignals.pendingOrders }),
        [conversations, shopSignals.pendingOrders],
    );

    const value = {
        view,
        setView: navigate,
        workflowNav,
        clearWorkflowNav,
        me,
        setMe,
        can: (key) => can(me, key),
        refreshMe,
        modal,
        openModal: setModal,
        closeModal: () => setModal(null),
        conversations,
        visible,
        selected,
        selectedId,
        setSelectedId,
        openInboxConversation,
        filter,
        setFilter: chooseFilter,
        socialAccounts,
        leadOpen,
        setLeadOpen,
        sort,
        setSort,
        status,
        setStatus,
        unrepliedOnly,
        setUnrepliedOnly,
        query,
        setQuery,
        sidebarOpen,
        setSidebarOpen,
        filtersOpen,
        setFiltersOpen,
        moreOpen,
        setMoreOpen,
        sendMessage,
        addContact,
        simulateNew,
        toggleAi,
        loadHistory,
        syncRemoteInbox,
        inboxReady,
        inboxSyncing,
        threadLoadingId,
        composeMode,
        setComposeMode,
        busy,
        error,
        setError,
        shopSignals,
        alerts,
        shop: me?.business
            ? { name: me.business.name, owner: me.user?.name, letter: me.business.letter }
            : { name: 'Wasl', owner: '', letter: 'W' },
    };

    const View = views[view] || InboxView;

    if (loading) {
        return <WorkspaceShellSkeleton />;
    }

    return (
        <SpaceContext.Provider value={value}>
            <div className="flex h-full min-h-0 overflow-hidden bg-white">
                <RailNav />
                <div className="flex min-w-0 flex-1 flex-col pb-[68px] md:pb-0">
                    {me?.impersonating && (
                        <div className="flex items-center justify-between bg-ink px-4 py-2 text-xs font-semibold text-white">
                            Viewing shop as Super Admin
                            <button
                                type="button"
                                className="rounded bg-white/15 px-2 py-1"
                                onClick={async () => {
                                    await api.post('/admin/stop-impersonation');
                                    window.location.assign('/admin');
                                }}
                            >
                                Back to tenants
                            </button>
                        </div>
                    )}
                    <TrialBanner />
                    {error && <p className="border-b border-line bg-cream-deep px-4 py-2 text-sm text-coral-dark">{error}</p>}
                    <div className="flex items-center justify-end border-b border-line px-3 py-1.5 md:hidden">
                        <ReplyLanguageSelect className="w-44" compact />
                    </div>
                    <div className="relative min-h-0 flex-1 overflow-hidden">
                        <OnboardingGate>
                            <View />
                        </OnboardingGate>
                    </div>
                </div>
                <BottomNav />
                <WorkspaceModals />
            </div>
        </SpaceContext.Provider>
    );
}

function previewFromMessage(message) {
    const text = String(message?.text || '').trim();
    if (text && !/^\[(attachment|image|photo|video|audio|file)\]$/i.test(text)) {
        return text;
    }
    const kind = String(message?.media_type || message?.type || '').toLowerCase();
    if (kind === 'image') return 'Photo';
    if (kind === 'video') return 'Video';
    if (kind === 'audio') return 'Audio';
    if (kind === 'share') return 'Shared link';
    if (message?.media_url || kind === 'file') return 'Attachment';
    return text || '';
}
