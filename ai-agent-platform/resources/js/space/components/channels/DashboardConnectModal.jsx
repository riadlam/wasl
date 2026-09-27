import Modal from '../modals/Modal';
import { platformMeta } from './platforms';

export default function DashboardConnectModal({ open, platformId = 'whatsapp', onClose }) {
    const meta = platformMeta(platformId);
    const Icon = meta.Icon;
    const isWhatsApp = platformId === 'whatsapp';

    return (
        <Modal open={open} title={`Connect ${meta.label}`} onClose={onClose} wide>
            <div className="space-y-4">
                <div
                    className="flex h-12 w-12 items-center justify-center rounded-2xl"
                    style={{ backgroundColor: `${meta.color}14`, color: meta.color }}
                >
                    <Icon size={26} />
                </div>
                <p className="text-sm leading-relaxed text-muted">
                    {isWhatsApp
                        ? 'WhatsApp uses Meta Embedded Signup. Channels stay private to this shop — we never import another shop’s accounts.'
                        : `${meta.label} connects for this shop only. Use Connect on the Channels page when in-app authorize is available.`}
                </p>
                <ol className="list-decimal space-y-2 ps-5 text-sm text-ink">
                    <li>
                        Keep this shop selected in Wasl (channels never sync from other shops).
                    </li>
                    <li>
                        Complete the connect steps when prompted, then return here — the account is stored for this shop only.
                    </li>
                </ol>
                <div className="flex flex-wrap items-center gap-2 border-t border-line pt-4">
                    <button type="button" onClick={onClose} className="rounded-xl bg-ink px-4 py-2 text-sm font-semibold text-white">
                        Got it
                    </button>
                </div>
            </div>
        </Modal>
    );
}
