import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../api';
import { queryKeys } from '../../query';
import { FormCard, TextField } from '../form';

export default function McpTokensCard({ setError }) {
    const queryClient = useQueryClient();
    const [name, setName] = useState('');
    const [created, setCreated] = useState(null);
    const [busy, setBusy] = useState(false);
    const query = useQuery({
        queryKey: queryKeys.mcpTokens,
        queryFn: async () => (await api.get('/mcp-tokens')).data,
    });

    const create = async () => {
        if (!name.trim()) return;
        setBusy(true);
        try {
            const { data } = await api.post('/mcp-tokens', { name: name.trim() });
            setCreated(data);
            setName('');
            queryClient.invalidateQueries({ queryKey: queryKeys.mcpTokens });
        } catch (err) {
            setError(err.response?.data?.message || 'Could not create the token');
        } finally {
            setBusy(false);
        }
    };

    const revoke = async (id) => {
        setBusy(true);
        try {
            await api.delete(`/mcp-tokens/${id}`);
            queryClient.invalidateQueries({ queryKey: queryKeys.mcpTokens });
        } catch (err) {
            setError(err.response?.data?.message || 'Could not revoke the token');
        } finally {
            setBusy(false);
        }
    };

    const tokens = query.data?.tokens || [];

    return (
        <FormCard
            title="API access"
            hint="Issue a token so an external AI app can read your catalog, delivery zones, orders and campaigns. Tokens are shown once."
        >
            <p className="break-all rounded-lg bg-bubble px-3 py-2 font-mono text-[12px] text-ink">
                Endpoint: {query.data?.endpoint || '/api/mcp'}
            </p>
            {created && (
                <div className="rounded-lg border border-teal/40 bg-teal/5 p-3">
                    <p className="text-[12px] font-medium text-ink">Copy this token now. It will not be shown again.</p>
                    <p className="mt-1 break-all font-mono text-[12px] text-ink">{created.plain_token}</p>
                </div>
            )}
            <div className="flex items-end gap-2">
                <div className="flex-1">
                    <TextField label="Token name" placeholder="Integration token" value={name} onChange={(e) => setName(e.target.value)} />
                </div>
                <button
                    type="button"
                    onClick={create}
                    disabled={busy || !name.trim()}
                    className="h-10 rounded-lg bg-ink px-3.5 text-[13px] font-semibold text-white disabled:opacity-50"
                >
                    Create token
                </button>
            </div>
            {tokens.length > 0 && (
                <ul className="divide-y divide-line">
                    {tokens.map((t) => (
                        <li key={t.id} className="flex items-center justify-between gap-3 py-2">
                            <div className="min-w-0">
                                <p className="truncate text-[13px] font-medium text-ink">{t.name}</p>
                                <p className="text-[12px] text-muted">
                                    {t.prefix}… · {t.last_used_at ? `last used ${new Date(t.last_used_at).toLocaleString()}` : 'never used'}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => revoke(t.id)}
                                disabled={busy}
                                className="h-8 rounded-lg border border-line px-3 text-[12px] font-medium text-ink hover:bg-bubble disabled:opacity-50"
                            >
                                Revoke
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </FormCard>
    );
}
