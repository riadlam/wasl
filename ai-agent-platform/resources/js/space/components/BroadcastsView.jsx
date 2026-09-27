import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { broadcasts } from '../data';
import PageFrame from './PageFrame';
import { TextField } from './form';

const schema = z.object({
    name: z.string().trim().min(1, 'Name is required.'),
});

export default function BroadcastsView() {
    const [items, setItems] = useState(broadcasts);
    const [adding, setAdding] = useState(false);
    const { register, handleSubmit, reset, formState: { errors } } = useForm({
        defaultValues: { name: '' },
        resolver: zodResolver(schema),
    });

    return (
        <PageFrame
            title="Broadcasts"
            subtitle="One message to the people who already talked to you."
            action={<button type="button" onClick={() => setAdding(true)} className="h-9 rounded-xl bg-coral px-4 text-sm font-semibold text-white">New broadcast</button>}
        >
            <div className="overflow-hidden rounded-2xl border border-line bg-white">
                {items.map((item) => (
                    <div key={item.id} className="flex flex-col gap-1 border-b border-line px-4 py-3 last:border-0 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div className="font-semibold text-ink">{item.name}</div>
                            <div className="text-sm font-medium text-ink/70">{item.audience}</div>
                        </div>
                        <div className="text-sm font-semibold text-ink">{item.status} · {item.when}</div>
                    </div>
                ))}
            </div>
            {adding && (
                <form
                    className="mt-3 flex flex-col gap-2 rounded-2xl border-2 border-dashed border-ink/15 bg-white p-4 sm:flex-row sm:items-end"
                    onSubmit={handleSubmit((values) => {
                        setItems((list) => [...list, { id: `b-${Date.now()}`, name: values.name, status: 'Draft', audience: 'All open chats', when: 'Not scheduled' }]);
                        reset({ name: '' });
                        setAdding(false);
                    })}
                >
                    <div className="min-w-0 flex-1">
                        <TextField label="Broadcast name" error={errors.name?.message} {...register('name')} />
                    </div>
                    <button type="submit" className="h-12 rounded-xl bg-ink px-4 text-sm font-semibold text-white">Save draft</button>
                </form>
            )}
        </PageFrame>
    );
}
