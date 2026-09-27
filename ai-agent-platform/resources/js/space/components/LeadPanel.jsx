import { PanelLeftClose, X } from 'lucide-react';
import { platformMeta, conversationPlatform } from './channels/platforms';
import { LeadPanelSkeleton } from './inboxSkeletons';
import { useSpace } from '../context';
import { IconButton, LeadAvatar } from './ui';

export default function LeadPanel({ conversation, onClose, onCollapse, overlay = false }) {
    const { threadLoadingId } = useSpace();
    if (!conversation) return null;

    const meta = platformMeta(conversationPlatform(conversation));
    const Icon = meta.Icon;
    const handle = conversation.username ? `@${String(conversation.username).replace(/^@/, '')}` : null;
    const delivery = [conversation.wilaya || conversation.city, conversation.commune].filter(Boolean).join(' · ');
    const notes = insightLines(conversation.insights);
    const aiOn = conversation.ai_enabled && !conversation.human;
    const loading = String(threadLoadingId) === String(conversation.id) && !conversation.username && !conversation.phone;

    return (
        <aside className="flex h-full min-h-0 w-full flex-col bg-white">
            <div className="flex h-10 items-center justify-between gap-1 border-b border-line px-2.5">
                <span className="text-[12px] font-semibold text-ink">Lead</span>
                <div className="flex items-center">
                    {onCollapse && !overlay && (
                        <IconButton label="Collapse lead" onClick={onCollapse}>
                            <PanelLeftClose size={14} className="rotate-180" />
                        </IconButton>
                    )}
                    {onClose && (
                        <button type="button" aria-label="Close lead" onClick={onClose} className="rounded-lg p-1 text-muted hover:bg-bubble hover:text-ink">
                            <X size={15} />
                        </button>
                    )}
                </div>
            </div>

            {loading ? (
                <LeadPanelSkeleton />
            ) : (
            <div className="inbox-scroll min-h-0 flex-1 overflow-y-auto px-2.5 pb-4 pt-2.5">
                <div className="flex items-start gap-2.5 border-b border-line pb-3">
                    <LeadAvatar name={conversation.name} src={conversation.avatar_url} size={40} />
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-[13px] font-semibold text-ink">{conversation.name}</div>
                        <div className="mt-0.5 flex items-center gap-1 truncate text-[11px] text-muted">
                            <Icon size={11} className="shrink-0" style={{ color: meta.color }} />
                            <span className="truncate">{handle || 'No username'}</span>
                        </div>
                        <div className="mt-1.5 flex flex-wrap items-center gap-1">
                            <span className="rounded bg-bubble px-1.5 py-0.5 text-[10px] font-semibold text-ink">{meta.label}</span>
                            <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold ${aiOn ? 'bg-info text-accent' : 'bg-bubble text-muted'}`}>
                                {aiOn ? 'AI on' : 'Human'}
                            </span>
                        </div>
                    </div>
                </div>

                <dl className="mt-2.5 space-y-0 divide-y divide-line">
                    <Row label="Page" value={conversation.account_name} empty="—" />
                    <Row label="Phone" value={conversation.phone} empty="—" />
                    <Row label="Delivery" value={delivery} empty="—" />
                    <Row label="Language" value={conversation.language} empty="—" />
                    <Row label="Email" value={conversation.email} empty="—" />
                    <Row
                        label="Meta Ad ID"
                        value={conversation.meta_ad_title ? `${conversation.meta_ad_id} · ${conversation.meta_ad_title}` : conversation.meta_ad_id}
                        empty="—"
                        mono
                    />
                </dl>

                <div className="mt-3">
                    <div className="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-muted">AI notes</div>
                    {notes.length === 0 ? (
                        <p className="rounded-lg bg-bubble px-2 py-2 text-[11px] leading-snug text-muted">
                            Delivery place and details the agent learns appear here.
                        </p>
                    ) : (
                        <ul className="space-y-1.5">
                            {notes.map((note) => (
                                <li key={note.label} className="rounded-lg bg-bubble px-2 py-1.5">
                                    <div className="text-[10px] font-semibold uppercase tracking-wide text-muted">{note.label}</div>
                                    <div className="text-[12px] text-ink">{note.value}</div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
            )}
        </aside>
    );
}

function Row({ label, value, empty, mono = false }) {
    return (
        <div className="flex items-baseline justify-between gap-2 py-1.5">
            <dt className="shrink-0 text-[11px] font-semibold text-muted">{label}</dt>
            <dd className={`min-w-0 truncate text-end text-[12px] ${value ? `font-medium text-ink ${mono ? 'font-mono' : ''}` : 'text-muted'}`}>
                {value || empty}
            </dd>
        </div>
    );
}

function insightLines(insights) {
    if (!insights) return [];
    if (Array.isArray(insights)) {
        return insights
            .map((item, index) => {
                if (item == null || item === '') return null;
                if (typeof item === 'string') return { label: 'Note', value: item, key: index };
                if (typeof item === 'object') {
                    const label = item.label || item.key || 'Note';
                    const value = item.value || item.text || '';
                    return value ? { label, value } : null;
                }
                return null;
            })
            .filter(Boolean);
    }
    if (typeof insights === 'object') {
        return Object.entries(insights)
            .filter(([, value]) => value != null && value !== '')
            .map(([label, value]) => ({
                label: label.replace(/_/g, ' '),
                value: String(value),
            }));
    }
    return [];
}
