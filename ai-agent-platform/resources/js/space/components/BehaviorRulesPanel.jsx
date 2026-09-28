import { useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Ban, Check, CircleCheck, Pencil, Plus, Trash2, X } from 'lucide-react';
import { api, apiErrorMessage } from '../api';
import { queryKeys } from '../query';
import { useSpace } from '../context';
import { ListSkeleton } from './inboxSkeletons';

/**
 * Full-panel Should-do / Must-not rules (Products → Delivery style).
 * polarity: 'should' | 'must_not'
 */
export default function BehaviorRulesPanel({ polarity = 'should' }) {
    const { can, setError } = useSpace();
    const queryClient = useQueryClient();
    const canManage = can('agents.manage');
    const isShould = polarity === 'should';

    const [creating, setCreating] = useState(false);
    const [draftBody, setDraftBody] = useState('');
    const [editingId, setEditingId] = useState(null);
    const [editBody, setEditBody] = useState('');
    const [busy, setBusy] = useState(false);

    const rulesQuery = useQuery({
        queryKey: queryKeys.agentBehaviorRules,
        queryFn: async () => {
            const { data } = await api.get('/agent/behavior-rules');
            return data.rules || [];
        },
    });

    const rules = useMemo(
        () => (rulesQuery.data || []).filter((r) => r.polarity === polarity),
        [rulesQuery.data, polarity],
    );

    const invalidate = () => queryClient.invalidateQueries({ queryKey: queryKeys.agentBehaviorRules });

    const title = isShould ? 'Should do' : 'Must not';
    const subtitle = isShould
        ? 'What the AI must always do for customers and the shop owner. Applied on every reply.'
        : 'What the AI must never do. Higher priority than other instructions — cannot be bypassed.';
    const Icon = isShould ? CircleCheck : Ban;
    const accent = isShould
        ? {
            header: 'text-emerald-700',
            card: 'border-emerald-200/90 bg-emerald-50/70',
            bar: 'bg-emerald-500',
            empty: 'text-emerald-600',
            btn: 'bg-emerald-600 hover:bg-emerald-700',
        }
        : {
            header: 'text-rose-700',
            card: 'border-rose-200/90 bg-rose-50/70',
            bar: 'bg-rose-500',
            empty: 'text-rose-600',
            btn: 'bg-rose-600 hover:bg-rose-700',
        };

    const startCreate = () => {
        setEditingId(null);
        setCreating(true);
        setDraftBody('');
    };

    const cancelCreate = () => {
        setCreating(false);
        setDraftBody('');
    };

    const saveCreate = async () => {
        const body = draftBody.trim();
        if (!body || busy) return;
        setBusy(true);
        try {
            await api.post('/agent/behavior-rules', { polarity, body });
            cancelCreate();
            await invalidate();
        } catch (e) {
            setError(apiErrorMessage(e, 'Could not add rule.'));
        } finally {
            setBusy(false);
        }
    };

    const startEdit = (rule) => {
        setCreating(false);
        setEditingId(rule.id);
        setEditBody(rule.body || '');
    };

    const cancelEdit = () => {
        setEditingId(null);
        setEditBody('');
    };

    const saveEdit = async (id) => {
        const body = editBody.trim();
        if (!body || busy) return;
        setBusy(true);
        try {
            await api.put(`/agent/behavior-rules/${id}`, { body });
            cancelEdit();
            await invalidate();
        } catch (e) {
            setError(apiErrorMessage(e, 'Could not update rule.'));
        } finally {
            setBusy(false);
        }
    };

    const remove = async (id) => {
        if (busy) return;
        setBusy(true);
        try {
            await api.delete(`/agent/behavior-rules/${id}`);
            await invalidate();
        } catch (e) {
            setError(apiErrorMessage(e, 'Could not delete rule.'));
        } finally {
            setBusy(false);
        }
    };

    if (rulesQuery.isLoading && !rulesQuery.data) {
        return (
            <div className="flex flex-1 flex-col px-4 py-6 sm:px-6">
                <ListSkeleton rows={4} className="max-w-lg" />
            </div>
        );
    }

    return (
        <div className="flex h-full min-h-0 flex-col overflow-hidden bg-cream">
            <header className="border-b border-line bg-white/70 px-4 py-4 sm:px-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-coral">Wasl</p>
                        <h1 className={`mt-1 text-2xl font-extrabold tracking-tight ${accent.header}`}>{title}</h1>
                        <p className="mt-1 max-w-xl text-sm font-medium text-ink/70">{subtitle}</p>
                    </div>
                    {canManage && (
                        <button
                            type="button"
                            disabled={busy}
                            onClick={startCreate}
                            className={`inline-flex h-9 items-center gap-1.5 rounded-lg px-3.5 text-sm font-semibold text-white disabled:opacity-50 ${accent.btn}`}
                        >
                            <Plus size={16} />
                            Add rule
                        </button>
                    )}
                </div>
            </header>

            <div className="min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:px-6">
                {creating && canManage && (
                    <div className={`relative mb-4 overflow-hidden rounded-2xl border px-4 py-3 ${accent.card}`}>
                        <span className={`absolute inset-y-0 left-0 w-1 ${accent.bar}`} aria-hidden />
                        <textarea
                            value={draftBody}
                            onChange={(e) => setDraftBody(e.target.value)}
                            rows={3}
                            placeholder={isShould ? 'e.g. Always greet in Darija…' : 'e.g. Never invent discounts…'}
                            className="w-full resize-none rounded-xl border border-line/80 bg-white px-3 py-2 text-sm text-ink outline-none focus:border-ink/30"
                            disabled={busy}
                            autoFocus
                        />
                        <div className="mt-2 flex justify-end gap-2">
                            <button type="button" disabled={busy} onClick={cancelCreate} className="inline-flex h-8 items-center gap-1 rounded-lg px-2.5 text-sm text-muted hover:bg-white/80">
                                <X size={14} /> Cancel
                            </button>
                            <button type="button" disabled={busy || !draftBody.trim()} onClick={saveCreate} className="inline-flex h-8 items-center gap-1 rounded-lg bg-ink px-2.5 text-sm font-semibold text-white disabled:opacity-40">
                                <Check size={14} /> Save
                            </button>
                        </div>
                    </div>
                )}

                {rules.length === 0 && !creating ? (
                    <div className="mx-auto flex max-w-md flex-col items-center rounded-2xl border border-dashed border-line bg-bubble/60 px-6 py-14 text-center">
                        <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white shadow-sm">
                            <Icon size={22} className={accent.empty} />
                        </div>
                        <p className="mt-4 text-sm font-semibold text-ink">No {title.toLowerCase()} rules yet</p>
                        <p className="mt-1 text-[13px] text-muted">These instructions always load into the owner and customer AI agents.</p>
                        {canManage && (
                            <button
                                type="button"
                                onClick={startCreate}
                                className={`mt-4 inline-flex h-9 items-center gap-1.5 rounded-lg px-3.5 text-sm font-semibold text-white ${accent.btn}`}
                            >
                                <Plus size={16} />
                                Add first rule
                            </button>
                        )}
                    </div>
                ) : (
                    <ul className="mx-auto max-w-2xl space-y-2.5">
                        {rules.map((rule) => (
                            <li key={rule.id} className={`relative overflow-hidden rounded-2xl border px-4 py-3 shadow-sm ${accent.card}`}>
                                <span className={`absolute inset-y-0 left-0 w-1 ${accent.bar}`} aria-hidden />
                                {editingId === rule.id ? (
                                    <div className="pl-2">
                                        <textarea
                                            value={editBody}
                                            onChange={(e) => setEditBody(e.target.value)}
                                            rows={3}
                                            className="w-full resize-none rounded-xl border border-line/80 bg-white px-3 py-2 text-sm text-ink outline-none focus:border-ink/30"
                                            disabled={busy}
                                        />
                                        <div className="mt-2 flex justify-end gap-2">
                                            <button type="button" disabled={busy} onClick={cancelEdit} className="inline-flex h-8 items-center gap-1 rounded-lg px-2.5 text-sm text-muted hover:bg-white/80">
                                                <X size={14} /> Cancel
                                            </button>
                                            <button type="button" disabled={busy || !editBody.trim()} onClick={() => saveEdit(rule.id)} className="inline-flex h-8 items-center gap-1 rounded-lg bg-ink px-2.5 text-sm font-semibold text-white disabled:opacity-40">
                                                <Check size={14} /> Save
                                            </button>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="flex items-start gap-3 pl-2">
                                        <p className="min-w-0 flex-1 text-[14px] leading-snug text-ink">{rule.body}</p>
                                        {canManage && (
                                            <div className="flex shrink-0 gap-1">
                                                <button type="button" disabled={busy} onClick={() => startEdit(rule)} className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-white/80 text-muted hover:text-ink" aria-label="Edit">
                                                    <Pencil size={14} />
                                                </button>
                                                <button type="button" disabled={busy} onClick={() => remove(rule.id)} className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-white/80 text-muted hover:text-rose-700" aria-label="Delete">
                                                    <Trash2 size={14} />
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
