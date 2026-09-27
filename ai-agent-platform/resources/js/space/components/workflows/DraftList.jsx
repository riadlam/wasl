import { Flame, GitBranch, Pause, Play, Plus, Zap } from 'lucide-react';
import TemplateWell from './TemplateWell';
import WorkflowsSidebar from './WorkflowsSidebar';
import { triggerLabelFor } from './TriggerPicker';

export default function DraftList({ items, onBrowse, onSelect, canManage, onShowLibrary }) {
    const activeCount = items.filter((item) => item.status === 'active').length;

    return (
        <div className="flex h-full min-h-0 bg-white">
            <WorkflowsSidebar
                mode="saved"
                counts={{ saved: items.length, active: activeCount }}
                onShowLibrary={onShowLibrary || onBrowse}
                onShowSaved={() => {}}
            />

            <div className="min-h-0 flex-1 overflow-y-auto">
                <header className="border-b border-line px-4 py-4 sm:px-6">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-coral">Wasl</p>
                            <h1 className="mt-1 text-2xl font-extrabold tracking-tight text-ink">Your workflows</h1>
                            <p className="mt-1 text-sm text-muted">
                                Post comment automations and lead signals for your inbox.
                            </p>
                        </div>
                        {canManage && (
                            <button
                                type="button"
                                onClick={onBrowse}
                                className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-coral px-3.5 text-sm font-semibold text-white"
                            >
                                <Plus size={16} />
                                New workflow
                            </button>
                        )}
                    </div>
                    <div className="mt-4 flex flex-wrap gap-2 lg:hidden">
                        <button
                            type="button"
                            onClick={onBrowse}
                            className="h-8 rounded-lg border border-line px-2.5 text-xs font-semibold text-ink hover:bg-bubble"
                        >
                            Browse templates
                        </button>
                    </div>
                </header>

                <div className="px-4 py-5 sm:px-6">
                    {items.length === 0 ? (
                        <EmptyState onBrowse={canManage ? onBrowse : null} />
                    ) : (
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {items.map((item) => (
                                <WorkflowCard key={item.id} item={item} onSelect={onSelect} />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

function WorkflowCard({ item, onSelect }) {
    const isEngagement = item.kind === 'engagement' || item.template_key === 'post_comment';
    const isDm = item.kind === 'dm' || item.template_key === 'dm_keyword';
    const signal = isDm
        ? `${(item.config?.platforms || (item.config?.platform ? [item.config.platform] : [])).length || 0} platform(s) · ${(item.config?.keywords || []).length} keyword${(item.config?.keywords || []).length === 1 ? '' : 's'}`
        : isEngagement
            ? `${(item.posts || []).length} post${(item.posts || []).length === 1 ? '' : 's'}`
            : (item.config?.trigger_field === 'custom' && item.config?.trigger_hint
                ? item.config.trigger_hint
                : (item.trigger_label || triggerLabelFor(item.config?.trigger_field || 'phone', item)));
    const stepCount = (item.steps || []).filter((step) => step.kind === 'action' || step.kind === 'message' || step.kind === 'trigger').length;
    const isHot = item.template_key === 'hot_lead';

    return (
        <button
            type="button"
            onClick={() => onSelect?.(item)}
            className="group flex flex-col overflow-hidden rounded-2xl border border-line bg-white text-start transition hover:border-coral/40 hover:shadow-[0_12px_32px_-18px_rgba(31,42,55,0.35)]"
        >
            <div className="p-2 pb-0">
                <TemplateWell tone={item.tone || (isHot ? 'apricot' : (isEngagement || isDm) ? 'accent' : 'coral')} />
            </div>
            <div className="flex flex-1 flex-col gap-2 p-4 pt-3">
                <div className="flex items-start justify-between gap-2">
                    <h3 className="text-[15px] font-semibold text-ink">{item.name || item.title}</h3>
                    <StatusPill status={item.status} />
                </div>
                <p className="line-clamp-2 text-[12px] text-muted">
                    {isDm ? `DM · ${signal}` : isEngagement ? `Trigger · ${signal}` : `Signal · ${signal}`}
                </p>
                <div className="mt-auto flex items-center gap-2 text-[11px] font-semibold text-muted">
                    {isDm || isEngagement ? <Zap size={12} /> : (isHot ? <Flame size={12} /> : <GitBranch size={12} />)}
                    {stepCount} steps
                    {item.status === 'active' ? <Play size={12} className="ms-auto text-accent" /> : <Pause size={12} className="ms-auto" />}
                </div>
            </div>
        </button>
    );
}

function EmptyState({ onBrowse }) {
    return (
        <div className="mx-auto flex max-w-md flex-col items-center rounded-2xl border border-dashed border-line bg-bubble/60 px-6 py-14 text-center">
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white shadow-sm">
                <GitBranch size={22} className="text-coral" />
            </div>
            <h2 className="mt-4 text-base font-bold text-ink">No workflows yet</h2>
            <p className="mt-1 text-sm text-muted">
                Start from Post comment automation or a lead template, then edit steps on the canvas.
            </p>
            {onBrowse && (
                <button
                    type="button"
                    onClick={onBrowse}
                    className="mt-5 inline-flex h-9 items-center gap-1.5 rounded-lg bg-coral px-3.5 text-sm font-semibold text-white"
                >
                    <Plus size={16} />
                    Browse templates
                </button>
            )}
        </div>
    );
}

function StatusPill({ status }) {
    if (status === 'active') {
        return (
            <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-teal/10 px-2 py-0.5 text-[11px] font-semibold text-teal-dark">
                <Play size={10} />
                Active
            </span>
        );
    }
    if (status === 'paused') {
        return (
            <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-bubble px-2 py-0.5 text-[11px] font-semibold text-ink">
                <Pause size={10} />
                Paused
            </span>
        );
    }
    return (
        <span className="inline-flex shrink-0 rounded-full bg-bubble px-2 py-0.5 text-[11px] font-semibold text-muted">
            Draft
        </span>
    );
}
