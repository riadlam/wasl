import { useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Pencil, Plus, Trash2, X } from 'lucide-react';
import { api, apiErrorMessage } from '../api';
import { queryKeys } from '../query';
import { useSpace } from '../context';

/**
 * Should-do (green) / Must-not (red) cards under AI Agents → Chats.
 * Rules always inject into owner + customer agent system prompts.
 */
export default function BehaviorRulesPanel({ canManage }) {
    const { setError } = useSpace();
    const queryClient = useQueryClient();
    const [draftPolarity, setDraftPolarity] = useState(null);
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

    const rules = rulesQuery.data || [];
    const should = useMemo(() => rules.filter((r) => r.polarity === 'should'), [rules]);
    const mustNot = useMemo(() => rules.filter((r) => r.polarity === 'must_not'), [rules]);

    const invalidate = () => queryClient.invalidateQueries({ queryKey: queryKeys.agentBehaviorRules });

    const startAdd = (polarity) => {
        setEditingId(null);
        setDraftPolarity(polarity);
        setDraftBody('');
    };

    const cancelDraft = () => {
        setDraftPolarity(null);
        setDraftBody('');
    };

    const saveDraft = async () => {
        const body = draftBody.trim();
        if (!body || !draftPolarity || busy) return;
        setBusy(true);
        try {
            await api.post('/agent/behavior-rules', { polarity: draftPolarity, body });
            cancelDraft();
            await invalidate();
        } catch (e) {
            setError(apiErrorMessage(e, 'Could not add rule.'));
        } finally {
            setBusy(false);
        }
    };

    const startEdit = (rule) => {
        setDraftPolarity(null);
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

    return (
        <div className="border-t border-line/80 px-2 pb-3 pt-2">
            <p className="px-1 text-[11px] font-semibold uppercase tracking-[0.06em] text-muted">AI rules</p>
            <p className="mb-2 px-1 text-[11px] leading-4 text-muted">Always applied to owner &amp; customer bots.</p>
            <div className="space-y-3">
                <RuleColumn
                    title="Should do"
                    tone="green"
                    rules={should}
                    canManage={canManage}
                    busy={busy}
                    loading={rulesQuery.isLoading}
                    draftOpen={draftPolarity === 'should'}
                    draftBody={draftBody}
                    editingId={editingId}
                    editBody={editBody}
                    onDraftBody={setDraftBody}
                    onEditBody={setEditBody}
                    onStartAdd={() => startAdd('should')}
                    onCancelDraft={cancelDraft}
                    onSaveDraft={saveDraft}
                    onStartEdit={startEdit}
                    onCancelEdit={cancelEdit}
                    onSaveEdit={saveEdit}
                    onDelete={remove}
                />
                <RuleColumn
                    title="Must not"
                    tone="red"
                    rules={mustNot}
                    canManage={canManage}
                    busy={busy}
                    loading={rulesQuery.isLoading}
                    draftOpen={draftPolarity === 'must_not'}
                    draftBody={draftBody}
                    editingId={editingId}
                    editBody={editBody}
                    onDraftBody={setDraftBody}
                    onEditBody={setEditBody}
                    onStartAdd={() => startAdd('must_not')}
                    onCancelDraft={cancelDraft}
                    onSaveDraft={saveDraft}
                    onStartEdit={startEdit}
                    onCancelEdit={cancelEdit}
                    onSaveEdit={saveEdit}
                    onDelete={remove}
                />
            </div>
        </div>
    );
}

function RuleColumn({
    title,
    tone,
    rules,
    canManage,
    busy,
    loading,
    draftOpen,
    draftBody,
    editingId,
    editBody,
    onDraftBody,
    onEditBody,
    onStartAdd,
    onCancelDraft,
    onSaveDraft,
    onStartEdit,
    onCancelEdit,
    onSaveEdit,
    onDelete,
}) {
    const isGreen = tone === 'green';
    const headerCls = isGreen ? 'text-emerald-700' : 'text-rose-700';
    const cardCls = isGreen
        ? 'border-emerald-200/90 bg-emerald-50/80'
        : 'border-rose-200/90 bg-rose-50/80';
    const accentCls = isGreen ? 'bg-emerald-500' : 'bg-rose-500';

    return (
        <div>
            <div className="mb-1.5 flex items-center justify-between gap-1 px-1">
                <p className={`text-[11px] font-semibold ${headerCls}`}>{title}</p>
                {canManage && (
                    <button
                        type="button"
                        disabled={busy}
                        onClick={onStartAdd}
                        className="inline-flex h-6 w-6 items-center justify-center rounded-md border border-line/80 bg-white text-ink transition hover:border-ink/20 disabled:opacity-50"
                        title={`Add ${title.toLowerCase()}`}
                        aria-label={`Add ${title.toLowerCase()}`}
                    >
                        <Plus size={13} strokeWidth={2.25} />
                    </button>
                )}
            </div>

            {loading ? (
                <p className="px-1 text-[11px] text-muted">Loading…</p>
            ) : (
                <ul className="space-y-1.5">
                    {rules.map((rule) => (
                        <li key={rule.id} className={`relative overflow-hidden rounded-lg border px-2 py-1.5 ${cardCls}`}>
                            <span className={`absolute inset-y-0 left-0 w-0.5 ${accentCls}`} aria-hidden />
                            {editingId === rule.id ? (
                                <div className="pl-1.5">
                                    <textarea
                                        value={editBody}
                                        onChange={(e) => onEditBody(e.target.value)}
                                        rows={2}
                                        className="w-full resize-none rounded-md border border-line/80 bg-white px-2 py-1 text-[12px] text-ink outline-none focus:border-ink/30"
                                        disabled={busy}
                                    />
                                    <div className="mt-1 flex justify-end gap-1">
                                        <button type="button" disabled={busy} onClick={onCancelEdit} className="rounded p-1 text-muted hover:bg-white/80" aria-label="Cancel">
                                            <X size={13} />
                                        </button>
                                        <button type="button" disabled={busy || !editBody.trim()} onClick={() => onSaveEdit(rule.id)} className="rounded p-1 text-ink hover:bg-white/80 disabled:opacity-40" aria-label="Save">
                                            <Check size={13} />
                                        </button>
                                    </div>
                                </div>
                            ) : (
                                <div className="flex items-start gap-1 pl-1.5">
                                    <p className="min-w-0 flex-1 text-[12px] leading-snug text-ink">{rule.body}</p>
                                    {canManage && (
                                        <div className="flex shrink-0 gap-0.5">
                                            <button type="button" disabled={busy} onClick={() => onStartEdit(rule)} className="rounded p-0.5 text-muted hover:bg-white/80 hover:text-ink" aria-label="Edit">
                                                <Pencil size={12} />
                                            </button>
                                            <button type="button" disabled={busy} onClick={() => onDelete(rule.id)} className="rounded p-0.5 text-muted hover:bg-white/80 hover:text-rose-700" aria-label="Delete">
                                                <Trash2 size={12} />
                                            </button>
                                        </div>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                    {rules.length === 0 && !draftOpen && (
                        <li className="px-1 text-[11px] text-muted">No rules yet.</li>
                    )}
                </ul>
            )}

            {draftOpen && canManage && (
                <div className={`mt-1.5 overflow-hidden rounded-lg border px-2 py-1.5 ${cardCls}`}>
                    <span className={`absolute inset-y-0 left-0 w-0.5 ${accentCls}`} aria-hidden />
                    <textarea
                        value={draftBody}
                        onChange={(e) => onDraftBody(e.target.value)}
                        rows={2}
                        placeholder={isGreen ? 'What the AI should do…' : 'What the AI must not do…'}
                        className="w-full resize-none rounded-md border border-line/80 bg-white px-2 py-1 text-[12px] text-ink outline-none focus:border-ink/30"
                        disabled={busy}
                        autoFocus
                    />
                    <div className="mt-1 flex justify-end gap-1">
                        <button type="button" disabled={busy} onClick={onCancelDraft} className="rounded p-1 text-muted hover:bg-white/80" aria-label="Cancel">
                            <X size={13} />
                        </button>
                        <button type="button" disabled={busy || !draftBody.trim()} onClick={onSaveDraft} className="rounded p-1 text-ink hover:bg-white/80 disabled:opacity-40" aria-label="Save">
                            <Check size={13} />
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
