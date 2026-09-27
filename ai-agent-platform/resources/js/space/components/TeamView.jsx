import { useEffect, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { motion } from 'framer-motion';
import {
    Bot,
    Boxes,
    Inbox,
    Plus,
    Share2,
    ShoppingBag,
    UserRound,
    Wallet,
} from 'lucide-react';
import { api } from '../api';
import { useSpace } from '../context';
import { queryKeys } from '../query';
import PageFrame from './PageFrame';
import { CheckboxField, TextField } from './form';

const schema = z.object({
    name: z.string().trim().min(1, 'Name is required.'),
    email: z.string().trim().email('Enter a valid email.'),
    password: z.string().min(8, 'Password must be at least 8 characters.'),
    permissions: z.array(z.string()).default([]),
    channel_ids: z.array(z.number()).min(1, 'Pick at least one channel.'),
});

export default function TeamView() {
    const { setError } = useSpace();
    const queryClient = useQueryClient();
    const [mode, setMode] = useState('roster'); // roster | add | edit
    const [selectedId, setSelectedId] = useState(null);
    const [draftPermissions, setDraftPermissions] = useState([]);
    const [draftChannels, setDraftChannels] = useState([]);
    const [saving, setSaving] = useState(false);

    const {
        register,
        handleSubmit,
        reset,
        watch,
        setValue,
        formState: { errors, isSubmitting },
    } = useForm({
        defaultValues: { name: '', email: '', password: '', permissions: [], channel_ids: [] },
        resolver: zodResolver(schema),
    });
    const createPermissions = watch('permissions') || [];
    const createChannels = watch('channel_ids') || [];

    const teamQuery = useQuery({
        queryKey: queryKeys.team,
        queryFn: async () => {
            const { data } = await api.get('/team');
            return {
                members: data.members || [],
                catalog: data.catalog || {},
                channels: data.channels || [],
                presets: data.presets || [],
            };
        },
    });

    const members = teamQuery.data?.members || [];
    const catalog = teamQuery.data?.catalog || {};
    const channels = teamQuery.data?.channels || [];
    const presets = teamQuery.data?.presets || [];

    const owner = useMemo(() => members.find((m) => m.role === 'owner'), [members]);
    const staff = useMemo(() => members.filter((m) => m.role !== 'owner'), [members]);
    const selected = useMemo(
        () => members.find((m) => m.id === selectedId) || null,
        [members, selectedId],
    );

    useEffect(() => {
        if (teamQuery.error) {
            setError(teamQuery.error.response?.data?.message || 'Could not load team');
        }
    }, [teamQuery.error, setError]);

    useEffect(() => {
        if (mode === 'edit' && selected && selected.role !== 'owner') {
            setDraftPermissions(selected.permissions || []);
            setDraftChannels(selected.channel_ids || []);
        }
    }, [mode, selected]);

    const reload = () => queryClient.invalidateQueries({ queryKey: queryKeys.team });

    const openAdd = () => {
        reset({
            name: '',
            email: '',
            password: '',
            permissions: [],
            channel_ids: channels.length === 1 ? [channels[0].id] : [],
        });
        setMode('add');
        setSelectedId(null);
    };

    const openEdit = (member) => {
        setSelectedId(member.id);
        setMode(member.role === 'owner' ? 'roster' : 'edit');
        if (member.role !== 'owner') {
            setDraftPermissions(member.permissions || []);
            setDraftChannels(member.channel_ids || []);
        }
    };

    const applyPreset = (presetKeys, current, setFn) => {
        const next = new Set(current);
        presetKeys.forEach((key) => next.add(key));
        setFn([...next]);
    };

    const toggleIn = (list, value, setFn) => {
        setFn(list.includes(value) ? list.filter((v) => v !== value) : [...list, value]);
    };

    const saveMember = async () => {
        if (!selected || selected.role === 'owner') return;
        if (draftChannels.length < 1) {
            setError('Assign at least one channel.');
            return;
        }
        setSaving(true);
        try {
            await api.put(`/team/${selected.user_id}`, {
                permissions: draftPermissions,
                channel_ids: draftChannels,
                disabled: selected.disabled,
            });
            await reload();
        } catch (err) {
            setError(err.response?.data?.message || err.response?.data?.errors?.channel_ids?.[0] || 'Could not save staff');
        } finally {
            setSaving(false);
        }
    };

    return (
        <PageFrame
            title="Team"
            subtitle="Invite staff to this shop, give them privileges, and limit each person to the channels they handle."
        >
            <div className="grid gap-4 lg:grid-cols-[280px_minmax(0,1fr)]">
                <aside className="rounded-2xl border border-line bg-white p-3 shadow-sm">
                    <div className="mb-3 flex items-center justify-between gap-2 px-1">
                        <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-muted">Roster</p>
                        <button
                            type="button"
                            onClick={openAdd}
                            className="inline-flex items-center gap-1 rounded-lg bg-ink px-2.5 py-1.5 text-xs font-semibold text-white"
                        >
                            <Plus size={14} />
                            Add
                        </button>
                    </div>

                    {owner && (
                        <RosterCard
                            member={owner}
                            channels={channels}
                            active={selectedId === owner.id && mode !== 'add'}
                            onClick={() => openEdit(owner)}
                        />
                    )}

                    <div className="mt-3 space-y-2">
                        {staff.length === 0 ? (
                            <p className="rounded-xl bg-bubble px-3 py-4 text-center text-xs font-medium text-muted">
                                No staff yet. Add someone to cover Facebook or Instagram.
                            </p>
                        ) : (
                            staff.map((member) => (
                                <RosterCard
                                    key={member.id}
                                    member={member}
                                    channels={channels}
                                    active={selectedId === member.id && mode !== 'add'}
                                    onClick={() => openEdit(member)}
                                />
                            ))
                        )}
                    </div>
                </aside>

                <section className="min-h-[420px] rounded-2xl border border-line bg-white p-5 shadow-sm">
                    {mode === 'add' ? (
                        <form
                            className="space-y-5"
                            onSubmit={handleSubmit(async (values) => {
                                try {
                                    const { data } = await api.post('/team', values);
                                    reset({ name: '', email: '', password: '', permissions: [], channel_ids: [] });
                                    await reload();
                                    if (data?.member?.id) {
                                        setSelectedId(data.member.id);
                                        setMode('edit');
                                    } else {
                                        setMode('roster');
                                    }
                                } catch (err) {
                                    setError(
                                        err.response?.data?.message
                                        || err.response?.data?.errors?.email?.[0]
                                        || err.response?.data?.errors?.channel_ids?.[0]
                                        || 'Could not add staff',
                                    );
                                }
                            })}
                        >
                            <Header
                                title="Add staff"
                                subtitle="They log in with this email and share the shop wallet for AI usage."
                            />
                            <WalletNote />
                            <div className="grid gap-3 sm:grid-cols-3">
                                <TextField label="Name" error={errors.name?.message} {...register('name')} />
                                <TextField label="Email" type="email" error={errors.email?.message} {...register('email')} />
                                <TextField label="Password" type="password" error={errors.password?.message} {...register('password')} />
                            </div>

                            <ChannelPicker
                                channels={channels}
                                selected={createChannels}
                                onToggle={(id) => setValue(
                                    'channel_ids',
                                    createChannels.includes(id)
                                        ? createChannels.filter((v) => v !== id)
                                        : [...createChannels, id],
                                    { shouldValidate: true },
                                )}
                                error={errors.channel_ids?.message}
                            />

                            <PermissionEditor
                                catalog={catalog}
                                presets={presets}
                                selected={createPermissions}
                                onToggle={(key) => setValue(
                                    'permissions',
                                    createPermissions.includes(key)
                                        ? createPermissions.filter((k) => k !== key)
                                        : [...createPermissions, key],
                                )}
                                onPreset={(keys) => applyPreset(keys, createPermissions, (next) => setValue('permissions', next))}
                            />

                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="submit"
                                    disabled={isSubmitting || channels.length === 0}
                                    className="h-10 rounded-xl bg-coral px-4 text-sm font-semibold text-white disabled:opacity-50"
                                >
                                    {isSubmitting ? 'Creating…' : 'Create staff'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setMode('roster')}
                                    className="h-10 rounded-xl border border-line px-4 text-sm font-semibold text-muted"
                                >
                                    Cancel
                                </button>
                            </div>
                            {channels.length === 0 && (
                                <p className="text-xs font-medium text-coral-dark">
                                    Connect a Facebook or Instagram page in Channels before inviting staff.
                                </p>
                            )}
                        </form>
                    ) : selected && selected.role === 'owner' ? (
                        <div className="space-y-4">
                            <Header title={selected.name} subtitle={`${selected.email} · Owner`} />
                            <WalletNote owner />
                            <p className="rounded-xl bg-bubble px-4 py-3 text-sm text-muted">
                                Owners have every permission and access to all channels. Staff you invite share this shop wallet for AI.
                            </p>
                            <ChannelChips channels={channels} ids={channels.map((c) => c.id)} />
                        </div>
                    ) : selected && mode === 'edit' ? (
                        <div className="space-y-5">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <Header
                                    title={selected.name}
                                    subtitle={`${selected.email} · Staff${selected.disabled ? ' · Disabled' : ''}`}
                                />
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        className="rounded-lg border border-line px-3 py-1.5 text-xs font-semibold"
                                        onClick={async () => {
                                            await api.put(`/team/${selected.user_id}`, {
                                                permissions: draftPermissions,
                                                channel_ids: draftChannels.length ? draftChannels : selected.channel_ids,
                                                disabled: !selected.disabled,
                                            });
                                            await reload();
                                        }}
                                    >
                                        {selected.disabled ? 'Enable' : 'Disable'}
                                    </button>
                                    <button
                                        type="button"
                                        className="rounded-lg border border-coral/30 px-3 py-1.5 text-xs font-semibold text-coral"
                                        onClick={async () => {
                                            await api.delete(`/team/${selected.user_id}`);
                                            setSelectedId(null);
                                            setMode('roster');
                                            await reload();
                                        }}
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>

                            <WalletNote />

                            <ChannelPicker
                                channels={channels}
                                selected={draftChannels}
                                onToggle={(id) => toggleIn(draftChannels, id, setDraftChannels)}
                            />

                            <PermissionEditor
                                catalog={catalog}
                                presets={presets}
                                selected={draftPermissions}
                                onToggle={(key) => toggleIn(draftPermissions, key, setDraftPermissions)}
                                onPreset={(keys) => applyPreset(keys, draftPermissions, setDraftPermissions)}
                            />

                            <button
                                type="button"
                                disabled={saving}
                                onClick={saveMember}
                                className="h-10 rounded-xl bg-accent px-4 text-sm font-semibold text-white disabled:opacity-50"
                            >
                                {saving ? 'Saving…' : 'Save access'}
                            </button>
                        </div>
                    ) : (
                        <EmptyState onAdd={openAdd} hasChannels={channels.length > 0} />
                    )}
                </section>
            </div>
        </PageFrame>
    );
}

function Header({ title, subtitle }) {
    return (
        <div>
            <h2 className="text-lg font-bold tracking-tight text-ink">{title}</h2>
            <p className="mt-0.5 text-sm text-muted">{subtitle}</p>
        </div>
    );
}

function WalletNote({ owner = false }) {
    return (
        <div className="flex items-start gap-3 rounded-xl border border-line bg-cream/50 px-3 py-3 text-xs text-muted">
            <Wallet size={16} className="mt-0.5 shrink-0 text-ink" />
            <p>
                {owner
                    ? 'Shop wallet lives on the owner account. Staff AI usage is billed here.'
                    : 'This person uses the shop wallet. They cannot top up or open the ledger.'}
            </p>
        </div>
    );
}

function RosterCard({ member, channels, active, onClick }) {
    const channelMap = Object.fromEntries(channels.map((c) => [c.id, c]));
    const ids = member.role === 'owner'
        ? channels.map((c) => c.id)
        : (member.channel_ids || []);

    return (
        <button
            type="button"
            onClick={onClick}
            className={`w-full rounded-xl border px-3 py-3 text-left transition ${
                active ? 'border-coral/40 bg-coral/5 shadow-sm' : 'border-line bg-white hover:bg-bubble'
            }`}
        >
            <div className="flex items-center gap-2">
                <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-ink/5 text-ink">
                    <UserRound size={16} />
                </span>
                <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-semibold text-ink">{member.name}</div>
                    <div className="truncate text-[11px] font-medium text-muted">
                        {member.role}
                        {member.disabled ? ' · disabled' : ''}
                    </div>
                </div>
            </div>
            <div className="mt-2 flex flex-wrap gap-1">
                {member.role === 'owner' ? (
                    <Chip>All channels</Chip>
                ) : ids.length === 0 ? (
                    <Chip>All channels</Chip>
                ) : (
                    ids.slice(0, 3).map((id) => {
                        const ch = channelMap[id];
                        return (
                            <Chip key={id}>
                                {(ch?.platform || 'channel')}
                                {ch?.name ? ` · ${ch.name}` : ''}
                            </Chip>
                        );
                    })
                )}
            </div>
        </button>
    );
}

function Chip({ children }) {
    return (
        <span className="rounded-md bg-bubble px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted">
            {children}
        </span>
    );
}

function ChannelChips({ channels, ids }) {
    const map = Object.fromEntries(channels.map((c) => [c.id, c]));
    return (
        <div className="flex flex-wrap gap-2">
            {ids.map((id) => {
                const ch = map[id];
                if (!ch) return null;
                return (
                    <span key={id} className="rounded-lg border border-line bg-white px-2.5 py-1.5 text-xs font-semibold text-ink">
                        {ch.platform}
                        {ch.name ? ` · ${ch.name}` : ''}
                    </span>
                );
            })}
        </div>
    );
}

function ChannelPicker({ channels, selected, onToggle, error }) {
    return (
        <div>
            <div className="mb-2 flex items-center gap-2">
                <Share2 size={14} className="text-muted" />
                <p className="text-xs font-bold uppercase tracking-[0.14em] text-muted">Channels</p>
            </div>
            {channels.length === 0 ? (
                <p className="rounded-xl border border-dashed border-line px-3 py-4 text-sm text-muted">
                    No connected pages yet.
                </p>
            ) : (
                <div className="grid gap-2 sm:grid-cols-2">
                    {channels.map((channel) => {
                        const on = selected.includes(channel.id);
                        return (
                            <button
                                key={channel.id}
                                type="button"
                                onClick={() => onToggle(channel.id)}
                                className={`rounded-xl border px-3 py-3 text-left transition ${
                                    on ? 'border-coral bg-coral/5' : 'border-line hover:bg-bubble'
                                }`}
                            >
                                <div className="text-sm font-semibold capitalize text-ink">{channel.platform}</div>
                                <div className="mt-0.5 truncate text-xs text-muted">
                                    {channel.name || channel.username || `Channel #${channel.id}`}
                                </div>
                            </button>
                        );
                    })}
                </div>
            )}
            {error && <p className="mt-1 text-xs font-medium text-coral">{error}</p>}
        </div>
    );
}

function PermissionEditor({ catalog, presets, selected, onToggle, onPreset }) {
    const presetIcons = {
        inbox: Inbox,
        catalog: Boxes,
        shop_ops: ShoppingBag,
    };

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <p className="text-xs font-bold uppercase tracking-[0.14em] text-muted">Privileges</p>
                {presets.map((preset) => {
                    const Icon = presetIcons[preset.key] || Bot;
                    return (
                        <button
                            key={preset.key}
                            type="button"
                            onClick={() => onPreset(preset.permissions || [])}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-line bg-white px-2.5 py-1.5 text-[11px] font-semibold text-ink hover:bg-bubble"
                        >
                            <Icon size={12} />
                            {preset.label}
                        </button>
                    );
                })}
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                {Object.entries(catalog).map(([group, items]) => (
                    <fieldset key={group} className="rounded-xl bg-bubble p-3">
                        <legend className="text-xs font-bold uppercase tracking-wide text-muted">{group}</legend>
                        {items.map((item) => (
                            <CheckboxField
                                key={item.value}
                                label={item.label}
                                checked={selected.includes(item.value)}
                                onChange={() => onToggle(item.value)}
                            />
                        ))}
                    </fieldset>
                ))}
            </div>
        </div>
    );
}

function EmptyState({ onAdd, hasChannels }) {
    return (
        <div className="flex h-full min-h-[360px] flex-col items-center justify-center text-center">
            <motion.div
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                className="max-w-sm"
            >
                <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-ink text-white">
                    <UserRound size={26} />
                </div>
                <h2 className="text-xl font-bold text-ink">Build your shop team</h2>
                <p className="mt-2 text-sm text-muted">
                    Put one person on Facebook and another on Instagram, or give someone products and orders only.
                    Everyone shares the shop wallet.
                </p>
                <button
                    type="button"
                    onClick={onAdd}
                    disabled={!hasChannels}
                    className="mt-5 inline-flex items-center gap-1.5 rounded-xl bg-coral px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                >
                    <Plus size={16} />
                    Add staff
                </button>
                {!hasChannels && (
                    <p className="mt-3 text-xs text-muted">Connect a channel first so you can assign pages.</p>
                )}
            </motion.div>
        </div>
    );
}
