import { useEffect, useMemo, useRef, useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Check, Plus, Star, Trash2 } from 'lucide-react';
import { api } from '../../api';
import { useSpace } from '../../context';
import { platformMeta } from '../channels/platforms';
import {
    CheckboxField,
    FileDrop,
    FormCard,
    NumberField,
    SelectField,
    SwitchField,
    TextArea,
    TextField,
} from '../form';
import { detailsReadyFrom, productSchema } from './schema';

const STEPS = [
    { id: 'details', label: 'Details' },
    { id: 'media', label: 'Media' },
    { id: 'variants', label: 'Variants' },
    { id: 'selling', label: 'Selling' },
];

const GALLERY_LIMIT = 3;

const emptyProduct = () => ({
    name: '',
    description: '',
    sku: '',
    price: '',
    compare_price: '',
    on_sale: false,
    stock: '0',
    status: 'active',
    type: 'physical',
    channel_scope: 'all',
    category: '',
    social_account_ids: [],
    delivery_zone_ids: [],
    variants: [],
    digital_asset: { delivery_note: '', access_url: '' },
    fulfillment_fields: '',
});

function emptyVariant() {
    return {
        key: crypto.randomUUID(),
        name: '',
        sku: '',
        price: '',
        stock: '0',
        attributes: {},
        pendingFile: null,
        previewUrl: null,
        image_url: null,
        image_id: null,
    };
}

