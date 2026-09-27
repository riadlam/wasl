import { useMemo } from 'react';
import { useSpace } from '../context';

export default function EmptyInbox() {
    const { can, setView, socialAccounts } = useSpace();

    const hasLiveChannel = useMemo(
        () => (socialAccounts || []).some(
            (a) => a.platform && a.platform !== 'simulator' && a.status !== 'disconnected',
        ),
        [socialAccounts],
    );

    return (
        <div className="flex h-full min-h-0 flex-1 flex-col items-center justify-center bg-[radial-gradient(ellipse_at_top,_#fff8f3_0%,_#ffffff_55%)] px-6 py-10 text-center">
            <InboxIllustration />
            <h2 className="mt-6 text-lg font-extrabold tracking-tight text-ink">
                {hasLiveChannel ? 'Waiting for your first message' : 'No chats yet'}
            </h2>
            <p className="mt-2 max-w-sm text-[13px] leading-relaxed text-mute">
                {hasLiveChannel
                    ? 'Your connected Instagram, Facebook, or WhatsApp chats will show up here as customers write in.'
                    : 'Connect Instagram, Facebook, or WhatsApp to receive live DMs in this inbox.'}
            </p>
            {!hasLiveChannel && can('settings.view') && (
                <button
                    type="button"
                    onClick={() => setView('channels')}
                    className="mt-6 inline-flex h-10 items-center justify-center rounded-xl bg-ink px-5 text-sm font-semibold text-white transition hover:bg-ink/90"
                >
                    Connect a channel
                </button>
            )}
        </div>
    );
}

function InboxIllustration() {
    return (
        <svg
            width="220"
            height="168"
            viewBox="0 0 220 168"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            className="select-none"
            aria-hidden="true"
        >
            <ellipse cx="110" cy="148" rx="72" ry="10" fill="#F0E8DF" />
            <rect x="38" y="28" width="144" height="108" rx="18" fill="#FFF8F3" stroke="#E8DFD4" strokeWidth="2" />
            <rect x="54" y="48" width="68" height="14" rx="7" fill="#F4E6DC" />
            <rect x="54" y="70" width="96" height="12" rx="6" fill="#EFE6DC" />
            <rect x="54" y="90" width="80" height="12" rx="6" fill="#EFE6DC" />
            <circle cx="162" cy="42" r="22" fill="#FF6B4A" />
            <path
                d="M154.5 42.5h15M162 35v15"
                stroke="white"
                strokeWidth="2.5"
                strokeLinecap="round"
            />
            <rect x="118" y="108" width="46" height="28" rx="12" fill="#1C1C1C" />
            <circle cx="132" cy="122" r="3" fill="#FFF8F3" />
            <circle cx="141" cy="122" r="3" fill="#FFF8F3" />
            <circle cx="150" cy="122" r="3" fill="#FFF8F3" />
        </svg>
    );
}
