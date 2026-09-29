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
                    Wasl links your <strong>existing WhatsApp Business app</strong> number via Meta
                    Coexistence. Same number, same chats — no new number is created.
                </p>
                <div className="rounded-2xl border border-line bg-bubble/40 p-3 text-sm text-ink">
                    <p className="font-semibold">Before you continue</p>
                    <ul className="mt-2 list-disc space-y-1.5 ps-5 text-muted">
                        <li>
                            Number must be on the <strong>WhatsApp Business</strong> app
                            (version 2.24.17+), not personal WhatsApp.
                        </li>
                        <li>
                            In the Meta popup, choose to connect your <strong>existing</strong> Business
                            number.
                        </li>
                        <li>
                            After the popup, Meta may ask you to <strong>confirm the link inside the
                            WhatsApp Business app</strong> on your phone — keep the app open.
                        </li>
                        <li>
                            If the number is still on regular WhatsApp, move it to WhatsApp Business
                            first (or delete that WhatsApp account, wait ~3 minutes, then retry).
                        </li>
                    </ul>
                </div>
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