export default function ProductEditor({ product, zones, onClose, onSaved, onDeleted }) {
    const { can, setError, socialAccounts } = useSpace();
    const openedWithId = useRef(Boolean(product?.id));
    const [step, setStep] = useState(0);
    const [maxStep, setMaxStep] = useState(openedWithId.current ? STEPS.length - 1 : 0);
    const [busy, setBusy] = useState(false);
    const [gallery, setGallery] = useState(() => hydrateGallery(product));
    const [removedImageIds, setRemovedImageIds] = useState([]);
    const galleryState = useRef(gallery);
    galleryState.current = gallery;
    const linked = (socialAccounts || []).filter((a) => a.status !== 'disconnected' && a.platform !== 'simulator' && (a.connected !== false));
    const canManage = can('products.manage');

    const { register, control, watch, setValue, getValues, reset, trigger, formState: { errors } } = useForm({
        defaultValues: hydrate(product),
        resolver: zodResolver(productSchema),
        mode: 'onTouched',
    });

    const { fields: variantFields, append, remove, update } = useFieldArray({
        control,
        name: 'variants',
        keyName: 'key',
    });

    const name = watch('name');
    const type = watch('type');
    const onSale = watch('on_sale');
    const price = watch('price');
    const comparePrice = watch('compare_price');
    const channelScope = watch('channel_scope');
    const socialIds = watch('social_account_ids') || [];
    const zoneIds = watch('delivery_zone_ids') || [];
    const variants = watch('variants') || [];

    useEffect(() => {
        reset(hydrate(product));
        setGallery(hydrateGallery(product));
        setRemovedImageIds([]);
        if (product?.id && openedWithId.current) {
            setMaxStep(STEPS.length - 1);
        }
        if (!product?.id) {
            openedWithId.current = false;
            setMaxStep(0);
            setStep(0);
        }
    }, [product?.id, reset]);

    useEffect(() => () => {
        galleryState.current.forEach((item) => item.url?.startsWith('blob:') && URL.revokeObjectURL(item.url));
        getValues('variants')?.forEach((variant) => variant.previewUrl && URL.revokeObjectURL(variant.previewUrl));
    }, [getValues]);

    const discount = useMemo(() => {
        const sale = Number(price);
        const compare = Number(comparePrice);
        if (!onSale || !sale || !compare || compare <= sale) return 0;
        return Math.round((1 - sale / compare) * 100);
    }, [onSale, price, comparePrice]);

    const detailsReady = detailsReadyFrom({ name, price, compare_price: comparePrice, on_sale: onSale });
    const galleryCount = gallery.length;

    const persist = async () => {
        const values = getValues();
        const payload = serialize(values);
        const { data } = product?.id
            ? await api.put(`/products/${product.id}`, payload)
            : await api.post('/products', payload);
        let saved = data.product;

        for (const id of removedImageIds) {
            const res = await api.delete(`/products/${saved.id}/images/${id}`);
            saved = res.data.product;
        }

        for (const item of gallery) {
            if (!item.file) continue;
            const body = new FormData();
            body.append('image', item.file);
            if (item.is_main) body.append('is_main', '1');
            const res = await api.post(`/products/${saved.id}/images`, body);
            saved = res.data.product;
        }

        const mainExisting = gallery.find((item) => item.is_main && item.id && !item.file);
        if (mainExisting) {
            const res = await api.patch(`/products/${saved.id}/images/${mainExisting.id}/main`);
            saved = res.data.product;
        }

        const named = (values.variants || []).filter((variant) => String(variant.name || '').trim());
        for (const variant of named) {
            if (!variant.pendingFile) continue;
            const match = saved.variants.find((row) => row.id === variant.id)
                || saved.variants.find((row) => row.name === String(variant.name).trim());
            if (!match) continue;
            const body = new FormData();
            body.append('image', variant.pendingFile);
            body.append('variant_id', String(match.id));
            const res = await api.post(`/products/${saved.id}/images`, body);
            saved = res.data.product;
        }

        gallery.forEach((item) => item.url?.startsWith('blob:') && URL.revokeObjectURL(item.url));
        setGallery(hydrateGallery(saved));
        setRemovedImageIds([]);
        reset(hydrate(saved));
        onSaved(saved);
        return saved;
    };

    const goToStep = async (index) => {
        if (index > maxStep) {
            await trigger(['name', 'price', 'compare_price']);
            setError(detailsReady ? 'Use Continue to move to the next step.' : 'Finish Details before continuing.');
            return;
        }
        if (index > 0 && !detailsReady) {
            await trigger(['name', 'price', 'compare_price']);
            setError('Finish Details before continuing.');
            setStep(0);
            setMaxStep(0);
            return;
        }
        setStep(index);
    };

    const goNext = async () => {
        if (!canManage) return;
        if (step === 0) {
            const ok = await trigger(['name', 'price', 'compare_price']);
            if (!ok) {
                setError('Finish required fields.');
                return;
            }
        }
        const next = Math.min(step + 1, STEPS.length - 1);
        setMaxStep((m) => Math.max(m, next));
        setStep(next);
    };

    const save = async () => {
        if (!canManage) return;
        const ok = await trigger(['name', 'price', 'compare_price']);
        if (!ok) {
            setError('Finish required fields.');
            setStep(0);
            return;
        }
        setBusy(true);
        try {
            await persist();
        } catch (err) {
            setError(err.response?.data?.message || 'Could not save product.');
        } finally {
            setBusy(false);
        }
    };

    const queueGallery = (fileList) => {
        const incoming = Array.from(fileList || []).filter((file) => file instanceof File);
        if (!incoming.length) return;
        const room = Math.max(0, GALLERY_LIMIT - gallery.length);
        const take = incoming.slice(0, room);
        if (!take.length) return;
        setGallery((list) => {
            let hasMain = list.some((item) => item.is_main);
            return [
                ...list,
                ...take.map((file) => {
                    const isMain = !hasMain;
                    hasMain = true;
                    return { key: crypto.randomUUID(), id: null, file, url: URL.createObjectURL(file), is_main: isMain };
                }),
            ];
        });
    };

    const setGalleryMain = (key) => {
        setGallery((list) => list.map((item) => ({ ...item, is_main: item.key === key })));
    };

    const removeGalleryItem = (slot) => {
        if (slot.url?.startsWith('blob:')) URL.revokeObjectURL(slot.url);
        if (slot.id) setRemovedImageIds((ids) => [...ids, slot.id]);
        setGallery((list) => {
            const next = list.filter((item) => item.key !== slot.key);
            if (next.length && !next.some((item) => item.is_main)) {
                next[0] = { ...next[0], is_main: true };
            }
            return next;
        });
    };

    const last = step === STEPS.length - 1;
    const continueDisabled = step === 0 && !detailsReady;

    return (
        <div className="absolute inset-0 z-30 flex flex-col overflow-hidden bg-cream-deep">
            <header className="shrink-0 border-b border-line bg-white px-3 py-1.5 sm:px-4">
                <div className="flex items-center gap-2">
                    <h1 className="w-[7.5rem] shrink-0 truncate text-[13px] font-extrabold tracking-tight text-ink sm:w-[9rem]">
                        {product?.id ? name || 'Edit product' : 'New product'}
                    </h1>
                    <ol className="mx-auto flex min-w-0 items-center gap-1 overflow-x-auto">
                        {STEPS.map((item, index) => {
                            const active = index === step;
                            const done = index < step;
                            const locked = index > maxStep || (index > 0 && !detailsReady);
                            return (
                                <li key={item.id} className="shrink-0">
                                    <button
                                        type="button"
                                        disabled={locked}
                                        onClick={(e) => {
                                            e.preventDefault();
                                            if (locked) {
                                                trigger(['name', 'price', 'compare_price']);
                                                setError(detailsReady ? 'Use Continue to move to the next step.' : 'Finish Details before continuing.');
                                                return;
                                            }
                                            goToStep(index);
                                        }}
                                        className={`flex items-center gap-1 rounded-full border px-2 py-1 ${
                                            active
                                                ? 'border-coral bg-coral/5'
                                                : locked
                                                    ? 'pointer-events-none cursor-not-allowed border-transparent bg-cream-deep opacity-45'
                                                    : 'border-line bg-white hover:bg-bubble'
                                        }`}
                                    >
                                        <span className={`flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[9px] font-extrabold ${
                                            active ? 'bg-coral text-white' : done ? 'bg-ink text-white' : 'bg-cream-deep text-ink/55'
                                        }`}>
                                            {done ? <Check size={9} strokeWidth={3} /> : index + 1}
                                        </span>
                                        <span className={`hidden text-[11px] font-bold sm:inline ${active ? 'text-ink' : 'text-ink/60'}`}>{item.label}</span>
                                    </button>
                                </li>
                            );
                        })}
                    </ol>
                    <div className="flex shrink-0 items-center gap-1">
                        {product?.id && canManage && (
                            <button
                                type="button"
                                onClick={async () => {
                                    await api.delete(`/products/${product.id}`);
                                    onDeleted(product.id);
                                }}
                                className="h-7 rounded-md border border-line px-2 text-[11px] font-bold text-coral hover:bg-bubble"
                            >
                                Delete
                            </button>
                        )}
                        <button type="button" onClick={onClose} className="h-7 rounded-md border border-line px-2 text-[11px] font-bold text-ink hover:bg-bubble">
                            Close
                        </button>
                    </div>
                </div>
            </header>

            <div className="min-h-0 flex-1 overflow-y-auto">
                <form className="mx-auto w-full max-w-5xl space-y-4 px-4 py-4 sm:px-6" onSubmit={(e) => e.preventDefault()}>
                    {step === 0 && (
                        <DetailsStep
                            register={register}
                            control={control}
                            errors={errors}
                            type={type}
                            onSale={onSale}
                            discount={discount}
                            canManage={canManage}
                            setValue={setValue}
                        />
                    )}
                    {step === 1 && (
                        <GalleryStep
                            images={gallery}
                            galleryCount={galleryCount}
                            onPick={queueGallery}
                            onSetMain={setGalleryMain}
                            onRemove={removeGalleryItem}
                        />
                    )}
                    {step === 2 && (
                        <VariantsStep
                            type={type}
                            fields={variantFields}
                            variants={variants}
                            canManage={canManage}
                            register={register}
                            append={append}
                            remove={remove}
                            update={update}
                        />
                    )}
                    {step === 3 && (
                        <SellingStep
                            register={register}
                            type={type}
                            channelScope={channelScope}
                            socialIds={socialIds}
                            zoneIds={zoneIds}
                            zones={zones}
                            linked={linked}
                            canManage={canManage}
                            setValue={setValue}
                        />
                    )}
                </form>
            </div>

            <footer className="flex shrink-0 items-center justify-between gap-3 border-t border-line bg-white px-3 py-2 sm:px-4">
                <button
                    type="button"
                    disabled={step === 0}
                    onClick={() => setStep((n) => Math.max(0, n - 1))}
                    className="h-9 rounded-lg border border-line px-3 text-[13px] font-bold text-ink hover:bg-bubble disabled:opacity-40"
                >
                    Back
                </button>
                <p className="text-[11px] font-bold text-ink/60">{step + 1} / {STEPS.length}</p>
                {canManage && (
                    last ? (
                        <button type="button" disabled={busy || !detailsReady} onClick={save} className="h-9 rounded-lg bg-coral px-4 text-[13px] font-bold text-white hover:bg-coral-dark disabled:opacity-50">
                            {busy ? 'Saving…' : 'Save product'}
                        </button>
                    ) : (
                        <button type="button" disabled={continueDisabled} onClick={goNext} className="h-9 rounded-lg bg-coral px-4 text-[13px] font-bold text-white hover:bg-coral-dark disabled:opacity-50">
                            Continue
                        </button>
                    )
                )}
            </footer>
        </div>
    );
}

