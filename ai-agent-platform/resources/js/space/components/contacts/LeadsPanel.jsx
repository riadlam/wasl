import { useEffect, useState } from 'react';
import { Flame } from 'lucide-react';
import { api } from '../../api';
import { useSpace } from '../../context';
import { platformMeta } from '../channels/platforms';
import { LeadAvatar } from '../ui';
import { SearchField } from '../form';
import { TableSkeleton } from '../inboxSkeletons';
import PersonDetailModal from './PersonDetailModal';

export default function LeadsPanel({ status = 'all', onStatusChange }) {
    const { setError, setView, openInboxConversation } = useSpace();
    const [leads, setLeads] = useState([]);
    const [counts, setCounts] = useState({ all: 0, new: 0, hot: 0 });
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(true);
    const [open, setOpen] = useState(null);

    useEffect(() => {
        const timer = window.setTimeout(() => setSearch(searchInput.trim()), 280);
        return () => window.clearTimeout(timer);
    }, [searchInput]);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        api.get('/leads', {
            params: {
                search: search || undefined,
                status: status === 'all' ? undefined : status,
            },
        })
            .then(({ data }) => {
                if (cancelled) return;
                setLeads(data.leads || []);
                setCounts(data.counts || { all: 0, new: 0, hot: 0 });
            })
            .catch((err) => {
                if (!cancelled) setError(err.response?.data?.message || 'Could not load leads.');
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => { cancelled = true; };
    }, [search, status, setError]);

    const openConversation = (lead) => {
        if (!lead?.conversation_id) {
            setError('This lead has no conversation yet.');
            return;
        }
        openInboxConversation(lead.conversation_id, lead.conversation_status);
    };

    const onSaved = (saved) => {
        setOpen((current) => (current ? { ...current, ...saved } : saved));
        setLeads((list) => list.map((row) => (row.id === saved.id ? { ...row, ...saved } : row)));
    };

    return (
        <>
            <header className="border-b border-line bg-white/70 px-4 py-4 sm:px-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-coral">Wasl</p>
                        <h1 className="mt-1 text-2xl font-extrabold tracking-tight text-ink">Leads</h1>
                        <p className="mt-1 text-sm font-medium text-ink/70">People the AI agent marked from inbox conversations.</p>
                    </div>
                    <div className="flex gap-2">
                        <CountChip label="New" value={counts.new} />
                        <CountChip label="Hot" value={counts.hot} accent />
                    </div>
                </div>
                <div className="mt-4 max-w-md">
                    <SearchField value={searchInput} onChange={setSearchInput} placeholder="Search name, phone, or city" />
                </div>
                <div className="mt-3 flex flex-wrap gap-1.5 lg:hidden">
                    {['all', 'new', 'hot'].map((id) => (
                        <button
                            key={id}
                            type="button"
                            onClick={() => onStatusChange?.(id)}
                            className={`h-8 rounded-lg border px-2.5 text-xs font-semibold capitalize ${
                                status === id ? 'border-ink bg-ink text-white' : 'border-line bg-white text-ink hover:bg-bubble'
                            }`}
                        >
                            {id === 'all' ? 'All' : id}
                        </button>
                    ))}
                </div>
            </header>

            <div className="px-0 py-4 sm:px-6">
                {loading && <TableSkeleton rows={7} columns={4} className="px-4 py-4" />}
                {!loading && leads.length === 0 && (
                    <div className="mx-auto max-w-sm px-4 py-16 text-center">
                        <Flame size={28} className="mx-auto text-coral" />
                        <h2 className="mt-3 text-base font-bold text-ink">No leads yet</h2>
                        <p className="mt-1 text-sm text-muted">Activate Mark Lead in Workflows. The agent will classify from the chat when a client shares their details.</p>
                        <button
                            type="button"
                            onClick={() => setView('workflows')}
                            className="mt-4 h-9 rounded-lg bg-coral px-3 text-xs font-semibold text-white"
                        >
                            Open workflows
                        </button>
                    </div>
                )}
                {!loading && leads.length > 0 && (
                    <div className="overflow-hidden border-y border-line bg-white sm:rounded-2xl sm:border">
                        {leads.map((lead) => (
                            <button
                                key={lead.id}
                                type="button"
                                onClick={() => setOpen(lead)}
                                className="flex w-full items-center gap-3 border-b border-line px-4 py-3 text-start last:border-0 hover:bg-bubble"
                            >
                                <LeadAvatar name={lead.name} src={lead.avatar_url} size={40} />
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="truncate text-[13px] font-semibold text-ink">{lead.name}</span>
                                        <LeadStatus status={lead.lead_status} />
                                    </div>
                                    <div className="mt-0.5 flex items-center gap-2 text-[12px] text-muted">
                                        <PlatformDot platform={lead.platform} />
                                        <span className="truncate">{lead.phone || 'No phone'}</span>
                                        {lead.wilaya && <span>· {lead.wilaya}</span>}
                                    </div>
                                </div>
                                <span className="shrink-0 text-[11px] text-muted">{lead.marked_at || ''}</span>
                            </button>
                        ))}
                    </div>
                )}
            </div>

            {open && (
                <PersonDetailModal
                    person={open}
                    title={open.name}
                    badges={<LeadStatus status={open.lead_status} />}
                    notes={insightLines(open.insights)}
                    preview={open.preview}
                    onClose={() => setOpen(null)}
                    onOpenChat={() => openConversation(open)}
                    onSaved={onSaved}
                />
            )}
        </>
    );
}

function CountChip({ label, value, accent }) {
    return (
        <div className={`rounded-xl border px-3 py-2 ${accent ? 'border-coral/30 bg-coral/5' : 'border-line bg-bubble'}`}>
            <div className="text-[10px] font-semibold uppercase tracking-wide text-muted">{label}</div>
            <div className="text-lg font-extrabold text-ink">{value}</div>
        </div>
    );
}

function LeadStatus({ status }) {
    const hot = status === 'hot';
    return (
        <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold ${hot ? 'bg-coral/10 text-coral-dark' : 'bg-info text-accent'}`}>
            {hot ? 'Hot' : 'New'}
        </span>
    );
}

function PlatformDot({ platform }) {
    const meta = platformMeta(platform);
    const Icon = meta.Icon;
    return <Icon size={11} className="shrink-0" style={{ color: meta.color }} />;
}

function insightLines(insights) {
    if (!insights) return [];
    if (Array.isArray(insights)) {
        return insights
            .map((item) => {
                if (typeof item === 'string') return { label: 'Note', value: item };
                if (item && typeof item === 'object') {
                    const value = item.value || item.text || '';
                    return value ? { label: item.label || item.key || 'Note', value } : null;
                }
                return null;
            })
            .filter(Boolean);
    }
    if (typeof insights === 'object') {
        return Object.entries(insights)
            .filter(([, value]) => value != null && value !== '')
            .map(([label, value]) => ({ label: label.replace(/_/g, ' '), value: String(value) }));
    }
    return [];
}
