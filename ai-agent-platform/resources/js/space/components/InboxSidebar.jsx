import { Inbox, PanelLeft, RefreshCw, Zap } from 'lucide-react';
import { useMemo, useState } from 'react';
import { platformMeta, SOCIALAPI_PLATFORMS } from './channels/platforms';
import { useSpace } from '../context';
import { IconButton } from './ui';

export default function InboxSidebar({
    onPick,
    onCollapse,
    section = 'chats',
    onOpenChats,
    onOpenAutomations,
}) {
    const {
        filter,
        setFilter,
        conversations,
        socialAccounts,
        setView,
        can,
        syncRemoteInbox,
        inboxSyncing,
    } = useSpace();
    const [syncing, setSyncing] = useState(false);

    const platforms = useMemo(() => linkedPlatforms(socialAccounts, conversations), [socialAccounts, conversations]);
    const openChatsList = conversations.filter((c) => !c.blocked && c.status !== 'closed');
    const automationsActive = section === 'automations';

    const pick = (id) => {
        onOpenChats?.();
        setFilter(id);
        onPick?.();
    };

    const sync = async () => {
        if (!syncRemoteInbox || syncing || inboxSyncing) return;
        setSyncing(true);
        try {
            await syncRemoteInbox({ withMessages: false });
        } catch {
            /* error surfaced in App */
        } finally {
            setSyncing(false);
        }
    };

    return (
        <div className="flex h-full min-h-0 flex-col bg-white">
            <div className="flex h-11 items-center justify-between px-2.5">
                <span className="text-[14px] font-semibold text-ink">Inbox</span>
                <div className="flex items-center">
                    <IconButton label="Sync inbox" onClick={sync}>
                        <RefreshCw size={14} className={syncing || inboxSyncing ? 'animate-spin' : ''} />
                    </IconButton>
                    {onCollapse && (
                        <IconButton label="Collapse platforms" className="hidden lg:inline-flex" onClick={onCollapse}>
                            <PanelLeft size={15} />
                        </IconButton>
                    )}
                </div>
            </div>

            <div className="inbox-scroll min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2 pb-4">
                <FilterRow
                    active={!automationsActive && filter === 'all'}
                    icon={Inbox}
                    label="All"
                    count={unrepliedCount(openChatsList)}
                    onClick={() => pick('all')}
                />

                {platforms.length > 0 && (
                    <p className="px-2 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wide text-muted">
                        Channels
                    </p>
                )}

                {platforms.map((row) => {
                    const meta = platformMeta(row.id);
                    return (
                        <FilterRow
                            key={row.id}
                            active={!automationsActive && filter === `platform:${row.id}`}
                            icon={meta.Icon}
                            iconColor={meta.color}
                            label={meta.label}
                            hint={row.pages}
                            count={unrepliedCount(openChatsList, row.id)}
                            onClick={() => pick(`platform:${row.id}`)}
                        />
                    );
                })}

                {platforms.length === 0 && (
                    <div className="px-2 pt-6 text-center">
                        <p className="text-[13px] text-muted">No platforms linked yet.</p>
                        {can('settings.view') && (
                            <button
                                type="button"
                                onClick={() => {
                                    onPick?.();
                                    setView('channels');
                                }}
                                className="mt-3 h-8 rounded-lg bg-ink px-3 text-xs font-semibold text-white"
                            >
                                Connect a platform
                            </button>
                        )}
                    </div>
                )}

                <p className="px-2 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wide text-muted">
                    Automations
                </p>
                <FilterRow
                    active={automationsActive}
                    icon={Zap}
                    iconColor="#ff5a3c"
                    label="DM automations"
                    hint="Keyword replies"
                    onClick={() => {
                        onOpenAutomations?.();
                        onPick?.();
                    }}
                />
            </div>
        </div>
    );
}

function linkedPlatforms(accounts, conversations) {
    const live = (accounts || []).filter(
        (account) => account.platform && account.platform !== 'simulator' && account.status !== 'disconnected',
    );
    const ids = new Set(live.map((account) => account.platform));
    (conversations || []).forEach((conversation) => {
        if (conversation.platform && conversation.platform !== 'simulator') {
            ids.add(conversation.platform);
        }
    });

    const catalog = SOCIALAPI_PLATFORMS.filter((platform) => ids.has(platform.id)).map((platform) => platform.id);
    const extras = [...ids].filter((id) => !catalog.includes(id));
    const rows = [...catalog, ...extras].map((id) => ({
        id,
        pages: pageLabels(live.filter((account) => account.platform === id), conversations, id),
    }));

    if ((conversations || []).some((conversation) => conversation.platform === 'simulator')) {
        rows.push({ id: 'simulator', pages: 'Test channel' });
    }

    return rows;
}

function pageLabels(accounts, conversations, platform) {
    const names = accounts
        .map((account) => account.name || (account.username ? `@${String(account.username).replace(/^@/, '')}` : null))
        .filter(Boolean);
    if (names.length) {
        return [...new Set(names)].join(' · ');
    }

    const fromChats = (conversations || [])
        .filter((conversation) => conversation.platform === platform && conversation.account_name)
        .map((conversation) => conversation.account_name);

    return [...new Set(fromChats)].join(' · ') || null;
}

function unrepliedCount(conversations, platform) {
    return conversations.filter((conversation) => conversation.unreplied && (!platform || conversation.platform === platform)).length;
}

function FilterRow({ icon: Icon, iconColor, label, hint, count, active, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`flex w-full items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-start text-[13px] text-ink ${active ? 'bg-selected font-semibold' : 'hover:bg-bubble'}`}
        >
            <span className="flex min-w-0 items-center gap-2">
                {Icon && (
                    <Icon
                        size={15}
                        className="shrink-0"
                        style={{ color: active ? (iconColor || 'var(--color-accent, #2563eb)') : iconColor || undefined }}
                    />
                )}
                <span className="min-w-0">
                    <span className="block truncate">{label}</span>
                    {hint && <span className="mt-0.5 block truncate text-[11px] font-medium text-muted">{hint}</span>}
                </span>
            </span>
            {count > 0 && (
                <span className="shrink-0 rounded-md bg-coral/10 px-1.5 py-0.5 text-[11px] font-bold text-coral-dark">{count}</span>
            )}
        </button>
    );
}