function DetailsStep({ register, control, errors, type, onSale, discount, canManage, setValue }) {
    return (
        <>
            <FormCard title="Type">
                <div className="grid grid-cols-2 gap-3">
                    {['physical', 'digital'].map((item) => (
                        <button
                            key={item}
                            type="button"
                            disabled={!canManage}
                            onClick={() => {
                                setValue('type', item);
                                if (item === 'digital') setValue('variants', []);
                            }}
                            className={`rounded-2xl border-2 px-4 py-3.5 text-start ${type === item ? 'border-coral bg-coral/5' : 'border-ink/15 bg-white hover:border-ink/30'}`}
                        >
                            <span className="block text-sm font-extrabold capitalize text-ink">{item}</span>
                            <span className="mt-1 block text-[12px] font-semibold text-ink/70">
                                {item === 'physical' ? 'Ships with delivery zones' : 'Sent as a file or link'}
                            </span>
                        </button>
                    ))}
                </div>
            </FormCard>
            <FormCard title="Basics">
                <TextField label="Name *" error={errors.name?.message} placeholder="Air Max sneakers" disabled={!canManage} {...register('name')} />
                <TextArea label="Description" placeholder="What the customer needs to know" disabled={!canManage} {...register('description')} />
                <div className="grid gap-3 sm:grid-cols-2">
                    <TextField label="SKU" disabled={!canManage} {...register('sku')} />
                    <TextField label="Category" disabled={!canManage} {...register('category')} />
                    <NumberField label="Stock" disabled={!canManage} {...register('stock')} />
                    <Controller
                        control={control}
                        name="status"
                        render={({ field }) => (
                            <SelectField
                                label="Status"
                                value={field.value}
                                onChange={field.onChange}
                                disabled={!canManage}
                                options={[
                                    { value: 'active', label: 'Active' },
                                    { value: 'draft', label: 'Draft' },
                                    { value: 'archived', label: 'Archived' },
                                ]}
                            />
                        )}
                    />
                </div>
            </FormCard>
            <FormCard title="Pricing" hint="Sale price is what the agent quotes. Original price is optional.">
                <div className="grid gap-3 sm:grid-cols-2">
                    <NumberField label="Price (DZD) *" error={errors.price?.message} placeholder="8500" disabled={!canManage} {...register('price')} />
                    <SwitchField
                        label={onSale ? 'On sale' : 'Add discount'}
                        description="Show a compared original price"
                        checked={onSale}
                        disabled={!canManage}
                        onChange={(value) => {
                            setValue('on_sale', value);
                            if (!value) setValue('compare_price', '');
                        }}
                    />
                </div>
                {onSale && (
                    <div className="grid items-end gap-3 sm:grid-cols-[1fr_auto]">
                        <NumberField label="Original price (DZD) *" error={errors.compare_price?.message} placeholder="10000" disabled={!canManage} {...register('compare_price')} />
                        {discount > 0 && (
                            <span className="mb-0.5 inline-flex h-12 items-center rounded-xl bg-coral px-3 text-sm font-extrabold text-white">
                                −{discount}%
                            </span>
                        )}
                    </div>
                )}
            </FormCard>
        </>
    );
}

