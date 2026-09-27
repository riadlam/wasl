export default function FormCard({ title, hint, children, className = '' }) {
    return (
        <section className={`rounded-xl border border-line bg-white p-4 ${className}`}>
            {title && <h2 className="text-sm font-semibold text-ink">{title}</h2>}
            {hint && <p className="mt-1 text-[12px] font-normal leading-snug text-muted">{hint}</p>}
            <div className={title || hint ? 'mt-4 space-y-3.5' : 'space-y-3.5'}>{children}</div>
        </section>
    );
}
