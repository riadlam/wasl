const tones = {
    teal: '#0f9b8e',
    accent: '#1b70ff',
    apricot: '#ffb020',
    ink: '#1f2a37',
    coral: '#ff5a3c',
};

export default function TemplateWell({ tone = 'accent' }) {
    const fill = tones[tone] || tones.accent;

    return (
        <div className="flex h-[120px] items-center justify-center rounded-lg border border-line bg-bubble">
            <svg viewBox="0 0 160 72" className="h-16 w-36" aria-hidden="true">
                <rect x="8" y="10" width="92" height="52" rx="8" fill="#fff" stroke="#e6e8ec" />
                <rect x="18" y="22" width="48" height="6" rx="3" fill={fill} />
                <rect x="18" y="34" width="70" height="4" rx="2" fill="#e6e8ec" />
                <rect x="18" y="44" width="40" height="4" rx="2" fill="#e6e8ec" />
                <circle cx="128" cy="28" r="16" fill="#e8f1ff" />
                <circle cx="128" cy="28" r="6" fill={fill} />
            </svg>
        </div>
    );
}
