import { useCallback, useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, PanelLeft, UserRound } from 'lucide-react';
import ConversationList from './ConversationList';
import EmptyInbox from './EmptyInbox';
import InboxSidebar from './InboxSidebar';
import DmAutomationsPanel from './inbox/DmAutomationsPanel';
import LeadPanel from './LeadPanel';
import ThreadPane from './ThreadPane';
import { ColumnResize, clamp, useInboxLayout } from './inboxLayout';
import { useSpace } from '../context';
import { IconButton } from './ui';

export default function InboxView({ initialSection = 'chats' }) {
    const { selected, filtersOpen, setFiltersOpen, leadOpen, setLeadOpen, setSidebarOpen, setView } = useSpace();
    const [section, setSection] = useState(initialSection === 'automations' ? 'automations' : 'chats');
    const [layout, setLayout] = useInboxLayout();
    const shellRef = useRef(null);

    useEffect(() => {
        setSection(initialSection === 'automations' ? 'automations' : 'chats');
    }, [initialSection]);

    const platformOpen = !layout.platformCollapsed;
    const leadDesktopOpen = section === 'chats' && Boolean(selected) && !layout.leadCollapsed;
    const showChats = section === 'chats';

    const resizePlatform = useCallback((clientX) => {
        const left = shellRef.current?.getBoundingClientRect().left ?? 0;
        setLayout({ platformWidth: clamp(Math.round(clientX - left), 132, 320) });
    }, [setLayout]);

    const resizeList = useCallback((clientX) => {
        const rect = shellRef.current?.getBoundingClientRect();
        if (!rect) return;
        const platformW = layout.platformCollapsed ? 36 : layout.platformWidth;
        const sep = layout.platformCollapsed ? 0 : 6;
        setLayout({ listWidth: clamp(Math.round(clientX - rect.left - platformW - sep), 200, 480) });
    }, [layout.platformCollapsed, layout.platformWidth, setLayout]);

    const resizeLead = useCallback((clientX) => {
        const right = shellRef.current?.getBoundingClientRect().right ?? window.innerWidth;
        setLayout({ leadWidth: clamp(Math.round(right - clientX), 180, 360) });
    }, [setLayout]);

    const collapsePlatforms = () => {
        setLayout({ platformCollapsed: true });
        setSidebarOpen(false);
    };

    const expandPlatforms = () => {
        setLayout({ platformCollapsed: false });
        setSidebarOpen(true);
    };

    const openAutomations = () => {
        setFiltersOpen(false);
        setView('dm_automations');
    };

    const openChats = () => {
        setView('inbox');
    };

    return (
        <div ref={shellRef} className="relative flex h-full min-h-0">
            {platformOpen ? (
                <div
                    className="hidden h-full min-h-0 shrink-0 overflow-hidden lg:flex"
                    style={{ width: layout.platformWidth }}
                >
                    <div className="min-h-0 min-w-0 flex-1">
                        <InboxSidebar
                            onCollapse={collapsePlatforms}
                            section={section}
                            onOpenChats={openChats}
                            onOpenAutomations={openAutomations}
                        />
                    </div>
                </div>
            ) : (
                <CollapsedRail className="hidden lg:flex" label="Inbox" onExpand={expandPlatforms} icon={PanelLeft} />
            )}
            {platformOpen && (
                <ColumnResize label="Resize inbox platforms" onResize={resizePlatform} onCollapse={collapsePlatforms} />
            )}

            {showChats ? (
                <>
                    <div
                        className={`h-full min-h-0 min-w-0 shrink-0 ${selected ? 'hidden lg:flex' : 'flex'} w-full lg:w-[var(--inbox-list-w)]`}
                        style={{ '--inbox-list-w': `${layout.listWidth}px` }}
                    >
                        <ConversationList
                            platformsCollapsed={layout.platformCollapsed}
                            onExpandPlatforms={expandPlatforms}
                        />
                    </div>
                    <ColumnResize label="Resize conversation list" onResize={resizeList} className="hidden lg:block" />

                    <div className={`min-h-0 min-w-0 flex-1 flex-col ${selected ? 'flex' : 'hidden lg:flex'}`}>
                        {selected ? (
                            <ThreadPane
                                leadCollapsed={layout.leadCollapsed}
                                onToggleLead={() => setLayout((s) => ({ leadCollapsed: !s.leadCollapsed }))}
                            />
                        ) : (
                            <EmptyInbox />
                        )}
                    </div>

                    {leadDesktopOpen && (
                        <>
                            <ColumnResize
                                label="Resize lead"
                                onResize={resizeLead}
                                onCollapse={() => setLayout({ leadCollapsed: true })}
                                className="hidden xl:block"
                            />
                            <div className="hidden h-full min-h-0 shrink-0 overflow-hidden xl:flex" style={{ width: layout.leadWidth }}>
                                <div className="min-h-0 min-w-0 flex-1">
                                    <LeadPanel
                                        conversation={selected}
                                        onCollapse={() => setLayout({ leadCollapsed: true })}
                                    />
                                </div>
                            </div>
                        </>
                    )}
                    {selected && layout.leadCollapsed && (
                        <CollapsedRail
                            className="hidden xl:flex"
                            label="Lead"
                            side="end"
                            onExpand={() => setLayout({ leadCollapsed: false })}
                            icon={UserRound}
                        />
                    )}

                    {selected && leadOpen && (
                        <div className="absolute inset-0 z-30 flex xl:hidden">
                            <button type="button" aria-label="Close lead" className="flex-1 bg-ink/30" onClick={() => setLeadOpen(false)} />
                            <div className="h-full w-[min(300px,92vw)] border-s border-line bg-white shadow-xl">
                                <LeadPanel conversation={selected} overlay onClose={() => setLeadOpen(false)} />
                            </div>
                        </div>
                    )}
                </>
            ) : (
                <div className="min-h-0 min-w-0 flex-1 overflow-hidden bg-white">
                    <DmAutomationsPanel />
                </div>
            )}

            {filtersOpen && (
                <div className="absolute inset-0 z-30 flex lg:hidden">
                    <div className="h-full w-[min(260px,88vw)] border-e border-line bg-white shadow-xl">
                        <InboxSidebar
                            onPick={() => setFiltersOpen(false)}
                            section={section}
                            onOpenChats={() => {
                                openChats();
                                setFiltersOpen(false);
                            }}
                            onOpenAutomations={openAutomations}
                        />
                    </div>
                    <button type="button" aria-label="Close filters" className="flex-1 bg-ink/30" onClick={() => setFiltersOpen(false)} />
                </div>
            )}
        </div>
    );
}

function CollapsedRail({ className = '', label, onExpand, icon: Icon, side = 'start' }) {
    return (
        <div className={`w-9 shrink-0 flex-col items-center border-line bg-white py-2 ${side === 'end' ? 'border-s' : 'border-e'} ${className}`}>
            <IconButton label={`Expand ${label}`} onClick={onExpand}>
                {side === 'end' ? <ChevronLeft size={15} /> : <ChevronRight size={15} />}
            </IconButton>
            <button
                type="button"
                onClick={onExpand}
                className="mt-3 flex flex-col items-center gap-2 text-muted hover:text-ink"
                title={`Expand ${label}`}
            >
                <Icon size={14} />
                <span
                    className="text-[10px] font-semibold uppercase tracking-wide"
                    style={{ writingMode: 'vertical-rl', transform: side === 'end' ? 'rotate(180deg)' : undefined }}
                >
                    {label}
                </span>
            </button>
        </div>
    );
}
