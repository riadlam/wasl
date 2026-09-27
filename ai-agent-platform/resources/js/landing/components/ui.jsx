import { motion, useReducedMotion } from 'framer-motion';

export function Reveal({ children, delay = 0, className = '', immediate = false }) {
    const reduce = useReducedMotion();

    if (immediate || reduce) {
        return <div className={className}>{children}</div>;
    }

    return (
        <motion.div
            className={className}
            initial={{ opacity: 0, y: 28 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true, amount: 0.2 }}
            transition={{ duration: 0.65, delay, ease: [0.22, 1, 0.36, 1] }}
        >
            {children}
        </motion.div>
    );
}

export function CtaButton({ variant = 'primary', className = '', children, href, onClick, type = 'button' }) {
    const styles = {
        primary:
            'bg-coral text-white shadow-[0_18px_50px_-18px_rgb(255_90_60_/_0.45)] hover:bg-coral-dark',
        outline: 'border border-ink/15 bg-white/70 text-ink hover:bg-white',
        ghost: 'text-ink/80 hover:text-ink hover:bg-ink/5',
        light: 'bg-white text-coral hover:bg-cream',
        onband: 'border border-white/40 bg-transparent text-white hover:bg-white/10',
    };

    const cls = `inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-xl px-5 text-[15px] font-semibold transition-colors ${styles[variant]} ${className}`;

    if (href) {
        return (
            <motion.a
                href={href}
                onClick={onClick}
                whileHover={{ scale: 1.03 }}
                whileTap={{ scale: 0.98 }}
                className={cls}
            >
                {children}
            </motion.a>
        );
    }

    return (
        <motion.button
            type={type}
            onClick={onClick}
            whileHover={{ scale: 1.03 }}
            whileTap={{ scale: 0.98 }}
            className={cls}
        >
            {children}
        </motion.button>
    );
}

export function Logo({ className = '' }) {
    return (
        <a href="#top" className={`flex items-center gap-2.5 ${className}`}>
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-linear-to-br from-coral to-teal shadow-md">
                <svg viewBox="0 0 24 24" className="h-5 w-5 text-white" fill="none" aria-hidden="true">
                    <path
                        d="M7 12h10M9 8.5 7 12l2 3.5M15 8.5l2 3.5-2 3.5"
                        stroke="currentColor"
                        strokeWidth="2"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    />
                </svg>
            </span>
            <span className="text-lg font-extrabold tracking-tight">Wasl</span>
        </a>
    );
}
