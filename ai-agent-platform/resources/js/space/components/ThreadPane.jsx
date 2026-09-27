import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, LoaderCircle, Send, UserRound } from 'lucide-react';
import { platformMeta, conversationPlatform } from './channels/platforms';
import { InlineSpinner, MessageThreadSkeleton, TopLoadBar } from './inboxSkeletons';
import MessageAttachment from './MessageAttachment';
import { useSpace } from '../context';
import { IconButton, LeadAvatar } from './ui';
import { TextField } from './form';

export default function ThreadPane({ leadCollapsed = false, onToggleLead }) {
    const {
        selected,
        setSelectedId,
        sendMessage,
        composeMode,
        setComposeMode,
        toggleAi,
        loadHistory,
        can,
        busy,
        setLeadOpen,
        threadLoadingId,
    } = useSpace();
    const [text, setText] = useState('');
    const [closed, setClosed] = useState(false);
    const [historyBusy, setHistoryBusy] = useState(false);
    const [loadingOlder, setLoadingOlder] = useState(false);
    const [hasMore, setHasMore] = useState(false);
    const [cursor, setCursor] = useState(null);
    const [initialLoad, setInitialLoad] = useState(false);
    const syncedFor = useRef(null);
    const scrollerRef = useRef(null);
    const bottomRef = useRef(null);
    const stickBottom = useRef(true);

    useEffect(() => {
        setClosed(false);
        setText('');
        setHasMore(false);
        setCursor(null);
        setInitialLoad(false);
        syncedFor.current = null;
        stickBottom.current = true;
    }, [selected?.id]);

    useEffect(() => {
        if (!selected?.id) return undefined;
        if (selected.platform === 'simulator') {
            setInitialLoad(false);
            return undefined;
        }
        if (syncedFor.current === selected.id) return undefined;

        syncedFor.current = selected.id;
        let cancelled = false;
        (async () => {
            setHistoryBusy(true);
            setInitialLoad(true);
            try {
                const data = await loadHistory(selected.id, null);
                if (cancelled) return;
                setHasMore(Boolean(data?.has_more));
                setCursor(data?.next_cursor || null);
            } catch {
                if (!cancelled) {
                    setHasMore(false);
                    setCursor(null);
                }
            } finally {
                if (!cancelled) {
                    setHistoryBusy(false);
                    setInitialLoad(false);
                }
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [selected?.id, selected?.platform, loadHistory]);

    useEffect(() => {
        const el = scrollerRef.current;
        if (!el || !stickBottom.current || !selected) return undefined;
        const skeleton = selected.platform !== 'simulator'
            && initialLoad
            && (selected.messages?.length || 0) < 2;
        if (skeleton) return undefined;

        const jump = () => {
            if (!el) return;
            el.scrollTop = el.scrollHeight;
        };
        jump();
        const t0 = requestAnimationFrame(jump);
        const t1 = window.setTimeout(jump, 50);
        const t2 = window.setTimeout(jump, 200);
        return () => {
            cancelAnimationFrame(t0);
            window.clearTimeout(t1);
            window.clearTimeout(t2);
        };
    }, [selected?.id, selected?.messages?.length, selected?.platform, initialLoad, selected]);

    if (!selected) return null;

    const meta = platformMeta(conversationPlatform(selected));
    const Icon = meta.Icon;
    const canHistory = selected.platform !== 'simulator';
    const threadLoading = String(threadLoadingId) === String(selected.id) || historyBusy;
    const showMessageSkeleton = canHistory && initialLoad && (selected.messages?.length || 0) < 2;
    const sending = busy;

    const submit = (e) => {
        e.preventDefault();
        stickBottom.current = true;
        sendMessage(selected.id, text);
        setText('');
    };

    const openLead = () => {
        if (typeof window !== 'undefined' && window.matchMedia('(min-width: 1280px)').matches) {
            onToggleLead?.();
            return;
        }
        setLeadOpen(true);
    };

    const loadOlder = async () => {
        if (!canHistory || historyBusy || loadingOlder || !hasMore) return;
        const el = scrollerRef.current;
        const prevHeight = el?.scrollHeight || 0;
        const prevTop = el?.scrollTop || 0;
        stickBottom.current = false;
        setLoadingOlder(true);
        setHistoryBusy(true);
        try {
            const data = await loadHistory(selected.id, cursor);
            setHasMore(Boolean(data?.has_more));
            setCursor(data?.next_cursor || null);
            requestAnimationFrame(() => {
                if (!el) return;
                el.scrollTop = el.scrollHeight - prevHeight + prevTop;
            });
        } catch {
            /* keep previous cursor */
        } finally {
            setLoadingOlder(false);
            setHistoryBusy(false);
        }
    };

    const onScroll = () => {
        const el = scrollerRef.current;
        if (!el) return;
        const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
        stickBottom.current = nearBottom;
        if (el.scrollTop < 48 && hasMore && !historyBusy && !loadingOlder) {
            loadOlder();
        }
    };

    return (
        <section className="relative flex h-full min-h-0 min-w-0 flex-1 flex-col bg-white">
            <TopLoadBar active={threadLoading && !loadingOlder} />
            <header className="flex items-center gap-2 border-b border-line bg-white px-3 py-2">
                <button type="button" aria-label="Back to chats" onClick={() => setSelectedId(null)} className="rounded-lg p-1 text-ink hover:bg-bubble lg:hidden">
                    <ArrowLeft size={18} />
                </button>
                <button
                    type="button"
                    onClick={openLead}
                    className="flex min-w-0 flex-1 items-center gap-2.5 rounded-lg px-1 py-0.5 text-left hover:bg-bubble"
                >
                    <LeadAvatar name={selected.name} src={selected.avatar_url} size={34} />
                    <span className="min-w-0">
                        <span className="block truncate text-sm font-semibold text-ink">{selected.name}</span>
                        <span className="flex items-center gap-1 truncate text-xs text-muted">
                            <Icon size={11} className="shrink-0" style={{ color: meta.color }} />
                            <span className="truncate">
                                {selected.username ? `@${String(selected.username).replace(/^@/, '')}` : meta.label}
                                {selected.account_name ? ` · ${selected.account_name}` : ''}
                            </span>
                        </span>
                    </span>
                </button>
                {threadLoading && <InlineSpinner className="hidden sm:inline-flex" label="Loading" />}
                {leadCollapsed && (
                    <IconButton label="Show lead" className="hidden xl:inline-flex" onClick={() => onToggleLead?.()}>
                        <UserRound size={15} />
                    </IconButton>
                )}
                {can('inbox.handoff') && (
                    <button
                        type="button"
                        onClick={() => toggleAi(selected.id, selected.human)}
                        className="h-7 shrink-0 rounded-lg border border-line bg-white px-2.5 text-xs font-semibold text-ink hover:bg-bubble"
                    >
                        {selected.human ? 'Resume AI' : 'Pause AI'}
                    </button>
                )}
                <button
                    type="button"
                    onClick={() => setClosed((v) => !v)}
                    className="h-7 shrink-0 rounded-lg border border-line bg-white px-2.5 text-xs font-semibold text-ink hover:bg-bubble"
                >
                    {closed ? 'Reopen' : 'Close'}
                </button>
            </header>

            {closed && (
                <p className="border-b border-line bg-info px-3 py-2 text-xs font-semibold text-ink">
                    Conversation closed. Reopen it to keep writing.
                </p>
            )}
            <div
                ref={scrollerRef}
                onScroll={onScroll}
                className="inbox-scroll min-h-0 flex-1 space-y-2.5 overflow-y-auto bg-white px-3 py-4"
            >
                {canHistory && (
                    <div className="flex min-h-6 justify-center pb-1">
                        {loadingOlder ? (
                            <InlineSpinner label="Loading older messages" />
                        ) : hasMore ? (
                            <button
                                type="button"
                                onClick={loadOlder}
                                className="rounded-lg border border-line bg-white px-3 py-1 text-[11px] font-semibold text-muted transition hover:bg-bubble hover:text-ink"
                            >
                                Load older messages
                            </button>
                        ) : null}
                    </div>
                )}

                {showMessageSkeleton && <MessageThreadSkeleton />}

                {!showMessageSkeleton && selected.messages.length === 0 && !threadLoading && (
                    <p className="text-center text-sm text-muted">No messages yet. Say salam.</p>
                )}

                {!showMessageSkeleton &&
                    [...selected.messages]
                        .sort((a, b) => {
                            const ta = Date.parse(a.created_at || '') || 0;
                            const tb = Date.parse(b.created_at || '') || 0;
                            if (ta !== tb) return ta - tb;
                            return Number(a.id) - Number(b.id);
                        })
                        .map((m) => {
                        const outgoing = m.from !== 'them';
                        const hasMedia = Boolean(m.media_url);
                        const hasText = Boolean(String(m.text || '').trim());
                        const mediaOnly = hasMedia && !hasText;

                        return (
                            <div key={m.id} className={`flex ${outgoing ? 'justify-end' : 'justify-start'}`}>
                                <div
                                    className={`max-w-[min(85%,28rem)] overflow-hidden rounded-2xl text-sm ${
                                        mediaOnly ? 'p-1' : 'px-3.5 py-2'
                                    } ${
                                        outgoing
                                            ? 'rounded-tr-md bg-teal text-white'
                                            : 'rounded-tl-md bg-bubble text-ink'
                                    }`}
                                >
                                    {m.from === 'agent' && (
                                        <div
                                            className={`mb-0.5 text-[10px] font-bold uppercase tracking-wide ${
                                                outgoing ? 'text-white/80' : 'text-muted'
                                            } ${mediaOnly ? 'px-2.5 pt-1.5' : ''}`}
                                        >
                                            Agent
                                        </div>
                                    )}
                                    {hasMedia && <MessageAttachment message={m} outgoing={outgoing} />}
                                    {hasText && <p className="whitespace-pre-wrap break-words">{m.text}</p>}
                                    <p
                                        className={`mt-1 text-[10px] font-medium ${mediaOnly ? 'px-2.5 pb-1' : ''} ${
                                            outgoing ? 'text-white/80' : 'text-muted'
                                        }`}
                                    >
                                        {m.time || ''}
                                    </p>
                                </div>
                            </div>
                        );
                    })}
                <div ref={bottomRef} className="h-px w-full shrink-0" aria-hidden />
            </div>
            <form onSubmit={submit} className="border-t border-line bg-white p-3">
                <div className="mb-2 flex gap-1">
                    {can('inbox.simulate') && (
                        <button
                            type="button"
                            onClick={() => setComposeMode('customer')}
                            className={`rounded-lg px-2 py-1 text-[11px] font-semibold ${composeMode === 'customer' ? 'bg-info text-accent' : 'bg-bubble text-muted'}`}
                        >
                            Customer (test)
                        </button>
                    )}
                    {can('inbox.reply') && (
                        <button
                            type="button"
                            onClick={() => setComposeMode('you')}
                            className={`rounded-lg px-2 py-1 text-[11px] font-semibold ${composeMode === 'you' ? 'bg-info text-accent' : 'bg-bubble text-muted'}`}
                        >
                            You
                        </button>
                    )}
                </div>
                <div className="flex gap-2">
                    <div className="min-w-0 flex-1">
                        <TextField
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                            disabled={closed || sending}
                            placeholder={composeMode === 'customer' ? 'Type as the customer to test the agent…' : 'Reply in Darija, French, or Arabic…'}
                        />
                    </div>
                    <button type="submit" aria-label="Send" disabled={closed || sending} className="flex h-10 w-10 items-center justify-center rounded-xl bg-coral text-white disabled:opacity-40">
                        {sending ? <LoaderCircle size={16} className="animate-spin" /> : <Send size={16} />}
                    </button>
                </div>
            </form>
        </section>
    );
}