function GalleryStep({ images, galleryCount, onPick, onSetMain, onRemove }) {
    const remaining = Math.max(0, GALLERY_LIMIT - images.length);

    return (
        <section className="mx-auto w-full max-w-md rounded-2xl border border-ink/10 bg-white p-4 text-center shadow-card sm:p-5">
            <h2 className="text-sm font-extrabold text-ink">Gallery</h2>
            <p className="mt-1 text-[12px] font-semibold text-ink/70">
                {galleryCount} of {GALLERY_LIMIT} photos
            </p>
            <p className="mx-auto mt-2 max-w-[22rem] rounded-xl bg-coral/10 px-3 py-2 text-[12px] font-semibold leading-snug text-ink">
                The photo marked <span className="font-extrabold text-coral">Main photo</span> is what customers see first.
                Tap <span className="font-extrabold">Set as main</span> on another photo to change it.
            </p>

            <div className="mx-auto mt-4 grid w-full max-w-[20rem] grid-cols-3 gap-2 sm:max-w-[22.5rem] sm:gap-3">
                {images.map((slot) => {
                    const isMain = slot.is_main;
                    return (
                        <figure
                            key={slot.id || slot.key}
                            className={`relative aspect-square overflow-hidden rounded-xl border-2 bg-cream-deep ${isMain ? 'border-coral ring-2 ring-coral/30' : 'border-ink/15'}`}
                        >
                            <img src={slot.url} alt="" className="h-full w-full object-cover" />
                            {isMain ? (
                                <span className="absolute inset-x-1 top-1 inline-flex items-center justify-center gap-0.5 whitespace-nowrap rounded-full bg-coral px-1 py-0.5 text-[9px] font-extrabold uppercase tracking-wide text-white sm:text-[10px]">
                                    <Star size={9} fill="currentColor" /> Main photo
                                </span>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => onSetMain(slot.key)}
                                    className="absolute inset-x-1 top-1 inline-flex items-center justify-center gap-0.5 whitespace-nowrap rounded-full bg-white/95 px-1 py-1 text-[9px] font-extrabold text-ink shadow-sm hover:bg-coral hover:text-white sm:text-[10px]"
                                >
                                    <Star size={9} /> Set as main
                                </button>
                            )}
                            <button
                                type="button"
                                className="absolute end-1 bottom-1 flex h-7 w-7 items-center justify-center rounded-full bg-white text-coral shadow-sm hover:bg-bubble"
                                onClick={() => onRemove(slot)}
                                aria-label="Remove photo"
                            >
                                <Trash2 size={12} />
                            </button>
                        </figure>
                    );
                })}
                {Array.from({ length: remaining }).map((_, index) => (
                    <FileDrop
                        key={`empty-${index}`}
                        compact
                        multiple
                        onFiles={onPick}
                        className="aspect-square"
                    />
                ))}
            </div>

            {remaining > 0 && (
                <div className="mt-4">
                    <FileDrop onFiles={onPick} hint={`JPG, PNG or WEBP · ${remaining} remaining`} />
                </div>
            )}
        </section>
    );
}

