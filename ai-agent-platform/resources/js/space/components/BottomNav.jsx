import { MoreHorizontal } from 'lucide-react';
import { useSpace } from '../context';
import { mobilePrimaryNav, workspaceNav } from '../navConfig';
import AgentMark from './AgentMark';

export default function BottomNav() {
    const { view, setView, moreOpen, setMoreOpen, can } = useSpace();
    const primary = workspaceNav.filter(
        (item) => mobilePrimaryNav.includes(item.id) && (!item.permission || can(item.permission)),
    );
    const moreIds = workspaceNav
        .filter((item) => !mobilePrimaryNav.includes(item.id) && (!item.permission || can(item.permission)))
        .map((item) => item.id);
    const moreActive = moreOpen || moreIds.includes(view);

    return (
        <nav className="fixed inset-x-0 bottom-0 z-40 flex border-t border-line bg-white px-1 pb-[env(safe-area-inset-bottom)] font-sans md:hidden">
            {primary.map((item) => {
                const Icon = item.icon;
                const active = (view === item.id || (item.id === 'contacts' && view === 'leads') || (item.id === 'inbox' && view === 'dm_automations') || (item.id === 'posts' && view === 'schedule')) && !moreOpen;
                const isAgents = item.id === 'agents';
                return (
                    <button
                        key={item.id}
                        type="button"
                        onClick={() => setView(item.id)}
                        className={`relative flex flex-1 flex-col items-center gap-0.5 py-2 text-[11px] font-medium ${
                            active ? (isAgents ? 'text-coral' : 'text-ink') : 'text-muted'
                        }`}
                    >
                        {active && <span className={`absolute inset-x-4 top-0 h-0.5 rounded-full ${isAgents ? 'bg-coral' : 'bg-accent'}`} />}
                        {isAgents ? <AgentMark size={18} active={active} /> : <Icon size={18} strokeWidth={1.75} />}
                        {item.label}
                    </button>
                );
            })}
            <button
                type="button"
                onClick={() => setMoreOpen(!moreOpen)}
                className={`relative flex flex-1 flex-col items-center gap-0.5 py-2 text-[11px] font-medium ${moreActive ? 'text-ink' : 'text-muted'}`}
            >
                {moreActive && <span className="absolute inset-x-4 top-0 h-0.5 rounded-full bg-accent" />}
                <MoreHorizontal size={18} strokeWidth={1.75} />
                More
            </button>
        </nav>
    );
}
