import { z } from 'zod';

export const productSchema = z.object({
    name: z.string().trim().min(1, 'Name is required.'),
    description: z.string().default(''),
    sku: z.string().default(''),
    price: z.string().default(''),
    compare_price: z.string().default(''),
    on_sale: z.boolean().default(false),
    stock: z.union([z.string(), z.number()]).default('0'),
    status: z.enum(['active', 'draft', 'archived']).default('active'),
    type: z.enum(['physical', 'digital']).default('physical'),
    channel_scope: z.enum(['all', 'selected']).default('all'),
    category: z.string().default(''),
    social_account_ids: z.array(z.number()).default([]),
    delivery_zone_ids: z.array(z.number()).default([]),
    variants: z.array(z.any()).default([]),
    digital_asset: z.object({
        delivery_note: z.string().default(''),
        access_url: z.string().default(''),
    }).default({ delivery_note: '', access_url: '' }),
    fulfillment_fields: z.string().default(''),
}).superRefine((data, ctx) => {
    const price = Number(data.price);
    if (data.price === '' || Number.isNaN(price) || price < 0) {
        ctx.addIssue({ code: 'custom', path: ['price'], message: 'Enter a valid price.' });
    }
    if (data.on_sale) {
        if (data.compare_price === '' || Number.isNaN(Number(data.compare_price))) {
            ctx.addIssue({ code: 'custom', path: ['compare_price'], message: 'Enter the original price.' });
        } else if (Number(data.compare_price) <= price) {
            ctx.addIssue({ code: 'custom', path: ['compare_price'], message: 'Original price must be higher than sale price.' });
        }
    }
});

export function detailsReadyFrom(values) {
    const nameOk = Boolean(String(values.name || '').trim());
    const price = Number(values.price);
    const priceOk = values.price !== '' && !Number.isNaN(price) && price >= 0;
    if (!values.on_sale) return nameOk && priceOk;
    const compare = Number(values.compare_price);
    return nameOk && priceOk && values.compare_price !== '' && !Number.isNaN(compare) && compare > price;
}
