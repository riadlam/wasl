import { createRoot } from 'react-dom/client';
import { useEffect, useState } from 'react';
import { Building2, LogOut, Activity } from 'lucide-react';
import { api, bootCsrf } from './space/api';

function AdminApp() {
    const [tab, setTab] = useState('tenants');
    const [businesses, setBusinesses] = useState([]);
    const [charges, setCharges] = useState([]);
    const [balances, setBalances] = useState([]);
    const [taskTypes, setTaskTypes] = useState([]);
    const [filterBusiness, setFilterBusiness] = useState('');
    const [filterType, setFilterType] = useState('');
    const [topupDraft, setTopupDraft] = useState({});
    const [error, setError] = useState('');
    const [flash, setFlash] = useState('');

    const loadTenants = async () => {
        const { data } = await api.get('/admin/businesses');
        setBusinesses(data.businesses || []);
    };

    const loadUsage = async () => {
        const params = {};
        if (filterBusiness) params.business_id = filterBusiness;
        if (filterType) params.task_type = filterType;
        const [usageRes, balRes] = await Promise.all([
            api.get('/admin/ai-usage', { params }),
            api.get('/admin/wallet/balances'),
        ]);
        setCharges(usageRes.data.charges || []);
        setTaskTypes(usageRes.data.task_types || []);
        setBalances(balRes.data.balances || []);
    };

    useEffect(() => {
        (async () => {
            try {
                await bootCsrf();
                await loadTenants();
            } catch (e) {
                if (e.response?.status === 401) window.location.assign('/login');
                else setError(e.response?.data?.message || 'Could not load tenants');
            }
        })();
    }, []);

    useEffect(() => {
        if (tab !== 'usage') return;
        (async () => {
            try {
                setError('');
                await loadUsage();
            } catch (e) {
                setError(e.response?.data?.message || 'Could not load usage');
            }
        })();
    }, [tab, filterBusiness, filterType]);

    const topup = async (ownerUserId, businessId, amountOverride) => {
        const key = `${businessId}`;
        const amount = Number(amountOverride ?? topupDraft[key] ?? 0);
        if (!ownerUserId || !(amount > 0)) {
            setError('Enter a positive DA amount and ensure the shop has an owner.');
            return;
        }
        try {
            setError('');
            setFlash('');
            await api.post('/admin/wallet/topup', {
                user_id: ownerUserId,
                amount_da: amount,
                business_id: businessId,
                note: 'admin UI top-up',
            });
            setTopupDraft((d) => ({ ...d, [key]: '', [`u${businessId}`]: '' }));
            setFlash(`Topped up ${amount} DA`);
            await loadTenants();
            if (tab === 'usage') await loadUsage();
        } catch (e) {
            setError(e.response?.data?.message || 'Top-up failed');
        }
    };

    const navBtn = (id, label, Icon) => (
        <button
            type="button"
            key={id}
            onClick={() => setTab(id)}
            className={`relative flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-sm font-semibold ${
                tab === id ? 'bg-selected text-ink' : 'text-muted hover:bg-bubble hover:text-ink'
            }`}
        >
            {tab === id && <span className="absolute start-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-full bg-accent" />}
            <Icon size={18} />
            {label}
        </button>
    );

    return (
        <div className="flex h-dvh overflow-hidden bg-white">
            <aside className="hidden h-full w-[220px] shrink-0 flex-col border-e border-line bg-white md:flex">
                <div className="border-b border-line px-3 py-3">
                    <p className="text-sm font-extrabold tracking-tight text-ink">Wasl</p>
                    <p className="text-[11px] font-medium text-muted">Platform admin</p>
                </div>
                <nav className="flex flex-1 flex-col gap-0.5 p-2">
                    {navBtn('tenants', 'Tenants', Building2)}
                    {navBtn('usage', 'Usage', Activity)}
                </nav>
                <div className="border-t border-line p-2">
                    <form method="POST" action="/logout">
                        <input type="hidden" name="_token" value={document.querySelector('meta[name="csrf-token"]')?.content || ''} />
                        <button type="submit" className="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-sm font-semibold text-muted hover:bg-bubble hover:text-ink">
                            <LogOut size={16} />
                            Log out
                        </button>
                    </form>
                </div>
            </aside>

            <div className="min-w-0 flex-1 overflow-y-auto px-4 py-8 sm:px-8">
                <div className="mb-6 flex items-center justify-between md:hidden">
                    <div>
                        <p className="text-sm font-semibold text-muted">Wasl platform</p>
                        <h1 className="text-2xl font-extrabold">{tab === 'usage' ? 'Usage' : 'Tenants'}</h1>
                    </div>
                    <div className="flex gap-2">
                        <button type="button" className={`rounded-lg px-2 py-1 text-xs font-semibold ${tab === 'tenants' ? 'bg-ink text-white' : 'border border-line'}`} onClick={() => setTab('tenants')}>Tenants</button>
                        <button type="button" className={`rounded-lg px-2 py-1 text-xs font-semibold ${tab === 'usage' ? 'bg-ink text-white' : 'border border-line'}`} onClick={() => setTab('usage')}>Usage</button>
                    </div>
                </div>
                <div className="mb-6 hidden md:block">
                    <h1 className="text-2xl font-extrabold tracking-tight">{tab === 'usage' ? 'AI usage' : 'Tenants'}</h1>
                    <p className="mt-1 text-sm text-muted">
                        {tab === 'usage' ? 'Charges from Fal / SK AI work and shop wallet balances.' : 'Every shop on the platform.'}
                    </p>
                </div>
                {error && <p className="mb-4 text-sm text-coral-dark">{error}</p>}
                {flash && <p className="mb-4 text-sm text-emerald-700">{flash}</p>}

                {tab === 'tenants' && (
                    <div className="overflow-hidden rounded-2xl border border-line bg-white">
                        {businesses.map((b) => (
                            <div key={b.id} className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3 last:border-0">
                                <div>
                                    <div className="font-semibold">{b.name}</div>
                                    <div className="text-sm text-muted">
                                        {b.slug} · {b.status} · {b.users_count} people
                                        {b.wallet_balance_da != null && (
                                            <> · wallet {Number(b.wallet_balance_da).toFixed(0)} DA</>
                                        )}
                                    </div>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <input
                                        type="number"
                                        min="1"
                                        step="1"
                                        placeholder="Top-up DA"
                                        className="w-28 rounded-lg border border-line px-2 py-1.5 text-xs"
                                        value={topupDraft[b.id] ?? ''}
                                        onChange={(e) => setTopupDraft((d) => ({ ...d, [b.id]: e.target.value }))}
                                    />
                                    <button
                                        type="button"
                                        className="rounded-lg border border-line px-3 py-1.5 text-xs font-semibold"
                                        onClick={() => topup(b.owner_user_id, b.id)}
                                    >
                                        Top up
                                    </button>
                                    <button
                                        type="button"
                                        className="rounded-lg border border-line px-3 py-1.5 text-xs font-semibold"
                                        onClick={async () => {
                                            await api.patch(`/admin/businesses/${b.id}`, { status: b.status === 'active' ? 'suspended' : 'active' });
                                            await loadTenants();
                                        }}
                                    >
                                        {b.status === 'active' ? 'Suspend' : 'Activate'}
                                    </button>
                                    <button
                                        type="button"
                                        className="rounded-lg bg-ink px-3 py-1.5 text-xs font-semibold text-white"
                                        onClick={async () => {
                                            await api.post(`/admin/businesses/${b.id}/impersonate`);
                                            window.location.assign('/space');
                                        }}
                                    >
                                        Open shop
                                    </button>
                                </div>
                            </div>
                        ))}
                        {businesses.length === 0 && <p className="px-4 py-8 text-center text-sm text-muted">No shops yet.</p>}
                    </div>
                )}

                {tab === 'usage' && (
                    <div className="space-y-6">
                        <div className="flex flex-wrap gap-3">
                            <select
                                className="rounded-lg border border-line px-3 py-2 text-sm"
                                value={filterBusiness}
                                onChange={(e) => setFilterBusiness(e.target.value)}
                            >
                                <option value="">All shops</option>
                                {balances.map((b) => (
                                    <option key={b.business_id} value={b.business_id}>{b.name}</option>
                                ))}
                            </select>
                            <select
                                className="rounded-lg border border-line px-3 py-2 text-sm"
                                value={filterType}
                                onChange={(e) => setFilterType(e.target.value)}
                            >
                                <option value="">All task types</option>
                                {taskTypes.map((t) => (
                                    <option key={t} value={t}>{t}</option>
                                ))}
                            </select>
                        </div>

                        <div className="overflow-hidden rounded-2xl border border-line">
                            <div className="border-b border-line bg-bubble/40 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-muted">Wallet balances</div>
                            {balances.map((b) => (
                                <div key={b.business_id} className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-2.5 last:border-0">
                                    <div>
                                        <div className="text-sm font-semibold">{b.name}</div>
                                        <div className="text-xs text-muted">{b.owner_email || 'no owner'}</div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-bold">{b.balance_da != null ? `${Number(b.balance_da).toFixed(0)} DA` : '—'}</span>
                                        <input
                                            type="number"
                                            min="1"
                                            className="w-24 rounded-lg border border-line px-2 py-1 text-xs"
                                            placeholder="Top-up"
                                            value={topupDraft[`u${b.business_id}`] ?? ''}
                                            onChange={(e) => setTopupDraft((d) => ({ ...d, [`u${b.business_id}`]: e.target.value }))}
                                        />
                                        <button
                                            type="button"
                                            className="rounded-lg border border-line px-2 py-1 text-xs font-semibold"
                                            onClick={() => topup(b.owner_user_id, b.business_id, topupDraft[`u${b.business_id}`])}
                                        >
                                            Credit
                                        </button>
                                    </div>
                                </div>
                            ))}
                            {balances.length === 0 && <p className="px-4 py-6 text-center text-sm text-muted">No balances.</p>}
                        </div>

                        <div className="overflow-x-auto rounded-2xl border border-line">
                            <table className="min-w-full text-left text-sm">
                                <thead className="border-b border-line bg-bubble/40 text-xs uppercase tracking-wide text-muted">
                                    <tr>
                                        <th className="px-3 py-2 font-semibold">When</th>
                                        <th className="px-3 py-2 font-semibold">Shop</th>
                                        <th className="px-3 py-2 font-semibold">Type</th>
                                        <th className="px-3 py-2 font-semibold">Model</th>
                                        <th className="px-3 py-2 font-semibold">USD</th>
                                        <th className="px-3 py-2 font-semibold">DA</th>
                                        <th className="px-3 py-2 font-semibold">Actor</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {charges.map((c) => (
                                        <tr key={c.id} className="border-b border-line last:border-0">
                                            <td className="whitespace-nowrap px-3 py-2 text-xs text-muted">{c.created_at ? new Date(c.created_at).toLocaleString() : '—'}</td>
                                            <td className="px-3 py-2">{c.business_name || c.business_id}</td>
                                            <td className="px-3 py-2 font-mono text-xs">{c.task_type}</td>
                                            <td className="max-w-[140px] truncate px-3 py-2 text-xs" title={c.provider_model || c.model_key}>{c.model_key}</td>
                                            <td className="px-3 py-2">{Number(c.cost_usd).toFixed(4)}</td>
                                            <td className="px-3 py-2 font-semibold">{Number(c.cost_da).toFixed(0)}</td>
                                            <td className="px-3 py-2 text-xs text-muted">{c.actor_email || c.owner_email || '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {charges.length === 0 && <p className="px-4 py-8 text-center text-sm text-muted">No AI charges yet.</p>}
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

createRoot(document.getElementById('admin-root')).render(<AdminApp />);
