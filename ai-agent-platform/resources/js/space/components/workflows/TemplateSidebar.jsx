import { workflowCategories } from '../../data';
import { SearchField } from '../form';

export default function TemplateSidebar({ query, setQuery, category, setCategory }) {
    return (
        <aside className="flex h-full min-h-0 w-[230px] shrink-0 flex-col gap-4 border-e border-line px-2.5 py-3.5">
            <SearchField value={query} onChange={setQuery} placeholder="Search Templates" />
            <div className="flex flex-col gap-0.5">
                {workflowCategories.map((item) => {
                    const active = category === item.id;
                    return (
                        <button
                            key={item.id}
                            type="button"
                            onClick={() => setCategory(item.id)}
                            className={`flex h-8 w-full items-center rounded-lg px-2 text-start text-[13px] capitalize ${active ? 'bg-selected font-semibold text-ink' : 'text-muted hover:bg-bubble'}`}
                        >
                            {item.label}
                        </button>
                    );
                })}
            </div>
        </aside>
    );
}
