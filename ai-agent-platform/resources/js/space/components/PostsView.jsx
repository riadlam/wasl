import { useEffect, useState } from 'react';
import { CalendarClock, Images } from 'lucide-react';
import { useSpace } from '../context';
import PostsLibraryPanel from './posts/PostsLibraryPanel';
import SchedulePostsPanel from './posts/SchedulePostsPanel';

export default function PostsView({ initialSection = 'library' }) {
    const { setView } = useSpace();
    const [section, setSection] = useState(initialSection === 'schedule' ? 'schedule' : 'library');

    useEffect(() => {
        setSection(initialSection === 'schedule' ? 'schedule' : 'library');
    }, [initialSection]);

    const openLibrary = () => setView('posts');
    const openSchedule = () => setView('schedule');

    return (
        <div className="relative flex h-full min-h-0 overflow-hidden bg-cream">
            <aside className="hidden h-full w-[220px] shrink-0 flex-col border-e border-line bg-white/80 lg:flex">
                <div className="flex h-11 items-center px-3">
                    <span className="text-[14px] font-semibold text-ink">Posts</span>
                </div>

                <p className="px-3 pb-1 text-[10px] font-semibold uppercase tracking-wide text-muted">Library</p>
                <div className="px-2">
                    <button
                        type="button"
                        onClick={openLibrary}
                        className={`flex h-9 w-full items-center gap-2 rounded-lg px-2 text-start text-[13px] ${
                            section === 'library' ? 'bg-selected font-semibold text-ink' : 'text-muted hover:bg-bubble'
                        }`}
                    >
                        <Images size={15} strokeWidth={1.75} className="shrink-0" />
                        Published posts
                    </button>
                </div>

                <p className="mt-4 px-3 pb-1 text-[10px] font-semibold uppercase tracking-wide text-muted">Publish</p>
                <div className="px-2">
                    <button
                        type="button"
                        onClick={openSchedule}
                        className={`flex h-9 w-full items-center gap-2 rounded-lg px-2 text-start text-[13px] ${
                            section === 'schedule' ? 'bg-selected font-semibold text-ink' : 'text-muted hover:bg-bubble'
                        }`}
                    >
                        <CalendarClock size={15} strokeWidth={1.75} className="shrink-0" />
                        Schedule posts
                    </button>
                </div>
            </aside>

            <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
                <div className="flex gap-1 border-b border-line bg-white px-3 py-2 lg:hidden">
                    <button
                        type="button"
                        onClick={openLibrary}
                        className={`h-8 flex-1 rounded-lg text-[12px] font-semibold ${
                            section === 'library' ? 'bg-selected text-ink' : 'text-muted'
                        }`}
                    >
                        Library
                    </button>
                    <button
                        type="button"
                        onClick={openSchedule}
                        className={`h-8 flex-1 rounded-lg text-[12px] font-semibold ${
                            section === 'schedule' ? 'bg-selected text-ink' : 'text-muted'
                        }`}
                    >
                        Schedule
                    </button>
                </div>

                <div className="min-h-0 flex-1 overflow-hidden bg-white">
                    {section === 'schedule' ? <SchedulePostsPanel /> : <PostsLibraryPanel />}
                </div>
            </div>
        </div>
    );
}
