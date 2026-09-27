import { Flame, GitBranch, LayoutTemplate } from 'lucide-react';
import { useSpace } from '../../context';
import { SearchField } from '../form';

const TEMPLATE_FILTERS = [
    { id: 'all', label: 'All templates' },
    { id: 'dm', label: 'DM' },
    { id: 'engagement', label: 'Engagement' },
    { id: 'leads', label: 'Leads' },
];

export default function WorkflowsSidebar({
    mode = 'library',
    query = '',
    setQuery,
    category = 'all',
    setCategory,
    counts = { saved: 0, active: 0 },
    onShowLibrary,
    onShowSaved,
}) {
    const { setView } = useSpace();

    return (
        <aside className="hidden h-full min-h-0 w-[230px] shrink-0 flex-col border-e border-line bg-white lg:flex">
            <div className="flex h-11 items-center px-3">
                <span className="text-[14px] font-semibold text-ink">Workflows</span>
            </div>

            <div className="flex flex-col gap-0.5 px-2 pb-3">
                <NavRow
                    active={mode === 'saved'}
                    icon={GitBranch}
                    label="Your workflows"
                    count={counts.saved}
                    onClick={onShowSaved}
                />
                <NavRow
                    active={mode === 'library'}
                    icon={LayoutTemplate}
                    label="Templates"
                    onClick={onShowLibrary}
                />
                <NavRow
                    active={false}
                    icon={Flame}
                    label="Open Leads"
                    onClick={() => setView('leads')}
                />
            </div>

            {mode === 'library' && (
                <>
                    <div className="px-2.5 pb-2">
                        <SearchField value={query} onChange={(value) => setQuery?.(value)} placeholder="Search templates" />
                    </div>
                    <p className="px-4 pb-1 text-[11px] font-semibold uppercase tracking-wide text-muted">
                        Categories
                    </p>
                    <div className="flex flex-col gap-0.5 px-2">
                        {TEMPLATE_FILTERS.map((item) => {
                            const active = category === item.id;
                            return (
                                <button
                                    key={item.id}
                                    type="button"
                                    onClick={() => setCategory?.(item.id)}
                                    className={`flex h-8 w-full items-center rounded-lg px-2 text-start text-[13px] ${
                                        active ? 'bg-selected font-semibold text-ink' : 'text-muted hover:bg-bubble'
                                    }`}
                                >
                                    <span className="truncate">{item.label}</span>
                                </button>
                            );
                        })}
                    </div>
                    <p className="mt-auto px-3 py-3 text-[12px] leading-snug text-muted">
                        Engagement flows reply to post comments. Lead flows classify inbox chats.
                    </p>
                </>
            )}

            {mode === 'saved' && (
                <div className="mt-auto space-y-2 px-3 py-3">
                    <div className="rounded-xl border border-line bg-bubble px-3 py-2.5">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-muted">Active</p>
                        <p className="mt-0.5 text-lg font-bold text-ink">{counts.active}</p>
                    </div>
                    <p className="text-[12px] leading-snug text-muted">
                        Click a workflow to edit its signal on the canvas.
                    </p>
                </div>
            )}
        </aside>
    );
}

function NavRow({ active, icon: Icon, label, count, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`flex h-9 w-full items-center gap-2 rounded-lg px-2 text-start text-[13px] ${
                active ? 'bg-selected font-semibold text-ink' : 'text-muted hover:bg-bubble hover:text-ink'
            }`}
        >
            <Icon size={15} className="shrink-0" />
            <span className="min-w-0 flex-1 truncate">{label}</span>
            {typeof count === 'number' && count > 0 && (
                <span className="rounded-md bg-white px-1.5 text-[11px] font-semibold text-ink">{count}</span>
            )}
        </button>
    );
}
