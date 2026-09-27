import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../api';
import { queryKeys } from '../../query';
import { FormCard, NumberField, SelectField, SwitchField, TextArea, TextField } from '../form';

const LANGUAGES = [
    { value: '', label: 'Same as shop' },
    { value: 'Darija', label: 'Darija' },
    { value: 'French', label: 'French' },
];

const LENGTHS = [
    { value: 'short', label: 'Short' },
    { value: 'medium', label: 'Medium' },
    { value: 'long', label: 'Long' },
];

const EMOJIS = [
    { value: 'none', label: 'No emojis' },
    { value: 'light', label: 'Light (at most one)' },
    { value: 'match', label: 'Only if the client uses them' },
];

const REPLY_FLAGS = [
    ['reply_dms', 'Reply to DMs', 'The customer AI answers direct messages on connected channels.'],
    ['reply_comments', 'Reply to comments', 'Answers comments on posts that have an active comment workflow.'],
    ['allow_order_creation', 'Capture orders', 'Lets the AI create cash-on-delivery orders once the client confirms and gives phone and wilaya.'],
];

export default function CustomerAiCard({ canManage, setError }) {
    const queryClient = useQueryClient();
    const [draft, setDraft] = useState(null);
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const query = useQuery({
        queryKey: queryKeys.customerAi,
        queryFn: async () => (await api.get('/customer-ai-settings')).data,
    });

    useEffect(() => {
        if (query.data?.settings) {
            const s = query.data.settings;
            setDraft({ ...s, handoff_text: (s.handoff_keywords || []).join(', ') });
        }
    }, [query.data]);

    if (query.isLoading || !draft) {
        return (
            <FormCard title="Customer AI" hint="Replies to your clients in comments and DMs.">
                <p className="text-[13px] text-muted">Loading…</p>
            </FormCard>
        );
    }

    const models = [
        { value: '', label: 'Default (fast replies)' },
        ...(query.data?.llm_models || []).map((m) => ({ value: m.id, label: m.label })),
    ];
    const set = (key) => (value) => {
        setSaved(false);
        setDraft((old) => ({ ...old, [key]: value }));
    };

    const persistReplyFlag = async (key, value) => {
        if (!canManage) return;
        setDraft((old) => ({ ...old, [key]: value }));
        setSaved(false);
        try {
            const { data } = await api.put('/customer-ai-settings', { [key]: Boolean(value) });
            queryClient.setQueryData(queryKeys.customerAi, data);
            queryClient.invalidateQueries({ queryKey: queryKeys.agent });
            setSaved(true);
        } catch (err) {
            setError(err.response?.data?.message || 'Could not save customer AI settings');
            // revert on failure
            query.refetch();
        }
    };

    const save = async () => {
        setSaving(true);
        try {
            const payload = {
                llm_model: draft.llm_model || null,
                language: draft.language || null,
                tone: draft.tone || null,
                persona: draft.persona || null,
                response_length: draft.response_length,
                emoji_policy: draft.emoji_policy,
                max_reply_chars: Number(draft.max_reply_chars) || 600,
                handoff_keywords: String(draft.handoff_text || '')
                    .split(',')
                    .map((k) => k.trim())
                    .filter(Boolean),
                reply_dms: Boolean(draft.reply_dms),
                reply_comments: Boolean(draft.reply_comments),
                allow_order_creation: Boolean(draft.allow_order_creation),
            };
            const { data } = await api.put('/customer-ai-settings', payload);
            queryClient.setQueryData(queryKeys.customerAi, data);
            queryClient.invalidateQueries({ queryKey: queryKeys.agent });
            setSaved(true);
        } catch (err) {
            setError(err.response?.data?.message || 'Could not save customer AI settings');
        } finally {
            setSaving(false);
        }
    };

    const inheritsLanguage = query.data?.inherits?.language;

    return (
        <FormCard
            title="Customer AI"
            hint="The AI that answers your clients in comments, replies and DMs. It only reads your catalog, delivery zones and the client's own orders. It never sees the owner chat."
        >
            <div className="divide-y divide-line">
                {REPLY_FLAGS.map(([key, label, help]) => (
                    <SwitchField
                        key={key}
                        label={label}
                        description={help}
                        checked={Boolean(draft[key])}
                        disabled={!canManage}
                        onChange={(value) => {
                            if (key === 'reply_dms' || key === 'reply_comments' || key === 'allow_order_creation') {
                                persistReplyFlag(key, value);
                            } else {
                                set(key)(value);
                            }
                        }}
                    />
                ))}
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <SelectField label="Model" value={draft.llm_model || ''} onChange={set('llm_model')} options={models} disabled={!canManage} />
                <SelectField
                    label="Reply language"
                    value={draft.language || ''}
                    onChange={set('language')}
                    options={LANGUAGES}
                    disabled={!canManage}
                    hint={!draft.language && inheritsLanguage ? `Currently ${inheritsLanguage}` : undefined}
                />
                <SelectField label="Reply length" value={draft.response_length} onChange={set('response_length')} options={LENGTHS} disabled={!canManage} />
                <SelectField label="Emojis" value={draft.emoji_policy} onChange={set('emoji_policy')} options={EMOJIS} disabled={!canManage} />
                <TextField
                    label="Tone"
                    placeholder={query.data?.inherits?.tone || 'friendly'}
                    value={draft.tone || ''}
                    onChange={(e) => set('tone')(e.target.value)}
                    disabled={!canManage}
                />
                <NumberField
                    label="Max reply characters"
                    value={String(draft.max_reply_chars ?? 600)}
                    onChange={(e) => set('max_reply_chars')(e.target.value.replace(/[^\d]/g, ''))}
                    disabled={!canManage}
                />
            </div>
            <TextArea
                label="Persona"
                rows={3}
                placeholder="Example: You are Amina from the shop team. Warm, direct, never pushy."
                value={draft.persona || ''}
                onChange={(e) => set('persona')(e.target.value)}
                disabled={!canManage}
            />
            <TextField
                label="Hand off to a human when the client writes"
                placeholder="remboursement, arnaque, manager"
                value={draft.handoff_text || ''}
                onChange={(e) => set('handoff_text')(e.target.value)}
                disabled={!canManage}
            />
            {canManage && (
                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={save}
                        disabled={saving}
                        className="h-9 rounded-lg bg-coral px-3.5 text-[13px] font-semibold text-white disabled:opacity-50"
                    >
                        {saving ? 'Saving…' : 'Save customer AI'}
                    </button>
                    {saved && <p className="text-[13px] font-medium text-teal-dark">Saved.</p>}
                </div>
            )}
        </FormCard>
    );
}
