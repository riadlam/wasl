export default function PageFrame({ title, subtitle, action, children }) {
    return (
        <div className="h-full overflow-y-auto">
            <div className="mx-auto w-full max-w-5xl px-4 py-5 sm:px-6">
                <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                        {subtitle && <p className="mt-1 text-sm font-medium leading-snug text-ink/70">{subtitle}</p>}
                    </div>
                    {action}
                </div>
                {children}
            </div>
        </div>
    );
}
