import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { MessageCircle, ShoppingBag } from 'lucide-react';
import { api } from '../api';
import { useSpace } from '../context';
import { queryKeys } from '../query';
import { platformMeta } from './channels/platforms';
import { LeadAvatar } from './ui';
import Modal from './modals/Modal';
import { SearchField } from './form';
import { TableSkeleton } from './inboxSkeletons';

const STATUS_FILTERS = ['all', 'pending', 'shipped', 'delivered', 'cancelled'];
const OWNER_STATUS_ACTIONS = [
    { id: 'pending', label: 'Pending' },
    { id: 'shipped', label: 'Shipped' },
    { id: 'delivered', label: 'Delivered' },
    { id: 'cancelled', label: 'Cancel' },
];

export default function OrdersView() {
    const { setError, setView, openInboxConversation } = useSpace();
    const queryClient = useQueryClient();
    const [status, setStatus] = useState('all');
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [open, setOpen] = useState(null);
    const [statusSaving, setStatusSaving] = useState(false);

    useEffect(() => {
        const timer = window.setTimeout(() => setSearch(searchInput.trim()), 280);
        return () => window.clearTimeout(timer);
    }, [searchInput]);

    const ordersQuery = useQuery({
        queryKey: queryKeys.orders(status, search),
        queryFn: async () => {
            const { data } = await api.get('/orders', {
                params: {
                    search: search || undefined,
                    status: status === 'all' ? undefined : status,
                },
            });
            return {
                orders: data.orders || [],
                counts: data.counts || { all: 0, pending: 0, cancelled: 0 },
            };
        },
        placeholderData: (prev) => prev,
    });

    const orders = ordersQuery.data?.orders || [];
    const counts = ordersQuery.data?.counts || { all: 0, pending: 0, cancelled: 0 };
    const loading = ordersQuery.isLoading && !ordersQuery.data;

    useEffect(() => {
        if (ordersQuery.error) {
            setError(ordersQuery.error.response?.data?.message || 'Could not load orders.');
        }
    }, [ordersQuery.error, setError]);

    const openConversation = (order) => {
        if (!order?.conversation_id) {
            setError('This order has no conversation yet.');
            return;
        }
        openInboxConversation(order.conversation_id, order.conversation_status);
    };

    const updateOrderStatus = async (order, nextStatus) => {
        if (!order?.id || order.status === nextStatus || statusSaving) return;
        setStatusSaving(true);
        setError('');
        try {
            const { data } = await api.patch(`/orders/${order.id}/status`, { status: nextStatus });
            const updated = data.order || { ...order, status: nextStatus };
            setOpen(updated);
            await queryClient.invalidateQueries({ queryKey: ['orders'] });
        } catch (err) {
            setError(err.response?.data?.message || 'Could not update order status.');
        } finally {
            setStatusSaving(false);
        }
    };

    return (
        <div className="flex h-full min-h-0 flex-col bg-white">
            <div className="min-h-0 flex-1 overflow-y-auto">
                <header className="border-b border-line px-4 py-4 sm:px-6">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-coral">Wasl</p>
                            <h1 className="mt-1 text-2xl font-extrabold tracking-tight text-ink">Orders</h1>
                            <p className="mt-1 text-sm text-muted">Confirmed sales the AI agent captured from inbox.</p>
                        </div>
                        <div className="flex gap-2">
                            <CountChip label="Pending" value={counts.pending} accent />
                            <CountChip label="Cancelled" value={counts.cancelled} />
                        </div>
                    </div>
                    <div className="mt-4 min-w-[180px] flex-1">
                        <SearchField value={searchInput} onChange={setSearchInput} placeholder="Search name, phone, order, or city" />
                    </div>
                    <div className="mt-3 flex flex-wrap gap-1.5">
                        {STATUS_FILTERS.map((id) => (
                            <button
                                key={id}
                                type="button"
                                onClick={() => setStatus(id)}
                                className={`h-8 rounded-lg border px-2.5 text-xs font-semibold capitalize ${
                                    status === id ? 'border-ink bg-ink text-white' : 'border-line bg-white text-ink hover:bg-bubble'
                                }`}
                            >
                                {id === 'all' ? 'All' : id}
                            </button>
                        ))}
                    </div>
                </header>

                <div className="px-0 sm:px-6 sm:py-4">
                    {loading && (
                        <div className="px-4 py-4 sm:px-0">
                            <TableSkeleton rows={6} columns={4} />
                        </div>
                    )}
                    {!loading && orders.length === 0 && (
                        <div className="mx-auto max-w-sm px-4 py-16 text-center">
                            <ShoppingBag size={28} className="mx-auto text-coral" />
                            <h2 className="mt-3 text-base font-bold text-ink">No orders yet</h2>
                            <p className="mt-1 text-sm text-muted">The agent captures an order when a client confirms they want to buy and leaves their phone and delivery info.</p>
                            <button
                                type="button"
                                onClick={() => setView('inbox')}
                                className="mt-4 h-9 rounded-lg bg-coral px-3 text-xs font-semibold text-white"
                            >
                                Open inbox
                            </button>
                        </div>
                    )}
                    {!loading && orders.length > 0 && (
                        <div className="overflow-hidden border-y border-line bg-white sm:rounded-2xl sm:border">
                            {orders.map((order) => (
                                <button
                                    key={order.id}
                                    type="button"
                                    onClick={() => setOpen(order)}
                                    className="flex w-full items-center gap-3 border-b border-line px-4 py-3 text-start last:border-0 hover:bg-bubble"
                                >
                                    <LeadAvatar name={order.name} src={order.avatar_url} size={40} />
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="truncate text-[13px] font-semibold text-ink">{order.name}</span>
                                            <OrderStatus status={order.status} />
                                        </div>
                                        <div className="mt-0.5 flex items-center gap-2 text-[12px] text-muted">
                                            <PlatformDot platform={order.platform} />
                                            <span className="truncate">{order.phone || 'No phone'}</span>
                                            {order.wilaya && <span>· {order.wilaya}</span>}
                                        </div>
                                    </div>
                                    <div className="shrink-0 text-end">
                                        <div className="text-[13px] font-semibold text-ink">{money(order.total, order.currency)}</div>
                                        <div className="text-[11px] text-muted">{order.placed_at || ''}</div>
                                    </div>
                                </button>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {open && (
                <OrderModal
                    order={open}
                    saving={statusSaving}
                    onClose={() => setOpen(null)}
                    onOpenChat={() => openConversation(open)}
                    onStatusChange={(next) => updateOrderStatus(open, next)}
                />
            )}
        </div>
    );
}

function OrderModal({ order, saving, onClose, onOpenChat, onStatusChange }) {
    const meta = platformMeta(order.platform);
    const Icon = meta.Icon;
    const delivery = [order.delivery_label, order.wilaya, order.commune].filter(Boolean).join(' · ');

    return (
        <Modal open title={order.order_number} onClose={onClose} wide>
            <div className="flex items-start gap-3">
                <LeadAvatar name={order.name} src={order.avatar_url} size={48} />
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span className="text-[15px] font-bold text-ink">{order.name}</span>
                        <OrderStatus status={order.status} />
                        <span className="inline-flex items-center gap-1 rounded bg-bubble px-1.5 py-0.5 text-[10px] font-semibold text-ink">
                            <Icon size={10} style={{ color: meta.color }} /> {meta.label}
                        </span>
                    </div>
                    <p className="mt-1 text-[12px] text-muted">{order.account_name || 'Linked page'}</p>
                </div>
            </div>
            <dl className="mt-4 divide-y divide-line">
                <InfoRow label="Phone" value={order.phone} />
                <InfoRow label="Delivery" value={delivery} />
                <InfoRow label="Address" value={order.address} />
                <InfoRow label="AI notes" value={order.ai_notes} />
            </dl>
            <ul className="mt-3 space-y-1.5">
                {(order.items || []).map((item) => (
                    <li key={item.id || item.name} className="flex items-baseline justify-between gap-3 rounded-lg bg-bubble px-3 py-2">
                        <span className="text-[13px] text-ink">{item.quantity} × {item.name}</span>
                        <span className="shrink-0 text-[12px] font-semibold text-ink">{money(item.total, order.currency)}</span>
                    </li>
                ))}
            </ul>
            <dl className="mt-3 divide-y divide-line">
                <InfoRow label="Subtotal" value={money(order.subtotal, order.currency)} />
                <InfoRow label="Delivery fee" value={money(order.delivery_fee, order.currency)} />
                <InfoRow label="Total" value={money(order.total, order.currency)} />
            </dl>
            <div className="mt-4">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-muted">Fulfillment status</p>
                <p className="mt-1 text-[12px] text-muted">
                    Mark shipped/delivered only after you verify payment and fulfill. The agent may say “done” only from this status.
                </p>
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {OWNER_STATUS_ACTIONS.map((action) => {
                        const active = order.status === action.id;
                        const isCancel = action.id === 'cancelled';
                        return (
                            <button
                                key={action.id}
                                type="button"
                                disabled={saving || active}
                                onClick={() => onStatusChange(action.id)}
                                className={`h-8 rounded-lg border px-2.5 text-xs font-semibold disabled:opacity-50 ${
                                    active
                                        ? 'border-ink bg-ink text-white'
                                        : isCancel
                                          ? 'border-coral/40 bg-white text-coral hover:bg-coral/5'
                                          : 'border-line bg-white text-ink hover:bg-bubble'
                                }`}
                            >
                                {action.label}
                            </button>
                        );
                    })}
                </div>
            </div>
            <button
                type="button"
                onClick={onOpenChat}
                disabled={!order.conversation_id}
                className="mt-5 inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl bg-coral text-sm font-semibold text-white disabled:opacity-50"
            >
                <MessageCircle size={16} /> View conversation
            </button>
        </Modal>
    );
}

function CountChip({ label, value, accent }) {
    return (
        <div className={`rounded-xl border px-3 py-2 ${accent ? 'border-coral/30 bg-coral/5' : 'border-line bg-bubble'}`}>
            <div className="text-[10px] font-semibold uppercase tracking-wide text-muted">{label}</div>
            <div className="text-lg font-extrabold text-ink">{value ?? 0}</div>
        </div>
    );
}

function OrderStatus({ status }) {
    const tone = {
        pending: 'bg-info text-accent',
        shipped: 'bg-bubble text-ink',
        delivered: 'bg-teal/10 text-teal-dark',
        cancelled: 'bg-coral/10 text-coral-dark',
    }[status] || 'bg-bubble text-ink';

    return (
        <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold capitalize ${tone}`}>
            {status}
        </span>
    );
}

function PlatformDot({ platform }) {
    const meta = platformMeta(platform);
    const Icon = meta.Icon;
    return <Icon size={11} className="shrink-0" style={{ color: meta.color }} />;
}

function InfoRow({ label, value }) {
    return (
        <div className="flex items-baseline justify-between gap-2 py-1.5">
            <dt className="text-[11px] font-semibold text-muted">{label}</dt>
            <dd className={`truncate text-[12px] ${value ? 'font-medium text-ink' : 'text-muted'}`}>{value || '—'}</dd>
        </div>
    );
}

function money(amount, currency = 'DZD') {
    const n = Number(amount);
    if (!Number.isFinite(n)) {
        return `— ${currency}`;
    }
    const shown = Number.isInteger(n) ? String(n) : n.toFixed(2);

    return `${shown} ${currency}`;
}
