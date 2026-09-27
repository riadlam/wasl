import { createRoot } from 'react-dom/client';
import { useEffect, useState } from 'react';
import { Building2, LogOut } from 'lucide-react';
import { api, bootCsrf } from './space/api';

function AdminApp() {
    const [businesses, setBusinesses] = useState([]);
    const [error, setError] = useState('');

    const load = async () => {
        const { data } = await api.get('/admin/businesses');
        setBusinesses(data.businesses || []);
    };

    useEffect(() => {
        (async () => {
            try {
                await bootCsrf();
                await load();
            } catch (e) {
                if (e.response?.status === 401) window.location.assign('/login');
                else setError(e.response?.data?.message || 'Could not load tenants');
            }
        })();
    }, []);

    return (
        <div className="flex h-dvh overflow-hidden bg-white">
            <aside className="hidden h-full w-[220px] shrink-0 flex-col border-e border-line bg-white md:flex">
                <div className="border-b border-line px-3 py-3">
                    <p className="text-sm font-extrabold tracking-tight text-ink">Wasl</p>
                    <p className="text-[11px] font-medium text-muted">Platform admin</p>
                </div>
                <nav className="flex flex-1 flex-col gap-0.5 p-2">
                    <div className="relative flex w-full items-center gap-2.5 rounded-xl bg-selected px-2.5 py-2 text-sm font-semibold text-ink">
                        <span className="absolute start-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-full bg-accent" />
                        <Building2 size={18} />
                        Tenants
                    </div>
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
                        <h1 className="text-2xl font-extrabold">Tenants</h1>
                    </div>
                    <form method="POST" action="/logout">
                        <input type="hidden" name="_token" value={document.querySelector('meta[name="csrf-token"]')?.content || ''} />
                        <button type="submit" className="text-sm font-semibold text-muted">Log out</button>
                    </form>
                </div>
                <div className="mb-6 hidden md:block">
                    <h1 className="text-2xl font-extrabold tracking-tight">Tenants</h1>
                    <p className="mt-1 text-sm text-muted">Every shop on the platform.</p>
                </div>
                {error && <p className="mb-4 text-sm text-coral-dark">{error}</p>}
                <div className="overflow-hidden rounded-2xl border border-line bg-white">
                    {businesses.map((b) => (
                        <div key={b.id} className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3 last:border-0">
                            <div>
                                <div className="font-semibold">{b.name}</div>
                                <div className="text-sm text-muted">{b.slug} · {b.status} · {b.users_count} people</div>
                            </div>
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    className="rounded-lg border border-line px-3 py-1.5 text-xs font-semibold"
                                    onClick={async () => {
                                        await api.patch(`/admin/businesses/${b.id}`, { status: b.status === 'active' ? 'suspended' : 'active' });
                                        await load();
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
            </div>
        </div>
    );
}

const el = document.getElementById('admin-root');
if (el) createRoot(el).render(<AdminApp />);
