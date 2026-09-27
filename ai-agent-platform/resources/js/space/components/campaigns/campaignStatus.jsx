export const CAMPAIGN_STATUS = {
    queued: { label: 'Queued', tone: 'bg-ink/8 text-ink/60' },
    scheduled: { label: 'Scheduled', tone: 'bg-teal/12 text-teal-dark' },
    running: { label: 'Running', tone: 'bg-coral/12 text-coral' },
    paused_wallet: { label: 'Paused: top up wallet', tone: 'bg-amber-100 text-amber-800' },
    partial: { label: 'Partly scheduled', tone: 'bg-amber-100 text-amber-800' },
    completed: { label: 'All scheduled', tone: 'bg-teal/12 text-teal-dark' },
    failed: { label: 'Failed', tone: 'bg-red-50 text-red-700' },
    cancelled: { label: 'Cancelled', tone: 'bg-ink/8 text-ink/45' },
};

export const SLOT_STATUS = {
    pending: { label: 'Waiting', tone: 'bg-ink/8 text-ink/55' },
    generating: { label: 'Writing', tone: 'bg-coral/12 text-coral' },
    publishing: { label: 'Queuing', tone: 'bg-coral/12 text-coral' },
    awaiting_approval: { label: 'Needs approval', tone: 'bg-amber-100 text-amber-800' },
    regen_requested: { label: 'Regenerating', tone: 'bg-coral/12 text-coral' },
    scheduled: { label: 'Scheduled', tone: 'bg-teal/12 text-teal-dark' },
    failed: { label: 'Failed', tone: 'bg-red-50 text-red-700' },
    cancelled: { label: 'Cancelled', tone: 'bg-ink/8 text-ink/45' },
    skipped: { label: 'Skipped', tone: 'bg-ink/8 text-ink/45' },
    deleted: { label: 'Removed', tone: 'bg-ink/8 text-ink/45' },
    ready: { label: 'Ready', tone: 'bg-amber-100 text-amber-800' },
};

export const LIVE_STATUSES = ['queued', 'scheduled', 'running'];

export const SLOT_BUSY = ['pending', 'generating', 'publishing', 'regen_requested'];
export const SLOT_SKELETON = ['pending', 'generating', 'publishing', 'regen_requested'];

export function StatusPill({ status, map = CAMPAIGN_STATUS }) {
    const meta = map[status] || { label: status || 'Unknown', tone: 'bg-ink/8 text-ink/55' };
    return (
        <span className={`inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${meta.tone}`}>
            {meta.label}
        </span>
    );
}

export function formatDa(value) {
    const n = Number(value) || 0;
    return `${n.toLocaleString(undefined, { maximumFractionDigits: n < 10 ? 2 : 0 })} DA`;
}

export function formatWhen(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}
