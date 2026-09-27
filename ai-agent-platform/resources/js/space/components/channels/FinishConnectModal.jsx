import Modal from '../modals/Modal';
import { FormSkeleton } from '../inboxSkeletons';

export default function FinishConnectModal({
    open,
    platform,
    pages,
    profiles,
    selectedPages,
    setSelectedPages,
    busy,
    error,
    onClose,
    onSubmit,
}) {
    const isFacebook = pages.length > 0 || platform === 'facebook';
    const title = isFacebook ? 'Choose Facebook Page' : 'Choose Google profile';
    const loading = busy === 'pending';

    return (
        <Modal open={open} title={title} onClose={onClose} wide>
            <form className="space-y-4" onSubmit={onSubmit}>
                <p className="text-sm leading-relaxed text-muted">
                    {isFacebook
                        ? 'Pick one Facebook Page for this shop. Each channel links a single Page.'
                        : 'Finish selecting the profile to link to this shop.'}
                </p>

                {loading && (
                    <FormSkeleton fields={3} compact className="rounded-xl border border-line bg-bubble/40 p-3" />
                )}

                {!loading && error && (
                    <p className="rounded-xl bg-bubble px-3 py-3 text-sm font-semibold text-ink">
                        Could not load options. The pending connection may have expired — connect again.
                    </p>
                )}

                {!loading && !error && pages.length === 0 && profiles.length === 0 && (
                    <p className="rounded-xl bg-bubble px-3 py-3 text-sm font-semibold text-ink">
                        No Pages or profiles returned. On Meta, keep the Pages checked, then reconnect.
                    </p>
                )}

                <div className="max-h-[40vh] space-y-2 overflow-y-auto pr-1">
                    {pages.map((page) => {
                        const locked = page.assignable === false;
                        const checked = selectedPages[0] === page.platform_page_id;
                        return (
                            <label
                                key={page.platform_page_id}
                                className={`flex cursor-pointer items-center gap-3 rounded-xl border px-3 py-3 text-sm transition ${
                                    locked
                                        ? 'cursor-not-allowed border-line opacity-50'
                                        : checked
                                            ? 'border-accent bg-selected'
                                            : 'border-line hover:bg-bubble'
                                }`}
                            >
                                <input
                                    type="radio"
                                    name="facebook_page"
                                    className="accent-accent"
                                    disabled={locked}
                                    checked={checked}
                                    onChange={() => setSelectedPages([page.platform_page_id])}
                                />
                                <span className="min-w-0 flex-1 font-semibold text-ink">{page.name || page.platform_page_id}</span>
                                {locked && <span className="text-[11px] font-semibold text-muted">Taken</span>}
                            </label>
                        );
                    })}
                    {profiles.map((profile) => {
                        const id = profile.platform_account_id;
                        const checked = selectedPages[0] === id;
                        return (
                            <label
                                key={id}
                                className={`flex cursor-pointer items-center gap-3 rounded-xl border px-3 py-3 text-sm transition ${
                                    checked ? 'border-accent bg-selected' : 'border-line hover:bg-bubble'
                                }`}
                            >
                                <input
                                    type="radio"
                                    name="profile"
                                    className="accent-accent"
                                    checked={checked}
                                    onChange={() => setSelectedPages([id])}
                                />
                                <span className="font-semibold text-ink">{profile.display_name || id}</span>
                            </label>
                        );
                    })}
                </div>

                <div className="flex items-center justify-end gap-2 border-t border-line pt-4">
                    <button type="button" onClick={onClose} className="rounded-xl px-4 py-2 text-sm font-semibold text-muted hover:bg-bubble">
                        Close
                    </button>
                    <button
                        type="submit"
                        disabled={busy === 'select' || loading || (pages.length === 0 && profiles.length === 0)}
                        className="rounded-xl bg-ink px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        {busy === 'select' ? 'Saving…' : 'Save to this shop'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