function VariantsStep({ type, fields, variants, canManage, register, append, remove, update }) {
    if (type === 'digital') {
        return (
            <FormCard title="Variants">
                <p className="text-sm font-semibold text-ink/70">Digital products don’t use size or color variants. Continue to selling options.</p>
            </FormCard>
        );
    }

    return (
        <FormCard title="Variants" hint="Optional. Leave empty to sell the product as-is. Each variant can have its own photo.">
            <div className="space-y-3">
                {fields.map((field, index) => {
                    const variant = variants[index] || field;
                    return (
                        <div key={field.key} className="flex gap-3 rounded-2xl border-2 border-ink/15 bg-white p-3">
                            <div className="h-[92px] w-[92px] shrink-0 overflow-hidden rounded-xl">
                                <FileDrop
                                    compact
                                    multiple={false}
                                    disabled={!canManage}
                                    preview={variant.previewUrl || variant.image_url}
                                    onFiles={(files) => {
                                        const file = files[0];
                                        if (!file) return;
                                        if (variant.previewUrl) URL.revokeObjectURL(variant.previewUrl);
                                        update(index, { ...variant, pendingFile: file, previewUrl: URL.createObjectURL(file) });
                                    }}
                                />
                            </div>
                            <div className="grid min-w-0 flex-1 gap-2 sm:grid-cols-2">
                                <TextField placeholder="Name · 42 / Black" disabled={!canManage} {...register(`variants.${index}.name`)} />
                                <TextField placeholder="SKU" disabled={!canManage} {...register(`variants.${index}.sku`)} />
                                <NumberField placeholder="Price override" disabled={!canManage} {...register(`variants.${index}.price`)} />
                                <NumberField placeholder="Stock" disabled={!canManage} {...register(`variants.${index}.stock`)} />
                            </div>
                            {canManage && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        if (variant.previewUrl) URL.revokeObjectURL(variant.previewUrl);
                                        remove(index);
                                    }}
                                    className="h-10 shrink-0 self-start rounded-lg border-2 border-line px-2 text-coral hover:bg-bubble"
                                >
                                    <Trash2 size={14} />
                                </button>
                            )}
                        </div>
                    );
                })}
            </div>
            {canManage && (
                <button
                    type="button"
                    onClick={() => append(emptyVariant())}
                    className="mt-1 inline-flex h-10 items-center gap-1.5 rounded-xl border-2 border-ink/15 bg-white px-3 text-sm font-bold hover:bg-bubble"
                >
                    <Plus size={14} /> Add variant
                </button>
            )}
        </FormCard>
    );
}

