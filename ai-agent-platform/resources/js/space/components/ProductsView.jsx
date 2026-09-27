import { useEffect, useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Boxes, Plus, Truck, Wallet } from 'lucide-react';
import { api } from '../api';
import { useSpace } from '../context';
import { queryKeys } from '../query';
import { platformMeta } from './channels/platforms';
import DeliveryPanel from './products/DeliveryPanel';
import PaymentMethodsPanel from './products/PaymentMethodsPanel';
import ProductEditor from './products/ProductEditor';
import { SearchField } from './form';
import { SplitViewSkeleton } from './inboxSkeletons';

const CATALOG_FILTERS = [
    { id: 'all', label: 'All' },
    { id: 'physical', label: 'Physical' },
    { id: 'digital', label: 'Digital' },
    { id: 'active', label: 'Active' },
];

export default function ProductsView({ initialSection = 'catalog' }) {
    const { can, setError, setView } = useSpace();
    const queryClient = useQueryClient();
    const [section, setSection] = useState(
        initialSection === 'delivery' || initialSection === 'payments' ? initialSection : 'catalog',
    );
    const [filter, setFilter] = useState('all');
    const [query, setQuery] = useState('');
    const [editing, setEditing] = useState(null);
    const [creating, setCreating] = useState(false);

    const productsQuery = useQuery({
        queryKey: queryKeys.products,
        queryFn: async () => {
            const { data } = await api.get('/products');
            return data.products || [];
        },
    });

    const zonesQuery = useQuery({
        queryKey: queryKeys.deliveryZones,
        queryFn: async () => {
            try {
                const { data } = await api.get('/delivery-zones');
                return data.zones || [];
            } catch {
                return [];
            }
        },
    });

    const products = productsQuery.data || [];
    const zones = zonesQuery.data || [];
    const loading = productsQuery.isLoading && !productsQuery.data;

    useEffect(() => {
        if (productsQuery.error) {
            setError(productsQuery.error.response?.data?.message || 'Could not load products.');
        }
    }, [productsQuery.error, setError]);

    const openCatalog = (nextFilter = 'all') => {
        setFilter(nextFilter);
        setView('products');
    };

    const openDelivery = () => setView('delivery');
    const openPayments = () => setView('payments');

    useEffect(() => {
        if (initialSection === 'delivery' || initialSection === 'payments') {
            setSection(initialSection);
        } else {
            setSection('catalog');
        }
    }, [initialSection]);

    const visible = useMemo(() => {
        const needle = query.trim().toLowerCase();
        return products.filter((item) => {
            if (filter === 'physical' && item.type !== 'physical') return false;
            if (filter === 'digital' && item.type !== 'digital') return false;
            if (filter === 'active' && item.status !== 'active') return false;
            return !needle || `${item.name} ${item.sku || ''} ${item.category || ''}`.toLowerCase().includes(needle);
        });
    }, [products, filter, query]);

    if (loading) {
        return <SplitViewSkeleton cards={6} />;
    }

    return (
        <div className="relative flex h-full min-h-0 overflow-hidden bg-cream">
            <aside className="hidden h-full w-[220px] shrink-0 flex-col border-e border-line bg-white/80 lg:flex">
                <div className="flex h-11 items-center px-3">
                    <span className="text-[14px] font-semibold text-ink">Products</span>
                </div>

                <p className="px-3 pb-1 text-[10px] font-semibold uppercase tracking-wide text-muted">Catalog</p>
                <div className="flex flex-col gap-0.5 px-2">
                    {CATALOG_FILTERS.map((item) => {
                        const active = section === 'catalog' && filter === item.id;
                        return (
                            <button
                                key={item.id}
                                type="button"
                                onClick={() => openCatalog(item.id)}
                                className={`flex h-9 items-center rounded-lg px-2 text-start text-[13px] ${
                                    active ? 'bg-selected font-semibold text-ink' : 'text-muted hover:bg-bubble'
                                }`}
                            >
                                {item.label}
                            </button>
                        );
                    })}
                </div>

                <p className="mt-4 px-3 pb-1 text-[10px] font-semibold uppercase tracking-wide text-muted">Shipping</p>
                <div className="px-2">
                    <button
                        type="button"
                        onClick={openDelivery}
                        className={`flex h-9 w-full items-center gap-2 rounded-lg px-2 text-start text-[13px] ${
                            section === 'delivery' ? 'bg-selected font-semibold text-ink' : 'text-muted hover:bg-bubble'
                        }`}
                    >
                        <Truck size={15} strokeWidth={1.75} className="shrink-0" />
                        Delivery zones
                    </button>
                </div>

                <p className="mt-4 px-3 pb-1 text-[10px] font-semibold uppercase tracking-wide text-muted">Checkout</p>
                <div className="px-2">
                    <button
                        type="button"
                        onClick={openPayments}
                        className={`flex h-9 w-full items-center gap-2 rounded-lg px-2 text-start text-[13px] ${
                            section === 'payments' ? 'bg-selected font-semibold text-ink' : 'text-muted hover:bg-bubble'
                        }`}
                    >
                        <Wallet size={15} strokeWidth={1.75} className="shrink-0" />
                        Payment methods
                    </button>
                </div>
            </aside>

            <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
                <div className="flex gap-1 border-b border-line bg-white px-3 py-2 lg:hidden">
                    <button
                        type="button"
                        onClick={() => openCatalog(filter)}
                        className={`h-8 flex-1 rounded-lg text-[12px] font-semibold ${
                            section === 'catalog' ? 'bg-selected text-ink' : 'text-muted'
                        }`}
                    >
                        Catalog
                    </button>
                    <button
                        type="button"
                        onClick={openDelivery}
                        className={`h-8 flex-1 rounded-lg text-[12px] font-semibold ${
                            section === 'delivery' ? 'bg-selected text-ink' : 'text-muted'
                        }`}
                    >
                        Delivery
                    </button>
                    <button
                        type="button"
                        onClick={openPayments}
                        className={`h-8 flex-1 rounded-lg text-[12px] font-semibold ${
                            section === 'payments' ? 'bg-selected text-ink' : 'text-muted'
                        }`}
                    >
                        Payments
                    </button>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    {section === 'delivery' ? (
                        <DeliveryPanel />
                    ) : section === 'payments' ? (
                        <PaymentMethodsPanel />
                    ) : (
                        <>
                            <header className="border-b border-line bg-white/70 px-4 py-4 sm:px-6">
                                <div className="flex flex-wrap items-end justify-between gap-3">
                                    <div>
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-coral">Wasl</p>
                                        <h1 className="mt-1 text-2xl font-extrabold tracking-tight text-ink">Products</h1>
                                        <p className="mt-1 text-sm font-medium text-ink/70">Physical and digital catalog the agent can sell from the inbox.</p>
                                    </div>
                                    {can('products.manage') && (
                                        <button
                                            type="button"
                                            onClick={() => { setCreating(true); setEditing(null); }}
                                            className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-coral px-3.5 text-sm font-semibold text-white hover:bg-coral-dark"
                                        >
                                            <Plus size={16} />
                                            New product
                                        </button>
                                    )}
                                </div>
                                <div className="mt-4 max-w-md">
                                    <SearchField value={query} onChange={setQuery} placeholder="Search name, SKU, category" />
                                </div>
                                <div className="mt-3 flex flex-wrap gap-1 lg:hidden">
                                    {CATALOG_FILTERS.map((item) => (
                                        <button
                                            key={item.id}
                                            type="button"
                                            onClick={() => setFilter(item.id)}
                                            className={`h-7 rounded-full px-2.5 text-[11px] font-semibold ${
                                                filter === item.id ? 'bg-ink text-white' : 'bg-bubble text-muted'
                                            }`}
                                        >
                                            {item.label}
                                        </button>
                                    ))}
                                </div>
                            </header>

                            <div className="px-4 py-5 sm:px-6">
                                {visible.length === 0 ? (
                                    <EmptyState canManage={can('products.manage')} onCreate={() => setCreating(true)} />
                                ) : (
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                        {visible.map((item) => (
                                            <button
                                                key={item.id}
                                                type="button"
                                                onClick={() => { setEditing(item); setCreating(false); }}
                                                className="overflow-hidden rounded-2xl border border-line bg-white text-start shadow-card transition hover:border-coral/40"
                                            >
                                                <div className="aspect-[4/3] bg-bubble">
                                                    {item.main_image_url ? (
                                                        <img src={item.main_image_url} alt="" className="h-full w-full object-cover" />
                                                    ) : (
                                                        <div className="flex h-full items-center justify-center text-muted">
                                                            <Boxes size={28} />
                                                        </div>
                                                    )}
                                                </div>
                                                <div className="space-y-2 p-4">
                                                    <div className="flex items-start justify-between gap-2">
                                                        <h2 className="truncate text-sm font-bold text-ink">{item.name}</h2>
                                                        <TypePill type={item.type} />
                                                    </div>
                                                    <PriceRow product={item} />
                                                    <p className="text-[12px] text-muted">
                                                        Stock {item.stock}
                                                        {item.variants?.length ? ` · ${item.variants.length} variants` : ''}
                                                        {item.sku ? ` · ${item.sku}` : ''}
                                                    </p>
                                                    <ChannelRow product={item} />
                                                </div>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </div>
            </div>

            {(creating || editing) && (
                <ProductEditor
                    product={creating ? null : editing}
                    zones={zones}
                    onClose={() => { setCreating(false); setEditing(null); }}
                    onSaved={(saved) => {
                        queryClient.setQueryData(queryKeys.products, (list = []) => {
                            const rest = list.filter((row) => row.id !== saved.id);
                            return [saved, ...rest];
                        });
                        setCreating(false);
                        setEditing(null);
                    }}
                    onDeleted={(id) => {
                        queryClient.setQueryData(queryKeys.products, (list = []) => list.filter((row) => row.id !== id));
                        setCreating(false);
                        setEditing(null);
                    }}
                />
            )}
        </div>
    );
}

function PriceRow({ product }) {
    const price = Number(product.price);
    const compare = product.compare_price != null ? Number(product.compare_price) : null;
    const onSale = compare !== null && compare > price;
    const off = onSale ? Math.round((1 - price / compare) * 100) : 0;

    return (
        <p className="flex flex-wrap items-baseline gap-2">
            <span className="text-sm font-semibold text-ink">{price.toLocaleString()} DZD</span>
            {onSale && (
                <>
                    <span className="text-[12px] text-muted line-through">{compare.toLocaleString()}</span>
                    <span className="rounded-full bg-coral/10 px-1.5 py-0.5 text-[10px] font-semibold text-coral">-{off}%</span>
                </>
            )}
        </p>
    );
}

function TypePill({ type }) {
    const digital = type === 'digital';
    return (
        <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ${digital ? 'bg-selected text-accent' : 'bg-bubble text-ink'}`}>
            {digital ? 'Digital' : 'Physical'}
        </span>
    );
}

function ChannelRow({ product }) {
    if (product.channel_scope !== 'selected' || !product.channels?.length) {
        return <p className="text-[11px] font-medium text-muted">All linked channels</p>;
    }
    return (
        <p className="flex flex-wrap gap-1">
            {product.channels.map((channel) => {
                const meta = platformMeta(channel.platform);
                return (
                    <span key={channel.id} className="rounded-md bg-bubble px-1.5 py-0.5 text-[10px] font-semibold text-ink">
                        {meta.label}
                    </span>
                );
            })}
        </p>
    );
}

function EmptyState({ canManage, onCreate }) {
    return (
        <div className="mx-auto flex max-w-md flex-col items-center rounded-2xl border border-dashed border-line bg-bubble/60 px-6 py-14 text-center">
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white shadow-sm">
                <Boxes size={22} className="text-coral" />
            </div>
            <h2 className="mt-4 text-base font-bold text-ink">No products yet</h2>
            <p className="mt-1 text-sm text-muted">Add a physical item or a digital product. The agent will look them up for price and stock.</p>
            {canManage && (
                <button type="button" onClick={onCreate} className="mt-5 inline-flex h-9 items-center gap-1.5 rounded-lg bg-coral px-3.5 text-sm font-semibold text-white hover:bg-coral-dark">
                    <Plus size={16} /> New product
                </button>
            )}
        </div>
    );
}
