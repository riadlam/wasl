import { useEffect, useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { CreditCard, Save } from 'lucide-react';
import { api } from '../../api';
import { useSpace } from '../../context';
import { queryKeys } from '../../query';
import { TextField } from '../form';
import { ListSkeleton } from '../inboxSkeletons';

const METHOD_ORDER = ['flexy', 'baridimob', 'ccp'];

const EMPTY = {
    flexy: { method: 'flexy', label: 'Flexy', enabled: false, priority: null, phone: '', ccp_cle: '', ccp_number: '', logo_url: '/images/payments/flexy.svg' },
    baridimob: { method: 'baridimob', label: 'BaridiMob', enabled: false, priority: null, phone: '', ccp_cle: '', ccp_number: '', logo_url: '/images/payments/baridimob.svg' },
    ccp: { method: 'ccp', label: 'CCP', enabled: false, priority: null, phone: '', ccp_cle: '', ccp_number: '', logo_url: '/images/payments/ccp.svg' },
};

function normalizeList(list) {
    const map = { ...EMPTY };
    (list || []).forEach((row) => {
        if (!row?.method || !map[row.method]) return;
        map[row.method] = {
            ...map[row.method],
            ...row,
            phone: row.phone || '',
            ccp_cle: row.ccp_cle || '',
            ccp_number: row.ccp_number || '',
            priority: row.priority ?? null,
            enabled: !!row.enabled,
        };
    });
    return METHOD_ORDER.map((key) => map[key]);
}

export default function PaymentMethodsPanel() {
    const { can, setError } = useSpace();
    const queryClient = useQueryClient();
    const [draft, setDraft] = useState(null);
    const [saving, setSaving] = useState(false);
    const canManage = can('knowledge.manage');

    const methodsQuery = useQuery({
        queryKey: queryKeys.paymentMethods,
        queryFn: async () => {
            const { data } = await api.get('/payment-methods');
            return normalizeList(data.methods || []);
        },
    });

    useEffect(() => {
        if (methodsQuery.data) setDraft(methodsQuery.data);
    }, [methodsQuery.data]);

    useEffect(() => {
        if (methodsQuery.error) {
            setError(methodsQuery.error.response?.data?.message || 'Could not load payment methods.');
        }
    }, [methodsQuery.error, setError]);

    const rows = draft || normalizeList([]);
    const enabledPriorities = useMemo(
        () => rows.filter((r) => r.enabled).map((r) => Number(r.priority)).filter((n) => n >= 1 && n <= 3),
        [rows],
    );

    const updateRow = (method, patch) => {
        setDraft((prev) => (prev || normalizeList([])).map((row) => (
            row.method === method ? { ...row, ...patch } : row
        )));
    };

    const onToggle = (method, enabled) => {
        setDraft((prev) => {
            const list = prev || normalizeList([]);
            const used = new Set(
                list.filter((r) => r.enabled && r.method !== method).map((r) => Number(r.priority)).filter((n) => n >= 1 && n <= 3),
            );
            let nextPriority = null;
            if (enabled) {
                for (let p = 1; p <= 3; p += 1) {
                    if (!used.has(p)) {
                        nextPriority = p;
                        break;
                    }
                }
            }
            return list.map((row) => {
                if (row.method !== method) return row;
                return {
                    ...row,
                    enabled,
                    priority: enabled ? (row.priority || nextPriority) : null,
                };
            });
        });
    };

    const save = async () => {
        setSaving(true);
        try {
            const payload = {
                methods: rows.map((row) => ({
                    method: row.method,
                    enabled: !!row.enabled,
                    priority: row.enabled ? Number(row.priority) : null,
                    phone: row.phone || null,
                    ccp_cle: row.ccp_cle || null,
                    ccp_number: row.ccp_number || null,
                })),
            };
            const { data } = await api.put('/payment-methods', payload);
            const next = normalizeList(data.methods || []);
            queryClient.setQueryData(queryKeys.paymentMethods, next);
            setDraft(next);
        } catch (err) {
            setError(err.response?.data?.message || 'Could not save payment methods.');
        } finally {
            setSaving(false);
        }
    };

    if ((methodsQuery.isLoading && !methodsQuery.data) || !draft) {
        return (
            <div className="flex flex-1 flex-col px-4 py-6 sm:px-6">
                <ListSkeleton rows={3} className="max-w-2xl" />
            </div>
        );
    }

    return (
        <>
            <header className="border-b border-line bg-white/70 px-4 py-4 sm:px-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-coral">Wasl</p>
                        <h1 className="mt-1 text-2xl font-extrabold tracking-tight text-ink">Payments</h1>
                        <p className="mt-1 max-w-xl text-sm font-medium text-ink/70">
                            Flexy, BaridiMob, and CCP the agent recommends after an order. Set priority so it knows what to offer first.
                        </p>
                    </div>
                    {canManage && (
                        <button
                            type="button"
                            onClick={save}
                            disabled={saving}
                            className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-coral px-3.5 text-sm font-semibold text-white disabled:opacity-60"
                        >
                            <Save size={16} />
                            {saving ? 'Saving…' : 'Save'}
                        </button>
                    )}
                </div>
            </header>

            <div className="px-4 py-5 sm:px-6">
                <div className="mb-4 flex items-start gap-3 rounded-2xl border border-line bg-bubble/50 px-4 py-3">
                    <CreditCard size={18} className="mt-0.5 shrink-0 text-coral" />
                    <p className="text-[13px] font-medium text-ink/75">
                        Priority 1 is what the agent recommends first. If the client prefers another enabled method, it shares those details instead.
                        {enabledPriorities.length > 0 ? ` Enabled priorities: ${[...enabledPriorities].sort().join(', ')}.` : ''}
                    </p>
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    {rows.map((row) => (
                        <MethodCard
                            key={row.method}
                            row={row}
                            canManage={canManage}
                            usedPriorities={enabledPriorities}
                            onToggle={(enabled) => onToggle(row.method, enabled)}
                            onChange={(patch) => updateRow(row.method, patch)}
                        />
                    ))}
                </div>
            </div>
        </>
    );
}

function MethodCard({ row, canManage, usedPriorities, onToggle, onChange }) {
    const priorityOptions = [1, 2, 3].filter((p) => (
        Number(row.priority) === p || !usedPriorities.includes(p) || !row.enabled
    ));

    return (
        <article className={`overflow-hidden rounded-2xl border bg-white shadow-[0_12px_32px_-22px_rgba(31,42,55,0.45)] ${
            row.enabled ? 'border-coral/35' : 'border-line'
        }`}
        >
            <div className="relative h-28 overflow-hidden bg-ink/5">
                <img
                    src={row.logo_url || `/images/payments/${row.method}.svg`}
                    alt={row.label}
                    className="h-full w-full object-cover"
                />
                <div className="absolute inset-x-0 bottom-0 flex items-center justify-between bg-gradient-to-t from-ink/55 to-transparent px-3 pb-2 pt-8">
                    <span className="text-sm font-extrabold text-white drop-shadow">{row.label}</span>
                    <label className="inline-flex items-center gap-2 rounded-full bg-white/95 px-2.5 py-1 text-[11px] font-semibold text-ink">
                        <input
                            type="checkbox"
                            checked={!!row.enabled}
                            disabled={!canManage}
                            onChange={(e) => onToggle(e.target.checked)}
                            className="h-3.5 w-3.5 accent-[var(--coral,#e85d4c)]"
                        />
                        Enabled
                    </label>
                </div>
            </div>

            <div className="space-y-3 p-4">
                <div>
                    <label className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-muted">Priority</label>
                    <select
                        disabled={!canManage || !row.enabled}
                        value={row.enabled ? (row.priority || '') : ''}
                        onChange={(e) => onChange({ priority: e.target.value ? Number(e.target.value) : null })}
                        className="h-10 w-full rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink disabled:bg-bubble disabled:text-muted"
                    >
                        <option value="">{row.enabled ? 'Choose 1–3' : '—'}</option>
                        {priorityOptions.map((p) => (
                            <option key={p} value={p}>
                                {p === 1 ? '1 · Recommend first' : p}
                            </option>
                        ))}
                    </select>
                </div>

                {row.method === 'ccp' ? (
                    <div className="grid grid-cols-2 gap-2">
                        <TextField
                            label="Clé"
                            disabled={!canManage || !row.enabled}
                            value={row.ccp_cle}
                            onChange={(e) => onChange({ ccp_cle: e.target.value })}
                            placeholder="xx"
                        />
                        <TextField
                            label="Account number"
                            disabled={!canManage || !row.enabled}
                            value={row.ccp_number}
                            onChange={(e) => onChange({ ccp_number: e.target.value })}
                            placeholder="CCP number"
                        />
                    </div>
                ) : (
                    <TextField
                        label="Phone"
                        disabled={!canManage || !row.enabled}
                        value={row.phone}
                        onChange={(e) => onChange({ phone: e.target.value })}
                        placeholder="05XXXXXXXX"
                    />
                )}
            </div>
        </article>
    );
}