function SellingStep({ register, type, channelScope, socialIds, zoneIds, zones, linked, canManage, setValue }) {
    return (
        <>
            <FormCard title="Channels" hint="Where this product is offered.">
                <div className="grid grid-cols-2 gap-2">
                    {[
                        { id: 'all', label: 'All linked' },
                        { id: 'selected', label: 'Specific' },
                    ].map((opt) => (
                        <button
                            key={opt.id}
                            type="button"
                            disabled={!canManage}
                            onClick={() => setValue('channel_scope', opt.id)}
                            className={`rounded-xl border-2 px-3 py-2.5 text-sm font-bold ${channelScope === opt.id ? 'border-coral bg-coral/5' : 'border-ink/15 hover:bg-bubble'}`}
                        >
                            {opt.label}
                        </button>
                    ))}
                </div>
                {channelScope === 'selected' && (
                    <div className="mt-3 space-y-1">
                        {linked.length === 0 && <p className="text-[12px] font-semibold text-ink/70">Connect a channel first.</p>}
                        {linked.map((account) => {
                            const meta = platformMeta(account.platform);
                            const checked = socialIds.includes(account.id);
                            return (
                                <CheckboxField
                                    key={account.id}
                                    label={`${meta.label} · ${account.name || account.username}`}
                                    checked={checked}
                                    disabled={!canManage}
                                    onChange={() => setValue(
                                        'social_account_ids',
                                        checked ? socialIds.filter((id) => id !== account.id) : [...socialIds, account.id],
                                    )}
                                />
                            );
                        })}
                    </div>
                )}
            </FormCard>
            {type === 'physical' ? (
                <FormCard title="Delivery zones" hint="Leave empty to use every shop zone.">
                    {zones.length === 0 && <p className="text-[12px] font-semibold text-ink/70">Create zones under Products → Delivery first.</p>}
                    {zones.map((zone) => {
                        const checked = zoneIds.includes(zone.id);
                        return (
                            <CheckboxField
                                key={zone.id}
                                label={zone.name}
                                checked={checked}
                                disabled={!canManage}
                                onChange={() => setValue(
                                    'delivery_zone_ids',
                                    checked ? zoneIds.filter((id) => id !== zone.id) : [...zoneIds, zone.id],
                                )}
                                trailing={<span className="text-[12px] font-bold text-ink/70">{zone.fee} DZD</span>}
                            />
                        );
                    })}
                </FormCard>
            ) : (
                <FormCard title="Digital delivery" hint="Tell the agent which account fields to collect before payment.">
                    <TextArea label="Note to the agent" placeholder="Send the download link after payment confirmation." disabled={!canManage} {...register('digital_asset.delivery_note')} />
                    <TextField label="Access URL" disabled={!canManage} {...register('digital_asset.access_url')} />
                    <TextField
                        label="Fields to collect (comma-separated)"
                        placeholder="player_id, zone_id"
                        hint="Required IDs before create_order (e.g. player_id, zone_id, game_id)."
                        disabled={!canManage}
                        {...register('fulfillment_fields')}
                    />
                </FormCard>
            )}
        </>
    );
}

