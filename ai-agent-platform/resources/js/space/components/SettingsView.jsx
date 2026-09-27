import { useEffect, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../api';
import { useSpace } from '../context';
import { queryKeys } from '../query';
import { SpaceHeader } from './ui';
import { FormCard, SelectField, TextArea, TextField } from './form';
import { connectedAccounts } from '../setup';
import CustomerAiCard from './settings/CustomerAiCard';
import McpTokensCard from './settings/McpTokensCard';
import TelegramAlertsCard from './settings/TelegramAlertsCard';

const schema = z.object({
    name: z.string().trim().min(1, 'Shop name is required.'),
    phone: z.string().default(''),
    email: z.string().default(''),
    wilaya: z.string().default(''),
    currency: z.string().default('DZD'),
    timezone: z.string().default('Africa/Algiers'),
    description: z.string().default(''),
});

const CURRENCIES = [
    { value: 'DZD', label: 'DZD' },
    { value: 'EUR', label: 'EUR' },
    { value: 'USD', label: 'USD' },
];

const TIMEZONES = [
    { value: 'Africa/Algiers', label: 'Africa/Algiers' },
    { value: 'Africa/Tunis', label: 'Africa/Tunis' },
    { value: 'UTC', label: 'UTC' },
];

const AUTO_FLAGS = [
    ['ai_auto_publish_posts', 'Publish without confirm', 'Skip the chat confirm card when publishing or scheduling posts.'],
    ['ai_auto_reply_comments', 'Reply to comments', 'Skip confirm when the agent replies to comments via SocialAPI.'],
    ['ai_auto_send_dms', 'Send DMs', 'Skip confirm when the agent sends direct messages.'],
    ['ai_auto_reply_reviews', 'Reply to reviews', 'Skip confirm when the agent replies to reviews.'],
];

const TABS = [
    ['shop', 'Shop'],
    ['owner', 'Owner assistant'],
    ['customer', 'Customer AI'],
    ['integrations', 'Integrations'],
];

export default function SettingsView() {
    const { me, can, refreshMe, setError, setView, socialAccounts } = useSpace();
    const [tab, setTab] = useState('shop');
    const [saved, setSaved] = useState(false);
    const [wilayas, setWilayas] = useState([]);
    const { register, handleSubmit, reset, control, formState: { errors, isSubmitting } } = useForm({
        defaultValues: {
            name: '',
            phone: '',
            email: '',
            wilaya: '',
            currency: 'DZD',
            timezone: 'Africa/Algiers',
            description: '',
        },
        resolver: zodResolver(schema),
    });
    const linked = connectedAccounts(socialAccounts);

    useEffect(() => {
        if (!me?.business) return;
        reset({
            name: me.business.name || '',
            phone: me.business.phone || '',
            email: me.business.email || '',
            wilaya: me.business.wilaya || '',
            currency: me.business.currency || 'DZD',
            timezone: me.business.timezone || 'Africa/Algiers',
            description: me.business.description || '',
        });
    }, [me, reset]);

    useEffect(() => {
        api.get('/wilayas')
            .then(({ data }) => setWilayas(data.wilayas || []))
            .catch(() => setWilayas([]));
    }, []);

    const wilayaOptions = [
        { value: '', label: 'Select wilaya' },
        ...wilayas.map((row) => ({ value: row.name_fr, label: row.name_fr })),
    ];

    return (
        <div className="flex h-full min-h-0 flex-col bg-white">
            <div className="min-h-0 flex-1 overflow-y-auto">
                <SpaceHeader title="Settings" subtitle="Shop details, your owner assistant, and the customer AI that talks to clients." />
                <div className="px-4 pt-3 sm:px-6">
                    <div role="tablist" className="inline-flex flex-wrap gap-1 rounded-xl bg-bubble p-1">
                        {TABS.filter(([key]) => key === 'shop' || can('settings.manage') || (key === 'owner' && can('agents.view'))).map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                role="tab"
                                aria-selected={tab === key}
                                onClick={() => setTab(key)}
                                className={`h-8 rounded-lg px-3 text-[13px] font-medium transition ${
                                    tab === key ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>
                <div className="grid gap-6 px-4 py-4 sm:px-6 lg:grid-cols-[minmax(0,1fr)_16rem]">
                    <div className="space-y-6">
                        {tab === 'owner' && (can('agents.view') || can('agents.manage')) && (
                            <AgentAutoSettingsCard canManage={can('agents.manage')} setError={setError} />
                        )}
                        {tab === 'customer' && can('settings.manage') && (
                            <CustomerAiCard canManage={can('settings.manage')} setError={setError} />
                        )}
                        {tab === 'integrations' && can('settings.manage') && (
                            <>
                                <TelegramAlertsCard setError={setError} />
                                <McpTokensCard setError={setError} />
                            </>
                        )}
                        {tab === 'shop' && (
                        <form
                            onSubmit={handleSubmit(async (values) => {
                                try {
                                    await api.put('/business', values);
                                    await refreshMe();
                                    setSaved(true);
                                } catch (err) {
                                    setError(err.response?.data?.message || 'Could not save settings');
                                }
                            })}
                        >
                            <FormCard title="Shop" hint="Shown on the workspace and used for delivery defaults.">
                                <TextField label="Shop name" error={errors.name?.message} disabled={!can('settings.manage')} {...register('name')} />
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <TextField label="Phone" disabled={!can('settings.manage')} {...register('phone')} />
                                    <TextField label="Email" type="email" disabled={!can('settings.manage')} {...register('email')} />
                                </div>
                                {wilayas.length > 0 ? (
                                    <Controller
                                        control={control}
                                        name="wilaya"
                                        render={({ field }) => (
                                            <SelectField
                                                label="Wilaya"
                                                value={field.value}
                                                onChange={field.onChange}
                                                disabled={!can('settings.manage')}
                                                options={wilayaOptions}
                                            />
                                        )}
                                    />
                                ) : (
                                    <TextField label="Wilaya" disabled={!can('settings.manage')} {...register('wilaya')} />
                                )}
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Controller
                                        control={control}
                                        name="currency"
                                        render={({ field }) => (
                                            <SelectField
                                                label="Currency"
                                                value={field.value}
                                                onChange={field.onChange}
                                                disabled={!can('settings.manage')}
                                                options={CURRENCIES}
                                            />
                                        )}
                                    />
                                    <Controller
                                        control={control}
                                        name="timezone"
                                        render={({ field }) => (
                                            <SelectField
                                                label="Timezone"
                                                value={field.value}
                                                onChange={field.onChange}
                                                disabled={!can('settings.manage')}
                                                options={TIMEZONES}
                                            />
                                        )}
                                    />
                                </div>
                                <TextArea label="Description" rows={4} disabled={!can('settings.manage')} {...register('description')} />
                                {can('settings.manage') && (
                                    <button type="submit" disabled={isSubmitting} className="h-9 rounded-lg bg-coral px-3.5 text-[13px] font-semibold text-white disabled:opacity-50">
                                        Save
                                    </button>
                                )}
                                {saved && <p className="text-[13px] font-medium text-teal-dark">Saved.</p>}
                            </FormCard>
                        </form>
                        )}
                    </div>

                    <div className="space-y-3">
                        <FormCard title="Channels" hint={`${linked.length} connected.`}>
                            <button
                                type="button"
                                onClick={() => setView('channels')}
                                className="h-9 rounded-lg bg-ink px-3.5 text-[13px] font-semibold text-white"
                            >
                                Open channels
                            </button>
                        </FormCard>
                        {can('team.manage') && (
                            <button
                                type="button"
                                onClick={() => setView('team')}
                                className="flex h-10 w-full items-center justify-between rounded-xl border border-line px-3 text-[13px] font-medium text-ink hover:bg-bubble"
                            >
                                Team
                                <span className="text-[12px] text-muted">Go</span>
                            </button>
                        )}
                        {can('agents.view') && (
                            <button
                                type="button"
                                onClick={() => setView('agents')}
                                className="flex h-10 w-full items-center justify-between rounded-xl border border-line px-3 text-[13px] font-medium text-ink hover:bg-bubble"
                            >
                                AI Agents
                                <span className="text-[12px] text-muted">Go</span>
                            </button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

function AgentAutoSettingsCard({ canManage, setError }) {
    const queryClient = useQueryClient();
    const [savingKey, setSavingKey] = useState(null);
    const agentQuery = useQuery({
        queryKey: queryKeys.agent,
        queryFn: async () => {
            const { data } = await api.get('/agent');
            return data;
        },
    });

    const settings = agentQuery.data?.settings || {};

    const toggle = async (key, checked) => {
        if (!canManage) return;
        setSavingKey(key);
        try {
            const { data } = await api.put('/agent', { [key]: checked });
            queryClient.setQueryData(queryKeys.agent, (old) => (
                old ? { ...old, settings: data.settings || { ...old.settings, [key]: checked } } : old
            ));
        } catch (err) {
            setError(err.response?.data?.message || 'Could not update AI automation settings');
        } finally {
            setSavingKey(null);
        }
    };

    return (
        <FormCard
            title="Owner assistant automation"
            hint="Your private assistant in the Agents chat. It uses the model you pick in the Agents header. When a flag is off, that SocialAPI action needs the chat confirm card; when on, it runs without confirm. The assistant can also change these when you ask in chat."
        >
            {agentQuery.isLoading ? (
                <p className="text-[13px] text-muted">Loading…</p>
            ) : (
                <ul className="divide-y divide-line">
                    {AUTO_FLAGS.map(([key, label, help]) => (
                        <li key={key} className="flex items-start justify-between gap-4 py-3 first:pt-0 last:pb-0">
                            <div className="min-w-0">
                                <p className="text-[13px] font-medium text-ink">{label}</p>
                                <p className="mt-0.5 text-[12px] text-muted">{help}</p>
                            </div>
                            <label className="inline-flex shrink-0 items-center gap-2 pt-0.5">
                                <input
                                    type="checkbox"
                                    className="h-4 w-4"
                                    checked={Boolean(settings[key])}
                                    disabled={!canManage || savingKey === key}
                                    onChange={(e) => toggle(key, e.target.checked)}
                                />
                                <span className="text-[12px] text-muted">
                                    {settings[key] ? 'On' : 'Off'}
                                </span>
                            </label>
                        </li>
                    ))}
                </ul>
            )}
            {!canManage && (
                <p className="text-[12px] text-muted">You can view these settings but need Agents manage permission to change them.</p>
            )}
        </FormCard>
    );
}
