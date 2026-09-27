import { useEffect, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Truck } from 'lucide-react';
import { api } from '../../api';
import { useSpace } from '../../context';
import { queryKeys } from '../../query';
import Modal from '../modals/Modal';
import { CheckboxField, NumberField, SearchField, TextField } from '../form';
import { ListSkeleton } from '../inboxSkeletons';

export default function DeliveryPanel() {
    const { can, setError } = useSpace();
    const queryClient = useQueryClient();
    const [editing, setEditing] = useState(null);
    const [creating, setCreating] = useState(false);

    const zonesQuery = useQuery({
        queryKey: queryKeys.deliveryZones,
        queryFn: async () => {
            const { data } = await api.get('/delivery-zones');
            return data.zones || [];
        },
    });

    const wilayasQuery = useQuery({
        queryKey: queryKeys.wilayas,
        queryFn: async () => {
            const { data } = await api.get('/wilayas');
            return data.wilayas || [];
        },
        staleTime: 30 * 60_000,
    });

    const zones = zonesQuery.data || [];
    const wilayas = wilayasQuery.data || [];
    const loading = (zonesQuery.isLoading && !zonesQuery.data) || (wilayasQuery.isLoading && !wilayasQuery.data);

    useEffect(() => {
        const err = zonesQuery.error || wilayasQuery.error;
        if (err) setError(err.response?.data?.message || 'Could not load delivery.');
    }, [zonesQuery.error, wilayasQuery.error, setError]);

    if (loading) {
        return (
            <div className="flex flex-1 flex-col px-4 py-6 sm:px-6">
                <ListSkeleton rows={4} className="max-w-lg" />
            </div>
        );
    }

    return (
        <>
            <header className="border-b border-line bg-white/70 px-4 py-4 sm:px-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-coral">Wasl</p>
                        <h1 className="mt-1 text-2xl font-extrabold tracking-tight text-ink">Delivery</h1>
                        <p className="mt-1 text-sm font-medium text-ink/70">Zones with wilayas and a fee. Catalog products pick which zones they ship to.</p>
                    </div>
                    {can('knowledge.manage') && (
                        <button
                            type="button"
                            onClick={() => { setCreating(true); setEditing(null); }}
                            className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-coral px-3.5 text-sm font-semibold text-white"
                        >
                            <Plus size={16} />
                            New zone
                        </button>
                    )}
                </div>
            </header>

            <div className="px-4 py-5 sm:px-6">
                {zones.length === 0 ? (
                    <div className="mx-auto flex max-w-md flex-col items-center rounded-2xl border border-dashed border-line bg-bubble/60 px-6 py-14 text-center">
                        <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white shadow-sm">
                            <Truck size={22} className="text-coral" />
                        </div>
                        <h2 className="mt-4 text-base font-bold text-ink">No zones yet</h2>
                        <p className="mt-1 text-sm text-muted">Group wilayas (Nord, Ouest, Est…) and set one fee per group.</p>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {zones.map((zone) => (
                            <button
                                key={zone.id}
                                type="button"
                                onClick={() => can('knowledge.manage') && setEditing(zone)}
                                className="rounded-2xl border border-line bg-white p-4 text-start transition hover:border-coral/40 hover:shadow-[0_12px_32px_-18px_rgba(31,42,55,0.35)]"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <h2 className="text-sm font-bold text-ink">{zone.name}</h2>
                                    <span className="rounded-full bg-bubble px-2 py-0.5 text-[11px] font-semibold text-ink">
                                        {(zone.wilayas || []).length} wilayas
                                    </span>
                                </div>
                                <p className="mt-2 text-lg font-extrabold tracking-tight text-ink">{Number(zone.fee).toLocaleString()} DZD</p>
                                <p className="text-[12px] text-muted">{zone.days || 'No ETA set'}</p>
                                <p className="mt-3 line-clamp-2 text-[12px] text-muted">
                                    {(zone.wilayas || []).map((w) => w.name_fr).join(', ') || 'No wilayas'}
                                </p>
                            </button>
                        ))}
                    </div>
                )}
            </div>

            {(creating || editing) && (
                <ZoneEditor
                    zone={creating ? null : editing}
                    wilayas={wilayas}
                    onClose={() => { setCreating(false); setEditing(null); }}
                    onSaved={(saved) => {
                        queryClient.setQueryData(queryKeys.deliveryZones, (list = []) => (
                            [...list.filter((row) => row.id !== saved.id), saved].sort((a, b) => a.name.localeCompare(b.name))
                        ));
                        setCreating(false);
                        setEditing(null);
                    }}
                    onDeleted={(id) => {
                        queryClient.setQueryData(queryKeys.deliveryZones, (list = []) => list.filter((row) => row.id !== id));
                        setCreating(false);
                        setEditing(null);
                    }}
                />
            )}
        </>
    );
}