function hydrateGallery(product) {
    return (product?.images || []).map((image) => ({
        id: image.id,
        key: `saved-${image.id}`,
        url: image.url,
        is_main: Boolean(image.is_main),
        file: null,
    }));
}

function hydrate(product) {
    if (!product?.id) return emptyProduct();
    return {
        name: product.name || '',
        description: product.description || '',
        sku: product.sku || '',
        price: product.price == null ? '' : String(product.price),
        compare_price: product.compare_price == null ? '' : String(product.compare_price),
        on_sale: product.compare_price != null && Number(product.compare_price) > 0,
        stock: String(product.stock ?? 0),
        status: product.status || 'active',
        type: product.type || 'physical',
        channel_scope: product.channel_scope || 'all',
        category: product.category || '',
        social_account_ids: product.social_account_ids || [],
        delivery_zone_ids: product.delivery_zone_ids || [],
        variants: (product.variants || []).map((v) => ({
            key: `v-${v.id}`,
            id: v.id,
            name: v.name || '',
            sku: v.sku || '',
            price: v.price == null ? '' : String(v.price),
            stock: String(v.stock ?? 0),
            attributes: v.attributes || {},
            pendingFile: null,
            previewUrl: null,
            image_url: v.image_url || null,
            image_id: v.image_id || null,
        })),
        digital_asset: {
            delivery_note: product.digital_asset?.delivery_note || '',
            access_url: product.digital_asset?.access_url || '',
        },
        fulfillment_fields: Array.isArray(product.fulfillment_fields)
            ? product.fulfillment_fields.join(', ')
            : '',
    };
}

function serialize(form) {
    const fulfillmentFields = String(form.fulfillment_fields || '')
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean);

    return {
        name: form.name,
        description: form.description || null,
        sku: form.sku || null,
        price: Number(form.price || 0),
        compare_price: form.on_sale && form.compare_price !== '' ? Number(form.compare_price) : null,
        stock: Number(form.stock || 0),
        status: form.status,
        type: form.type,
        channel_scope: form.channel_scope,
        category: form.category || null,
        social_account_ids: form.channel_scope === 'selected' ? form.social_account_ids : [],
        delivery_zone_ids: form.type === 'physical' ? form.delivery_zone_ids : [],
        variants: form.type === 'digital'
            ? []
            : (form.variants || [])
                .filter((v) => String(v.name || '').trim())
                .map((v) => ({
                    id: v.id,
                    name: v.name,
                    sku: v.sku || null,
                    price: v.price === '' || v.price == null ? null : Number(v.price),
                    stock: Number(v.stock || 0),
                    attributes: v.attributes || {},
                })),
        digital_asset: form.type === 'digital' ? form.digital_asset : null,
        fulfillment_fields: form.type === 'digital' ? fulfillmentFields : [],
    };
}
