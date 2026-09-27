/**
 * Left-panel briefing for workflow template preview + editor.
 */
export default function WorkflowBriefing({
    eyebrow = 'Briefing',
    title,
    summary,
    bullets = [],
    how = [],
    status = null,
    children = null,
}) {
    return (
        <div className="space-y-4 text-[13px] text-ink">
            {(title || summary) && (
                <div className="rounded-2xl border border-line bg-bubble/60 px-3.5 py-3">
                    {eyebrow && (
                        <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-coral">{eyebrow}</p>
                    )}
                    {title && <p className="mt-1 text-sm font-semibold text-ink">{title}</p>}
                    {summary && <p className="mt-1.5 text-[13px] leading-relaxed text-muted">{summary}</p>}
                    {status}
                </div>
            )}

            {bullets.length > 0 && (
                <section>
                    <h2 className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted">What this does</h2>
                    <ul className="space-y-2">
                        {bullets.map((item) => (
                            <li key={item} className="flex gap-2.5 text-[13px] leading-snug text-ink">
                                <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-coral" aria-hidden />
                                <span>{item}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {how.length > 0 && (
                <section>
                    <h2 className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted">How to set it up</h2>
                    <ol className="space-y-2">
                        {how.map((item, index) => (
                            <li key={item} className="flex gap-2.5 text-[13px] leading-snug text-ink">
                                <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-ink text-[10px] font-bold text-white">
                                    {index + 1}
                                </span>
                                <span className="pt-0.5">{item}</span>
                            </li>
                        ))}
                    </ol>
                </section>
            )}

            {children}
        </div>
    );
}

export function engagementBriefing({ postCount = 0, replyLabel, dmLabel } = {}) {
    return {
        eyebrow: 'Post automation',
        title: 'Comment → reply → private DM',
        summary: 'When someone comments on the posts you pick, this flow runs public reply and Auto DM in order.',
        bullets: [
            postCount > 0
                ? `Currently covers ${postCount} post${postCount === 1 ? '' : 's'} — click “Comment on posts” on the canvas to change.`
                : 'Pick one or more posts on the canvas trigger — only those posts run this automation.',
            `Public reply: ${replyLabel || 'AI agent or a fixed message (+ optional image)'}.`,
            `Auto DM: ${dmLabel || 'default agent message or your custom private reply'}.`,
            'One active automation per post — activating here replaces any other active flow on the same posts.',
        ],
        how: [
            'Click the first canvas step and select the posts to cover.',
            'Open Public reply — choose AI agent or fixed text.',
            'Open Auto DM — leave to agent or write a custom message.',
            'Turn Active on, then Save.',
        ],
    };
}

export function leadBriefing({ signalLabel, isHot = false } = {}) {
    return {
        eyebrow: isHot ? 'Hot lead' : 'Mark lead',
        title: isHot ? 'Ready-to-buy signal' : 'New lead signal',
        summary: isHot
            ? 'The AI watches inbox chats and marks a contact Hot when your signal clearly appears.'
            : 'The AI watches inbox chats and marks a new lead when your signal clearly appears.',
        bullets: [
            `Current signal: ${signalLabel || 'Phone number'} — change it on the Trigger canvas step.`,
            'Works on DMs and comments the agent already processes.',
            'Requires a clear match by default so shop numbers or order IDs are not marked by mistake.',
            'Marked contacts show up under Leads for follow-up.',
        ],
        how: [
            'Click Trigger on the canvas and pick what counts as the signal.',
            'Optionally relax “Require a clear match” on the AI classify step.',
            'Turn Active on, then Save.',
        ],
    };
}

export function dmKeywordBriefing({ platform = 'Pick platforms', keywordCount = 0, replyMode = 'fixed' } = {}) {
    return {
        eyebrow: 'DM automation',
        title: 'Keyword → instant reply',
        summary: 'When someone DMs you words you care about, send your prepared message or let the AI answer — on the platforms you choose.',
        bullets: [
            `Platforms: ${platform} — only DMs on selected platforms trigger this flow.`,
            keywordCount > 0
                ? `${keywordCount} keyword${keywordCount === 1 ? '' : 's'} set — edit them on the first canvas step.`
                : 'Add words or phrases like “price”, “prix”, or “كم السعر” on the canvas.',
            replyMode === 'agent'
                ? 'Reply mode: AI agent drafts the answer when a keyword matches.'
                : 'Reply mode: your fixed text (and optional image) sends instantly.',
            'If no keyword matches, the normal inbox AI settings still apply.',
        ],
        how: [
            'Click “When someone messages” — pick platforms and add keywords.',
            'Click “Send reply” — choose Fixed message or AI agent.',
            'Give the automation a clear name (it appears in Inbox).',
            'Turn Active on, then Save.',
        ],
    };
}

