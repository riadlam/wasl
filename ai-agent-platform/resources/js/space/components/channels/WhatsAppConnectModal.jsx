import Modal from '../modals/Modal';
import { platformMeta } from './platforms';

/**
 * Pre-connect guidance for WhatsApp Coexistence (existing Business app numbers).
 */
export default function WhatsAppConnectModal({
    open,
    busy = false,
    onClose,
    onStart,
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
                    Wasl links your <strong>existing WhatsApp Business app</strong> number to Cloud API
                    (Meta Coexistence). You keep using the same number — we do not create a new one.
                </p>
                <div className="rounded-2xl border border-line bg-bubble/40 p-3 text-sm text-ink">
                    <p className="font-semibold">Before you continue</p>
                    <ul className="mt-2 list-disc space-y-1.5 ps-5 text-muted">
                        <li>
                            Use the <strong>WhatsApp Business</strong> app (not personal WhatsApp).
                            Personal WhatsApp numbers cannot stay on the phone and join Cloud API.
                        </li>
                        <li>WhatsApp Business app version 2.24.17 or newer.</li>
                        <li>
                            In the Meta popup, choose to connect your <strong>existing</strong> Business
                            number (not “create a new number”).
                        </li>
                        <li>
                            If Meta says the number is already registered on personal WhatsApp, delete /
                            migrate that chat app first, wait ~3 minutes, then retry.
                        </li>
                    </ul>
                </div>
                <p className="text-xs leading-relaxed text-muted">
                    Meta Coexistence must also be enabled on SocialAPI&apos;s Embedded Signup config, and
                    this domain ({typeof window !== 'undefined' ? window.location.hostname : 'Wasl'}) must
                    be allowlisted. If the popup still forces a new number, email{' '}
                    <a className="font-semibold text-accent underline" href="mailto:support@social-api.ai">
                        support@social-api.ai
                    </a>{' '}
                    asking them to enable <em>WhatsApp Business app onboarding (Coexistence)</em> and
                    allowlist this hostname.
                </p>
                <div className="flex flex-wrap items-center gap-2 border-t border-line pt-4">
                    <button
                        type="button"
                        disabled={busy}
                        onClick={onStart}
                        className="rounded-xl bg-ink px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                    >
                        {busy ? 'Opening Meta…' : 'Continue with Meta'}
                    </button>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </Modal>
    );
}