function ZoneEditor({ zone, wilayas, onClose, onSaved, onDeleted }) {
    const { setError } = useSpace();
    const [query, setQuery] = useState('');
    const { register, handleSubmit, watch, setValue, formState: { errors, isSubmitting } } = useForm({
        defaultValues: {
            name: zone?.name || '',
            fee: zone?.fee == null ? '' : String(zone.fee),
            days: zone?.days || '2-4 days',
            wilaya_ids: zone?.wilaya_ids || [],
        },
        resolver: zodResolver(z.object({
            name: z.string().trim().min(1, 'Name is required.'),
            fee: z.string().min(1, 'Fee is required.'),
            days: z.string().default(''),
            wilaya_ids: z.array(z.number()).min(1, 'Pick at least one wilaya.'),
        })),
    });
    const selected = watch('wilaya_ids') || [];

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();
        return wilayas.filter((w) => !needle || `${w.code} ${w.name_fr} ${w.name_ar}`.toLowerCase().includes(needle));
    }, [wilayas, query]);

    return (
        <Modal open wide title={zone?.id ? zone.name : 'New zone'} onClose={onClose}>
            <form
                className="space-y-3"
                onSubmit={handleSubmit(async (values) => {
                    try {
                        const payload = { ...values, fee: Number(values.fee) };
                        const { data } = zone?.id
                            ? await api.put(`/delivery-zones/${zone.id}`, payload)
                            : await api.post('/delivery-zones', payload);
                        onSaved(data.zone);
                    } catch (err) {
                        setError(err.response?.data?.message || 'Could not save zone.');
                    }
                })}
            >
                <TextField label="Zone name" placeholder="Nord, Ouest…" error={errors.name?.message} {...register('name')} />
                <div className="grid grid-cols-2 gap-2">
                    <NumberField label="Fee (DZD)" error={errors.fee?.message} {...register('fee')} />
                    <TextField label="ETA" {...register('days')} />
                </div>
                <SearchField value={query} onChange={setQuery} placeholder="Search wilayas" />
                <div className="max-h-56 overflow-y-auto rounded-xl border-2 border-ink/15">
                    {filtered.map((wilaya) => {
                        const checked = selected.includes(wilaya.id);
                        return (
                            <div key={wilaya.id} className="flex items-center gap-2 border-b border-line px-2 last:border-0">
                                <CheckboxField
                                    label={`${wilaya.code}  ${wilaya.name_fr}`}
                                    checked={checked}
                                    onChange={() => setValue(
                                        'wilaya_ids',
                                        checked ? selected.filter((id) => id !== wilaya.id) : [...selected, wilaya.id],
                                        { shouldValidate: true },
                                    )}
                                    trailing={<span className="text-[12px] font-semibold text-ink/70">{wilaya.name_ar}</span>}
                                />
                            </div>
                        );
                    })}
                </div>
                <p className="text-[12px] font-semibold text-ink/70">
                    {selected.length} wilayas selected
                    {errors.wilaya_ids?.message ? ` · ${errors.wilaya_ids.message}` : ''}
                </p>
                <div className="flex justify-between gap-2 pt-1">
                    {zone?.id ? (
                        <button
                            type="button"
                            className="h-9 rounded-lg px-3 text-sm font-semibold text-coral hover:bg-bubble"
                            onClick={async () => {
                                await api.delete(`/delivery-zones/${zone.id}`);
                                onDeleted(zone.id);
                            }}
                        >
                            Delete
                        </button>
                    ) : <span />}
                    <div className="flex gap-2">
                        <button type="button" onClick={onClose} className="h-9 rounded-lg border border-line px-3 text-sm font-semibold hover:bg-bubble">Cancel</button>
                        <button type="submit" disabled={isSubmitting} className="h-9 rounded-lg bg-coral px-3.5 text-sm font-semibold text-white disabled:opacity-60">
                            Save
                        </button>
                    </div>
                </div>
            </form>
        </Modal>
    );
}
