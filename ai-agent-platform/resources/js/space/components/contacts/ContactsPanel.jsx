import { useEffect, useState } from 'react';
import { Users } from 'lucide-react';
import { api } from '../../api';
import { useSpace } from '../../context';
import { platformMeta } from '../channels/platforms';
import { LeadAvatar, SpaceHeader } from '../ui';
import { SearchField, SelectField } from '../form';
import { TableSkeleton } from '../inboxSkeletons';
import PersonDetailModal from './PersonDetailModal';

const PER_PAGE = [
    { value: '10', label: '10 / page' },
    { value: '25', label: '25 / page' },
    { value: '50', label: '50 / page' },
];

export default function ContactsPanel() {
    const { setError, openInboxConversation } = useSpace();
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [perPage, setPerPage] = useState('25');
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [open, setOpen] = useState(null);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            const next = searchInput.trim();
            setSearch((prev) => {
                if (prev !== next) setPage(1);
                return next;
            });
        }, 280);
        return () => window.clearTimeout(timer);
    }, [searchInput]);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        api.get('/customers', {
            params: {
                search: search || undefined,
                per_page: Number(perPage),
                page,
            },
        })
            .then(({ data }) => {
                if (cancelled) return;
                setRows(data.customers || []);
                setMeta(data.meta || { current_page: 1, last_page: 1, per_page: 25, total: 0 });
            })
            .catch((err) => {
                if (!cancelled) setError(err.response?.data?.message || 'Could not load contacts.');
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => { cancelled = true; };
    }, [search, perPage, page, setError]);

    const openConversation = (contact) => {
        if (!contact?.conversation_id) {
            setError('This contact has no conversation yet.');
            return;
        }
        openInboxConversation(contact.conversation_id, contact.conversation_status);
    };

    const onSaved = (saved) => {
        setOpen((current) => (current ? { ...current, ...saved } : saved));
        setRows((list) => list.map((row) => (row.id === saved.id ? { ...row, ...saved } : row)));
    };

    return (
        <>
            <SpaceHeader title="Contacts" subtitle="Everyone who wrote the shop, including simulator tests.">
                <div className="rounded-lg border border-line bg-bubble px-3 py-2">
                    <div className="text-[10px] font-semibold uppercase tracking-wide text-muted">Total</div>
                    <div className="text-lg font-semibold text-ink">{meta.total ?? 0}</div>
                </div>
            </SpaceHeader>

            <div className="px-4 py-4 sm:px-6">
                <div className="flex flex-wrap items-end gap-3">
                    <div className="min-w-[180px] flex-1">
                        <SearchField value={searchInput} onChange={setSearchInput} placeholder="Search name, phone, city, or email" />
                    </div>
                    <div className="w-[8.5rem]">
                        <SelectField
                            value={perPage}
                            onChange={(value) => { setPerPage(String(value)); setPage(1); }}
                            options={PER_PAGE}
                        />
                    </div>
                </div>

                {loading && <TableSkeleton rows={7} columns={4} className="px-4 py-4" />}
                {!loading && rows.length === 0 && (
                    <div className="mx-auto max-w-sm px-4 py-16 text-center">
                        <Users size={28} className="mx-auto text-coral" />
                        <h2 className="mt-3 text-base font-semibold text-ink">No contacts yet</h2>
                        <p className="mt-1 text-sm text-muted">People appear here when they message the shop.</p>
                    </div>
                )}
                {!loading && rows.length > 0 && (
                    <div className="mt-4 overflow-hidden border-y border-line bg-white sm:rounded-2xl sm:border">
                        {rows.map((contact) => (
                            <button
                                key={contact.id}
                                type="button"
                                onClick={() => setOpen(contact)}
                                className="flex w-full items-center gap-3 border-b border-line px-4 py-3 text-start last:border-0 hover:bg-bubble"
                            >
                                <LeadAvatar name={contact.name} src={contact.avatar_url} size={40} />
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="truncate text-[13px] font-semibold text-ink">{contact.name}</span>
                                        {contact.lifecycle && (
                                            <span className="rounded bg-bubble px-1.5 py-0.5 text-[10px] font-semibold capitalize text-muted">{contact.lifecycle}</span>
                                        )}
                                    </div>
                                    <div className="mt-0.5 flex items-center gap-2 text-[12px] text-muted">
                                        <PlatformDot platform={contact.platform} />
                                        <span className="truncate">{contact.phone || 'No phone'}</span>
                                        {contact.wilaya && <span>· {contact.wilaya}</span>}
                                    </div>
                                </div>
                            </button>
                        ))}
                    </div>
                )}

                {!loading && meta.last_page > 1 && (
                    <div className="mt-4 flex flex-wrap items-center justify-between gap-2 text-[12px] text-muted">
                        <span>
                            {meta.from}–{meta.to} of {meta.total}
                        </span>
                        <div className="flex gap-1">
                            <button
                                type="button"
                                disabled={page <= 1}
                                onClick={() => setPage((p) => Math.max(1, p - 1))}
                                className="h-8 rounded-lg border border-line px-2.5 font-semibold text-ink disabled:opacity-40"
                            >
                                Prev
                            </button>
                            <span className="inline-flex h-8 items-center px-2 font-medium text-ink">
                                {meta.current_page} / {meta.last_page}
                            </span>
                            <button
                                type="button"
                                disabled={page >= meta.last_page}
                                onClick={() => setPage((p) => p + 1)}
                                className="h-8 rounded-lg border border-line px-2.5 font-semibold text-ink disabled:opacity-40"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {open && (
                <PersonDetailModal
                    person={open}
                    title={open.name}
                    badges={open.lifecycle ? (
                        <span className="rounded-md bg-white px-1.5 py-0.5 text-[10px] font-semibold capitalize text-muted ring-1 ring-line">
                            {open.lifecycle}
                        </span>
                    ) : null}
                    onClose={() => setOpen(null)}
                    onOpenChat={() => openConversation(open)}
                    onSaved={onSaved}
                />
            )}
        </>
    );
}

function PlatformDot({ platform }) {
    const meta = platformMeta(platform);
    const Icon = meta.Icon;
    return <Icon size={11} className="shrink-0" style={{ color: meta.color }} />;
}
