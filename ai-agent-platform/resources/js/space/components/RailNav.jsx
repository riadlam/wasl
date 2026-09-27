import { motion, useReducedMotion } from 'framer-motion';
import { BookOpen, LogOut } from 'lucide-react';
import { useSpace } from '../context';
import { navLowerIds, navUpperIds, workspaceNav, workspaceTopTools } from '../navConfig';
import AgentMark from './AgentMark';
import ReplyLanguageSelect from './ReplyLanguageSelect';
import { LogoutForm } from './LogoutForm';
import { setupProgress } from '../setup';

export default function RailNav() {
    const { view, setView, openModal, shop, can, me, socialAccounts, conversations, shopSignals, alerts } = useSpace();
    const reduce = useReducedMotion();
    const items = workspaceNav.filter((item) => !item.permission || can(item.permission));
    const upper = items.filter((item) => navUpperIds.includes(item.id));
    const featuredItems = items.filter((item) => item.featured);
    const lower = items.filter((item) => navLowerIds.includes(item.id));
    const initials = (me?.user?.name || 'W')
        .split(' ')
        .map((p) => p[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
    const progress = setupProgress({
        me,
        socialAccounts,
        conversations,
        aiEnabled: shopSignals?.aiEnabled,
        leadCount: shopSignals?.leadCount,
        orderCount: shopSignals?.orderCount,
        canView: can,
    });
    const pct = progress.total ? Math.round((progress.done / progress.total) * 100) : 0;
    const alertCount = alerts?.length || 0;
    const hover = reduce ? undefined : { x: 3, scale: 1.015 };
    const tap = reduce ? undefined : { scale: 0.97 };

    return (
        <aside className="hidden h-full w-[220px] shrink-0 flex-col border-e border-line bg-white font-sans md:flex">
            <div className="border-b border-line px-3 py-3">
                <div className="flex items-center gap-2.5">
                    <button
                        type="button"
                        title={shop.name}
                        onClick={() => openModal('workspace')}
                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-line bg-bubble text-sm font-semibold text-ink"
                    >
                        {shop.letter}
                    </button>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-[13px] font-semibold text-ink">{shop.name}</p>
                        <p className="truncate text-[11px] font-medium text-muted">Workspace</p>
                    </div>
                </div>
                <ReplyLanguageSelect className="mt-2.5" />
                <div className="mt-2.5 flex items-center gap-1">
                    {workspaceTopTools.map((item) => {
                        const Icon = item.icon;
                        const isAlerts = item.id === 'notifications';
                        return (
                            <motion.button
                                key={item.id}
                                type="button"
                                title={item.label}
                                aria-label={item.label}
                                onClick={() => openModal(item.modal)}
                                whileHover={hover}
                                whileTap={tap}
                                className="relative flex h-8 w-8 items-center justify-center rounded-lg text-muted hover:bg-bubble hover:text-ink"
                            >
                                <Icon size={16} strokeWidth={1.75} />
                                {isAlerts && alertCount > 0 && (
                                    <span className="absolute -end-0.5 -top-0.5 min-w-4 rounded-full bg-coral px-1 text-center text-[9px] font-semibold leading-4 text-white">
                                        {alertCount > 9 ? '9+' : alertCount}
                                    </span>
                                )}
                            </motion.button>
                        );
                    })}
                    <motion.button
                        type="button"
                        onClick={() => openModal('user')}
                        whileHover={hover}
                        whileTap={tap}
                        className="ms-auto flex min-w-0 flex-1 items-center gap-1.5 rounded-lg px-1.5 py-1 hover:bg-bubble"
                    >
                        <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-line bg-bubble text-[10px] font-bold text-ink">
                            {initials}
                        </span>
                        <span className="min-w-0 flex-1 text-start">
                            <span className="block truncate text-[13px] font-medium text-ink">{me?.user?.name || 'Account'}</span>
                        </span>
                    </motion.button>
                </div>
            </div>

            <button
                type="button"
                onClick={() => openModal('checklist')}
                className="mx-2 mt-2 flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-left text-[13px] font-medium text-ink hover:bg-bubble"
            >
                <BookOpen size={18} strokeWidth={1.75} className="shrink-0 text-muted" />
                <span className="flex-1">Onboarding</span>
                <span className="h-1.5 w-8 overflow-hidden rounded-full bg-line">
                    <span className="block h-full bg-coral" style={{ width: `${pct}%` }} />
                </span>
            </button>

            <nav className="mt-1 flex flex-1 flex-col gap-0.5 overflow-y-auto px-2 pb-2">
                {upper.map((item) => (
                    <RailItem
                        key={item.id}
                        item={item}
                        active={view === item.id || (item.id === 'products' && view === 'delivery') || (item.id === 'contacts' && view === 'leads') || (item.id === 'posts' && view === 'schedule') || (item.id === 'inbox' && view === 'dm_automations')}
                        hover={hover}
                        tap={tap}
                        onClick={() => setView(item.id)}
                    />
                ))}
                {featuredItems.length > 0 && (
                    <>
                        <div className="mx-2.5 my-1 h-px bg-line" />
                        {featuredItems.map((item) => (
                            <FeaturedNavItem
                                key={item.id}
                                item={item}
                                active={view === item.id}
                                hover={hover}
                                tap={tap}
                                onClick={() => setView(item.id)}
                            />
                        ))}
                        <div className="mx-2.5 my-1 h-px bg-line" />
                    </>
                )}
                {lower.map((item) => (
                    <RailItem
                        key={item.id}
                        item={item}
                        active={view === item.id || (item.id === 'products' && view === 'delivery') || (item.id === 'contacts' && view === 'leads') || (item.id === 'posts' && view === 'schedule') || (item.id === 'inbox' && view === 'dm_automations')}
                        hover={hover}
                        tap={tap}
                        onClick={() => setView(item.id)}
                    />
                ))}
            </nav>

            <div className="border-t border-line px-2 py-2">
                <LogoutForm
                    buttonClassName="flex h-9 w-full items-center justify-center gap-2 rounded-xl text-[13px] font-semibold text-coral hover:bg-coral/10"
                    label={(
                        <>
                            <LogOut size={16} strokeWidth={1.75} />
                            Log out
                        </>
                    )}
                />
            </div>
        </aside>
    );
}

function RailItem({ item, active, hover, tap, onClick }) {
    const Icon = item.icon;
    return (
        <motion.button
            type="button"
            onClick={onClick}
            whileHover={hover}
            whileTap={tap}
            className={`relative flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-left font-sans text-[13px] font-medium transition ${
                active ? 'bg-coral/10 text-ink' : 'text-muted hover:bg-bubble hover:text-ink'
            }`}
        >
            {active && <span className="absolute start-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-full bg-coral" />}
            <Icon size={18} strokeWidth={1.75} className={`shrink-0 ${active ? 'text-coral' : 'text-muted'}`} />
            <span className="truncate">{item.label}</span>
        </motion.button>
    );
}

function FeaturedNavItem({ item, active, hover, tap, onClick }) {
    const Icon = item.icon;
    const isAgents = item.id === 'agents';

    return (
        <motion.button
            type="button"
            onClick={onClick}
            whileHover={hover}
            whileTap={tap}
            className={`relative flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-left font-sans text-[13px] font-medium transition ${
                active ? 'bg-coral/10 text-ink' : 'text-muted hover:bg-bubble hover:text-ink'
            }`}
        >
            {active && <span className="absolute start-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-full bg-coral" />}
            {isAgents ? (
                <AgentMark size={18} active={active} />
            ) : (
                <Icon size={18} strokeWidth={1.75} className={`shrink-0 ${active ? 'text-coral' : 'text-muted'}`} />
            )}
            <span className="truncate">{item.label}</span>
        </motion.button>
    );
}
