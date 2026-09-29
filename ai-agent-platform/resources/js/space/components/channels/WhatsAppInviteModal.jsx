import Modal from '../modals/Modal';
import { platformMeta } from './platforms';

/**
 * Shown after opening a SocialAPI-hosted WhatsApp invite in a new tab.
 */
export default function WhatsAppInviteModal({
    open,
    inviteUrl = '',
    busy = false,
    onClose,
    onRefresh,
}) {
    const meta = platformMeta('whatsapp');
    const Icon = meta.Icon;

    return (
        <Modal open={open} title="Connect WhatsApp" onClose={onClose} wide>
            <div className="space-y-4">
                <div
                    className="flex h-12 w-12 items-center justify-center rounded-2xl"
                    style={{ backgroundColor: `${meta.color}14`, color: meta.color }}
                >
                    <Icon size={26} />
                </div>
                <p className="text-sm leading-relaxed text-muted">
                    WhatsApp signup opens on SocialAPI (Meta does not allow the popup on our domain
                    until they allowlist it). Finish the steps in the other tab, then come back here.
                </p>
                <ol className="list-decimal space-y-2 ps-5 text-sm text-ink">
                    <li>Complete Meta Embedded Signup in the SocialAPI tab.</li>
                    <li>Return here and click <strong>I&apos;ve connected</strong> so Wasl imports the number for this shop.</li>
                </ol>
                <div className="flex flex-wrap items-center gap-2 border-t border-line pt-4">
                    <button
                        type="button"
                        disabled={busy}
                        onClick={onRefresh}
                        className="rounded-xl bg-ink px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                    >
                        {busy ? 'Checking…' : "I've connected"}
                    </button>
                    {inviteUrl ? (
                        <a
                            href={inviteUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink"
                        >
                            Reopen invite
                        </a>
                    ) : null}
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink"
                    >
                        Close
                    </button>
                </div>
            </div>
        </Modal>
    );
}
