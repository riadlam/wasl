import Modal from '../modals/Modal';
import { platformMeta } from './platforms';

export default function DashboardConnectModal({ open, platformId = 'telegram', onClose }) {
    const meta = platformMeta(platformId);
    const Icon = meta.Icon;

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
                    {meta.label} connects with credentials for this shop only
                    (bot token / app password). In-app authorize is not available yet —
                    use the SocialAPI dashboard for this channel, then refresh Channels.
                </p>
                <ol className="list-decimal space-y-2 ps-5 text-sm text-ink">
                    <li>Keep this shop selected in Wasl (channels never sync from other shops).</li>
                    <li>
                        Open SocialAPI, connect {meta.label} to this shop&apos;s brand, then return
                        here and refresh.
                    </li>
                </ol>
                <div className="flex flex-wrap items-center gap-2 border-t border-line pt-4">
                    <a
                        href="https://dashboard.social-api.ai"
                        target="_blank"
                        rel="noreferrer"
                        className="rounded-xl bg-ink px-4 py-2 text-sm font-semibold text-white"
                    >
                        Open SocialAPI
                    </a>
                    <button type="button" onClick={onClose} className="rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink">
                        Close
                    </button>
                </div>
            </div>
        </Modal>
    );
}
