export function controlClass(invalid = false, extra = '') {
    return [
        'h-10 w-full rounded-lg border bg-white px-3 text-[13px] font-medium text-ink outline-none transition',
        'placeholder:font-normal placeholder:text-ink/40',
        'focus:ring-0',
        'disabled:cursor-not-allowed disabled:bg-cream-deep disabled:text-ink/45',
        invalid ? 'border-coral focus:border-coral' : 'border-line focus:border-ink',
        extra,
    ].filter(Boolean).join(' ');
}

export function labelClass() {
    return 'mb-1.5 block text-[12px] font-medium text-muted';
}

export function hintClass(error = false) {
    return error
        ? 'mt-1 text-[12px] font-normal text-coral'
        : 'mt-1 text-[12px] font-normal text-muted';
}
