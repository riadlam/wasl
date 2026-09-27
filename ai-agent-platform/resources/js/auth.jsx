import { useRef } from 'react';
import { createRoot } from 'react-dom/client';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { CheckboxField, TextField } from './space/components/form';

function firstMessage(bag, key) {
    const value = bag?.[key];
    return Array.isArray(value) ? value[0] : (value || '');
}

function LoginForm({ csrf, action, old, errors, registerUrl }) {
    const formRef = useRef(null);
    const { register, handleSubmit, watch, setValue, formState: { errors: fieldErrors } } = useForm({
        defaultValues: { email: old.email || '', password: '', remember: false },
        resolver: zodResolver(z.object({
            email: z.string().trim().min(1, 'Email is required.'),
            password: z.string().min(1, 'Password is required.'),
            remember: z.boolean().optional(),
        })),
    });
    const remember = watch('remember');

    return (
        <div className="rounded-xl border border-line bg-white p-6">
            <h1 className="text-xl font-semibold text-ink">Log in</h1>
            <p className="mt-1 text-[13px] font-normal text-muted">Use your shop or staff account.</p>
            {firstMessage(errors, 'email') && (
                <p className="mt-3 rounded-xl bg-cream-deep px-3 py-2 text-sm font-semibold text-coral-dark">{firstMessage(errors, 'email')}</p>
            )}
            <form
                ref={formRef}
                method="POST"
                action={action}
                className="mt-5 space-y-3"
                onSubmit={handleSubmit(() => formRef.current?.submit())}
            >
                <input type="hidden" name="_token" value={csrf} />
                {remember && <input type="hidden" name="remember" value="1" />}
                <TextField
                    label="Email"
                    type="email"
                    autoFocus
                    error={fieldErrors.email?.message || firstMessage(errors, 'email')}
                    {...register('email')}
                />
                <TextField
                    label="Password"
                    type="password"
                    error={fieldErrors.password?.message}
                    {...register('password')}
                />
                <CheckboxField
                    label="Remember me"
                    checked={remember}
                    onChange={(value) => setValue('remember', value)}
                />
                <button type="submit" className="h-9 w-full rounded-lg bg-coral text-[13px] font-semibold text-white">Log in</button>
            </form>
            <p className="mt-4 text-center text-[13px] font-normal text-muted">
                New shop? <a href={registerUrl} className="font-medium text-accent">Create an account</a>
            </p>
        </div>
    );
}

function RegisterForm({ csrf, action, old, errors, loginUrl }) {
    const formRef = useRef(null);
    const { register, handleSubmit, formState: { errors: fieldErrors } } = useForm({
        defaultValues: {
            name: old.name || '',
            shop_name: old.shop_name || '',
            email: old.email || '',
            password: '',
            password_confirmation: '',
        },
        resolver: zodResolver(z.object({
            name: z.string().trim().min(1, 'Your name is required.'),
            shop_name: z.string().trim().min(1, 'Shop name is required.'),
            email: z.string().trim().min(1, 'Email is required.'),
            password: z.string().min(8, 'Use at least 8 characters.'),
            password_confirmation: z.string().min(1, 'Confirm your password.'),
        }).refine((data) => data.password === data.password_confirmation, {
            path: ['password_confirmation'],
            message: 'Passwords do not match.',
        })),
    });

    const banner = Object.values(errors || {}).flat()[0];

    return (
        <div className="rounded-xl border border-line bg-white p-6">
            <h1 className="text-xl font-semibold text-ink">Create your shop</h1>
            <p className="mt-1 text-[13px] font-normal text-muted">You will be the owner. You can add staff later.</p>
            {banner && <p className="mt-3 rounded-xl bg-cream-deep px-3 py-2 text-sm font-semibold text-coral-dark">{banner}</p>}
            <form
                ref={formRef}
                method="POST"
                action={action}
                className="mt-5 space-y-3"
                onSubmit={handleSubmit(() => formRef.current?.submit())}
            >
                <input type="hidden" name="_token" value={csrf} />
                <TextField label="Your name" error={fieldErrors.name?.message || firstMessage(errors, 'name')} {...register('name')} />
                <TextField label="Shop name" error={fieldErrors.shop_name?.message || firstMessage(errors, 'shop_name')} {...register('shop_name')} />
                <TextField label="Email" type="email" error={fieldErrors.email?.message || firstMessage(errors, 'email')} {...register('email')} />
                <TextField label="Password" type="password" error={fieldErrors.password?.message || firstMessage(errors, 'password')} {...register('password')} />
                <TextField label="Confirm password" type="password" error={fieldErrors.password_confirmation?.message} {...register('password_confirmation')} />
                <button type="submit" className="h-9 w-full rounded-lg bg-coral text-[13px] font-semibold text-white">Create shop</button>
            </form>
            <p className="mt-4 text-center text-[13px] font-normal text-muted">
                Already have an account? <a href={loginUrl} className="font-medium text-accent">Log in</a>
            </p>
        </div>
    );
}

const el = document.getElementById('auth-root');
if (el) {
    const payload = {
        csrf: el.dataset.csrf || '',
        action: el.dataset.action || '',
        loginUrl: el.dataset.login || '/login',
        registerUrl: el.dataset.register || '/register',
        old: JSON.parse(el.dataset.old || '{}'),
        errors: JSON.parse(el.dataset.errors || '{}'),
    };
    createRoot(el).render(
        el.dataset.mode === 'register'
            ? <RegisterForm {...payload} />
            : <LoginForm {...payload} />,
    );
}
