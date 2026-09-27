import { Pencil, Plus, RefreshCw, Sparkles, Zap } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { api } from '../../api';
import { platformMeta } from '../channels/platforms';
import { useSpace } from '../../context';
import { TableSkeleton } from '../inboxSkeletons';

function sortRows(rows) {
    return [...rows].sort((a, b) => {
        if (a.status === 'active' && b.status !== 'active') return -1;
        if (b.status === 'active' && a.status !== 'active') return 1;
        return String(a.name || '').localeCompare(String(b.name || ''));
    });
}

function platformsOf(row) {
    if (Array.isArray(row.config?.platforms) && row.config.platforms.length > 0) {
        return row.config.platforms;
    }
    if (row.config?.platform) return [row.config.platform];
    return [];
}

function replyModeOf(row) {
    const reply = (row.config?.steps || []).find((s) => s?.type === 'dm_reply');
    return reply?.mode === 'agent' ? 'AI agent' : 'Fixed reply';
}

export default function DmAutomationsPanel() {
    const { setView, can, setError } = useSpace();
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [statusFilter, setStatusFilter] = useState('all');
    const canManage = can('settings.manage');

    const load = useCallback(() => {
        setLoading(true);
        return api.get('/workflows')
            .then(({ data }) => {
                const list = (data.workflows || []).filter((row) => (
                    (row.template_key === 'dm_keyword' || row.kind === 'dm')
                    && row.status !== 'draft'
                ));
                setRows(sortRows(list));
            })
            .catch((err) => {
                setRows([]);
                setError(err.response?.data?.message || 'Could not load DM automations.');
            })
            .finally(() => setLoading(false));
    }, [setError]);

    useEffect(() => {
        load();
    }, [load]);

    const filtered = useMemo(() => {
        if (statusFilter === 'live') return rows.filter((r) => r.status === 'active');
        if (statusFilter === 'paused') return rows.filter((r) => r.status !== 'active');
        return rows;
    }, [rows, statusFilter]);

    const liveCount = rows.filter((r) => r.status === 'active').length;
    const draftCount = rows.length - liveCount;

    const openEdit = (row) => setView('workflows', { wf: row.id });

    const createNew = () => {
        if (!canManage) {
            setError?.('You need permission to create workflows.');
            return;
        }
        setView('workflows', { create: 'dm_keyword' });
    };

    return (
        <div className="flex h-full min-h-0 flex-col overflow-hidden bg-white">
            <header className="shrink-0 border-b border-line px-4 py-4 sm:px-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-coral">Wasl</p>
                        <h1 className="mt-1 text-xl font-extrabold tracking-tight text-ink">DM automations</h1>
                        <p className="mt-1 text-sm text-muted">
                            Keyword replies that run before the normal AI inbox.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={load}
                            className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-line px-3 text-sm font-semibold text-ink hover:bg-bubble"
                        >
                            <RefreshCw size={14} className={loading ? 'animate-spin' : ''} />
                            Refresh
                        </button>
                        {canManage && (
                            <button
                                type="button"
                                onClick={createNew}
                                className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-coral px-3.5 text-sm font-semibold text-white"
                            >
                                <Plus size={14} />
                                New
                            </button>
                        )}
                    </div>
                </div>

                <div className="mt-4 flex flex-wrap gap-1.5">
                    {[
                        { id: 'all', label: 'All', count: rows.length },
                        { id: 'live', label: 'Live', count: liveCount },
                        { id: 'paused', label: 'Paused', count: draftCount },
                    ].map((chip) => (
                        <button
                            key={chip.id}
                            type="button"
                            onClick={() => setStatusFilter(chip.id)}
                            className={`inline-flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-[12px] font-semibold ${
                                statusFilter === chip.id
                                    ? 'bg-ink text-white'
                                    : 'border border-line bg-white text-muted hover:bg-bubble hover:text-ink'
                            }`}
                        >
                            {chip.label}
                            <span className={`rounded px-1 text-[10px] font-bold ${
                                statusFilter === chip.id ? 'bg-white/20' : 'bg-bubble text-ink'
                            }`}
                            >
                                {chip.count}
                            </span>
                        </button>
                    ))}
                </div>
            </header>

            <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-6">
                {loading && rows.length === 0 ? (
                    <TableSkeleton rows={5} columns={4} />
                ) : filtered.length === 0 ? (
                    <EmptyState
                        hasAny={rows.length > 0}
                        canManage={canManage}
                        onCreate={createNew}
                    />
                ) : (
                    <>
                        <div className="hidden overflow-hidden rounded-2xl border border-line md:block">
                            <table className="w-full text-start text-[13px]">
                                <thead className="bg-bubble/80 text-[11px] font-semibold uppercase tracking-wide text-muted">
                                    <tr>
                                        <th className="px-4 py-2.5 font-semibold">Name</th>
                                        <th className="px-4 py-2.5 font-semibold">Platforms</th>
                                        <th className="px-4 py-2.5 font-semibold">Keywords</th>
                                        <th className="px-4 py-2.5 font-semibold">Reply</th>
                                        <th className="px-4 py-2.5 font-semibold">Status</th>
                                        <th className="px-4 py-2.5 text-end font-semibold"> </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line bg-white">
                                    {filtered.map((row) => (
                                        <TableRow
                                            key={row.id}
                                            row={row}
                                            canManage={canManage}
                                            onEdit={() => openEdit(row)}
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="space-y-2 md:hidden">
                            {filtered.map((row) => (
                                <MobileCard
                                    key={row.id}
                                    row={row}
                                    canManage={canManage}
                                    onEdit={() => openEdit(row)}
                                />
                            ))}
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}

function EmptyState({ hasAny, canManage, onCreate }) {
    return (
        <div className="flex flex-col items-center justify-center px-4 py-16 text-center">
            <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-coral/10 text-coral">
                <Sparkles size={24} strokeWidth={1.75} />
            </div>
            <p className="mt-4 text-base font-bold text-ink">
                {hasAny ? 'No automations in this filter' : 'No DM automations yet'}
            </p>
            <p className="mt-1 max-w-sm text-sm text-muted">
                {hasAny
                    ? 'Try All, Live, or Paused.'
                    : 'Reply instantly when someone DMs words like “price” or “prix”.'}
            </p>
            {!hasAny && canManage && (
                <button
                    type="button"
                    onClick={onCreate}
                    className="mt-5 inline-flex h-9 items-center gap-1.5 rounded-lg bg-coral px-3.5 text-sm font-semibold text-white"
                >
                    <Plus size={14} />
                    Create automation
                </button>
            )}
        </div>
    );
}

function TableRow({ row, canManage, onEdit }) {
    const platforms = platformsOf(row);
    const keywordCount = (row.config?.keywords || []).length;
    const isActive = row.status === 'active';

    return (
        <tr
            className="cursor-pointer transition hover:bg-coral/[0.03]"
            onClick={onEdit}
        >
            <td className="px-4 py-3">
                <span className="font-semibold text-ink">{row.name || row.title || 'DM automation'}</span>
            </td>
            <td className="px-4 py-3 text-muted">
                <PlatformCell platforms={platforms} />
            </td>
            <td className="px-4 py-3 text-muted">
                {keywordCount} keyword{keywordCount === 1 ? '' : 's'}
            </td>
            <td className="px-4 py-3 text-muted">{replyModeOf(row)}</td>
            <td className="px-4 py-3">
                <StatusBadge active={isActive} />
            </td>
            <td className="px-4 py-3 text-end">
                {canManage && (
                    <button
                        type="button"
                        onClick={(e) => {
                            e.stopPropagation();
                            onEdit();
                        }}
                        className="inline-flex h-8 items-center gap-1 rounded-lg px-2.5 text-[12px] font-semibold text-coral hover:bg-coral/5"
                    >
                        <Pencil size={12} />
                        Edit
                    </button>
                )}
            </td>
        </tr>
    );
}

function MobileCard({ row, canManage, onEdit }) {
    const platforms = platformsOf(row);
    const keywordCount = (row.config?.keywords || []).length;
    const isActive = row.status === 'active';

    return (
        <button
            type="button"
            onClick={onEdit}
            className="flex w-full items-start gap-3 rounded-2xl border border-line bg-white p-3 text-start transition hover:border-coral/40"
        >
            <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-coral/10 text-coral">
                <Zap size={16} />
            </span>
            <span className="min-w-0 flex-1">
                <span className="flex items-center gap-2">
                    <span className="truncate text-[13px] font-semibold text-ink">
                        {row.name || row.title || 'DM automation'}
                    </span>
                    <StatusBadge active={isActive} />
                </span>
                <span className="mt-1 block text-[12px] text-muted">
                    <PlatformCell platforms={platforms} />
                    {' · '}
                    {keywordCount} keyword{keywordCount === 1 ? '' : 's'}
                    {' · '}
                    {replyModeOf(row)}
                </span>
            </span>
            {canManage && (
                <span className="inline-flex h-8 shrink-0 items-center gap-1 rounded-lg px-2 text-[11px] font-semibold text-coral">
                    <Pencil size={12} />
                    Edit
                </span>
            )}
        </button>
    );
}

function PlatformCell({ platforms }) {
    if (platforms.length === 0) return <span>No platform</span>;
    if (platforms.length === 1) {
        return <span>{platformMeta(platforms[0])?.label || platforms[0]}</span>;
    }
    return <span>{platforms.length} platforms</span>;
}

function StatusBadge({ active }) {
    return (
        <span
            className={`inline-flex rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ${
                active ? 'bg-teal/15 text-teal-dark' : 'bg-bubble text-muted'
            }`}
        >
            {active ? 'Live' : 'Paused'}
        </span>
    );
}
