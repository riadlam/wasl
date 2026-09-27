import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { AnimatePresence, motion } from 'framer-motion';
import { X } from 'lucide-react';
import { CtaButton } from './ui';
import { TextField } from '../../space/components/form';

const schema = z.object({
    name: z.string().trim().min(1, 'Name is required.'),
    shop: z.string().trim().min(1, 'Shop name is required.'),
    contact: z.string().trim().min(3, 'WhatsApp or email is required.'),
});

export default function WaitlistModal({ open, onClose }) {
    const [sent, setSent] = useState(false);
    const { register, handleSubmit, reset, formState: { errors } } = useForm({
        defaultValues: { name: '', shop: '', contact: '' },
        resolver: zodResolver(schema),
    });

    const close = () => {
        onClose();
        setTimeout(() => {
            setSent(false);
            reset({ name: '', shop: '', contact: '' });
        }, 280);
    };

    return (
        <AnimatePresence>
            {open && (
                <motion.div
                    className="fixed inset-0 z-[80] flex items-end justify-center bg-ink/40 p-4 sm:items-center"
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    exit={{ opacity: 0 }}
                    onClick={close}
                >
                    <motion.div
                        initial={{ y: 24, opacity: 0 }}
                        animate={{ y: 0, opacity: 1 }}
                        exit={{ y: 16, opacity: 0 }}
                        onClick={(e) => e.stopPropagation()}
                        className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"
                    >
                        <div className="mb-4 flex items-start justify-between">
                            <div>
                                <h3 className="text-xl font-extrabold">Get early access</h3>
                                <p className="mt-1 text-sm font-semibold text-ink/70">
                                    Auth is coming next. Leave your shop details and we’ll save your spot.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={close}
                                className="rounded-lg p-1 hover:bg-ink/5"
                                aria-label="Close"
                            >
                                <X size={18} />
                            </button>
                        </div>

                        {sent ? (
                            <p className="rounded-xl bg-teal/10 px-4 py-6 text-center font-semibold text-teal-dark">
                                You’re on the list. We’ll write you on WhatsApp soon.
                            </p>
                        ) : (
                            <form
                                onSubmit={handleSubmit(() => setSent(true))}
                                className="space-y-3"
                            >
                                <TextField label="Your name" error={errors.name?.message} {...register('name')} />
                                <TextField label="Shop name" error={errors.shop?.message} {...register('shop')} />
                                <TextField label="WhatsApp or email" error={errors.contact?.message} {...register('contact')} />
                                <CtaButton type="submit" className="mt-2 w-full">
                                    Join the waitlist
                                </CtaButton>
                            </form>
                        )}
                    </motion.div>
                </motion.div>
            )}
        </AnimatePresence>
    );
}
