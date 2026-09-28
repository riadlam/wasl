import { apiErrorMessage } from '../../api';

export const CAMPAIGN_STEPS = [
    { id: 'channels', label: 'Channels', short: 'Where to publish' },
    { id: 'schedule', label: 'Schedule', short: 'Days & cadence' },
    { id: 'content', label: 'Content', short: 'How AI builds posts' },
    { id: 'review', label: 'Review', short: 'Confirm & launch' },
];

export const MIN_PROMPT_LEN = 10;
export const MAX_CAMPAIGN_DAYS = 7;

export const CONTENT_MODES = {
    ai_recent: {
        id: 'ai_recent',
        title: 'AI from your channels',
        description: 'We study recent posts on the selected pages, then draft new posts and stories in the same voice.',
        request: 'We research your pages, draft the post, then check brand fit before you see a sample.',
        exampleTitle: 'Example output',
        exampleBody: 'Carousel caption in Darija highlighting a weekend promo, plus two story frames with CTA stickers — timed to your Day 2 slots.',
        agents: ['Brief', 'Write', 'Brand check'],
    },
    product_images: {
        id: 'product_images',
        title: 'Product images',
        description: 'Upload product photos; AI creates one post and matching stories per image across your schedule.',
        request: 'Same flow on your uploads: research → draft → brand check, then post + story sizes.',
        exampleTitle: 'Example output',
        exampleBody: 'Square post with price line and hashtags, plus a vertical story with swipe-up style CTA — mapped to your Day 1 morning slot.',
        agents: ['Brief', 'Write', 'Brand check'],
    },
};

export const CAMPAIGN_TEASE_AGENTS = [
    { id: 'brief', role: 'Brief' },
    { id: 'identity', role: 'Identity' },
    { id: 'confirm_q', role: 'Confirm Q' },
    { id: 'write', role: 'Write' },
    { id: 'confirm', role: 'Brand check' },
];

export function minImagesForPosts(totalPosts) {
    const n = Number(totalPosts) || 0;
    if (n <= 0) return 0;
    return Math.ceil(n * 0.8);
}

export function createDaySchedule(dayIndex, prev) {
    const posts = prev?.posts ?? (dayIndex === 1 ? 2 : 1);
    const stories = prev?.stories ?? 1;
    const defaultTimes = posts >= 2 ? ['10:00', '18:00'] : ['12:00'];
    return {
        dayIndex,
        posts,
        stories,
        times: [...(prev?.times?.length ? prev.times : defaultTimes)],
    };
}

export function resizeScheduleDays(dayCount, existingDays = []) {
    const count = Math.min(MAX_CAMPAIGN_DAYS, Math.max(1, Number(dayCount) || 1));
    const next = [];
    for (let i = 0; i < count; i += 1) {
        next.push(createDaySchedule(i + 1, existingDays[i]));
    }
    return next;
}

export function toDateInputValue(date = new Date()) {
    const d = date instanceof Date ? date : new Date(date);
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

/** Tomorrow in the browser's local timezone — default campaign start. */
export function defaultStartsOn() {
    const d = new Date();
    d.setHours(12, 0, 0, 0);
    d.setDate(d.getDate() + 1);
    return toDateInputValue(d);
}

export function todayDateInput() {
    const d = new Date();
    d.setHours(12, 0, 0, 0);
    return toDateInputValue(d);
}

/** Calendar date for campaign day_index (1-based) from startsOn (YYYY-MM-DD). */
export function dateForCampaignDay(startsOn, dayIndex) {
    if (!startsOn) return null;
    const [y, m, d] = String(startsOn).split('-').map(Number);
    if (!y || !m || !d) return null;
    const date = new Date(y, m - 1, d + Math.max(0, (Number(dayIndex) || 1) - 1));
    return date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
}

export function initialCampaignState() {
    return {
        name: '',
        channelIds: [],
        dayCount: 0,
        days: [],
        startsOn: defaultStartsOn(),
        contentMode: 'ai_recent',
        focusPrompt: '',
        examplePreview: null,
        planMeta: null,
        briefNotes: null,
        briefComplete: false,
        images: [],
    };
}

export function campaignTotals(days = []) {
    return (days || []).reduce(
        (acc, day) => ({
            posts: acc.posts + Math.max(0, Number(day.posts) || 0),
            stories: acc.stories + Math.max(0, Number(day.stories) || 0),
        }),
        { posts: 0, stories: 0 },
    );
}

const TIME_RE = /^([01]?\d|2[0-3]):([0-5]\d)$/;

export function isValidTime(value) {
    return TIME_RE.test(String(value || '').trim());
}

export function validateStep(state, stepIndex) {
    const errors = {};
    if (stepIndex === 0) {
        if (!state.channelIds?.length) {
            errors.channels = 'Select at least one channel or page.';
        }
    }
    if (stepIndex === 1) {
        const count = Number(state.dayCount) || 0;
        if (count < 1 || count > MAX_CAMPAIGN_DAYS) {
            errors.dayCount = `Choose how many days AI should run (1–${MAX_CAMPAIGN_DAYS}).`;
        }
        const startsOn = String(state.startsOn || '').trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(startsOn)) {
            errors.startsOn = 'Pick a start date for the campaign.';
        } else if (startsOn < todayDateInput()) {
            errors.startsOn = 'Start date cannot be in the past.';
        }
        const totals = campaignTotals(state.days);
        if (totals.posts + totals.stories < 1) {
            errors.schedule = 'Plan at least one post or story across the campaign.';
        }
        (state.days || []).forEach((day, idx) => {
            const posts = Math.max(0, Number(day.posts) || 0);
            const times = (day.times || []).filter((t) => String(t).trim());
            if (posts > 0 && times.length < posts) {
                errors[`day${idx}times`] = `Day ${idx + 1}: add ${posts} post time${posts > 1 ? 's' : ''} (have ${times.length}).`;
            }
            times.forEach((t, ti) => {
                if (!isValidTime(t)) {
                    errors[`day${idx}time${ti}`] = `Day ${idx + 1}: invalid time "${t}". Use HH:mm.`;
                }
            });
        });
    }
    if (stepIndex === 2) {
        if (state.contentMode === 'ai_recent') {
            const len = state.focusPrompt?.trim().length || 0;
            if (len < MIN_PROMPT_LEN) {
                errors.prompt = `Focus prompt must be at least ${MIN_PROMPT_LEN} characters (${len}/${MIN_PROMPT_LEN}).`;
            }
        } else if (state.contentMode === 'product_images') {
            const totalPosts = campaignTotals(state.days).posts;
            const min = minImagesForPosts(totalPosts);
            const have = state.images?.length || 0;
            if (totalPosts < 1) {
                errors.images = 'Add at least one planned post in the schedule step.';
            } else if (have < min) {
                errors.images = `Upload at least ${min} images for ${totalPosts} posts (${have}/${min}, 80% coverage).`;
            }
        } else {
            errors.contentMode = 'Choose a content mode.';
        }
        if (!state.briefComplete) {
            errors.brief = 'Confirm the campaign brief (Confirm / Deny cards) before continuing.';
        }
    }
    return errors;
}

export function isStepValid(state, stepIndex) {
    return Object.keys(validateStep(state, stepIndex)).length === 0;
}

/** Content mode / prompt / images OK — brief cards may still be pending. */
export function contentBasicsValid(state) {
    const errors = validateStep(state, 2);
    return Object.keys(errors).every((key) => key === 'brief');
}

export function channelLabel(account) {
    return account?.name || account?.page_name || account?.username || `Channel ${account?.id || ''}`;
}

export function campaignPayload(state, assetIds = []) {
    const planMeta = {
        ...(state.planMeta || {}),
        ...(state.examplePreview?.plan_meta || {}),
    };
    const briefNotes = state.briefNotes
        || planMeta.brief_notes
        || planMeta.understanding
        || null;
    if (briefNotes && !planMeta.brief_notes) {
        planMeta.brief_notes = briefNotes;
    }
    return {
        name: state.name?.trim() || null,
        channel_ids: (state.channelIds || []).map((id) => Number(id)),
        day_count: Number(state.dayCount) || 0,
        starts_on: state.startsOn || defaultStartsOn(),
        days: (state.days || []).map((day) => ({
            day_index: Number(day.dayIndex),
            posts: Number(day.posts) || 0,
            stories: Number(day.stories) || 0,
            times: (day.times || []).map((t) => String(t).trim()).filter(Boolean),
        })),
        content_mode: state.contentMode,
        focus_prompt: state.contentMode === 'ai_recent' ? (state.focusPrompt || '') : null,
        asset_ids: state.contentMode === 'product_images' ? assetIds.map((id) => Number(id)) : [],
        plan_meta: Object.keys(planMeta).length ? planMeta : null,
        accepted_tease: state.examplePreview?.caption || null,
        brief_notes: briefNotes,
    };
}

export function walletErrorMessage(error, fallback) {
    if (error?.response?.status === 402) {
        const available = error.response?.data?.available_da;
        return available != null
            ? `Insufficient wallet balance (available: ${available} DA). Top up to keep chatting.`
            : (apiErrorMessage(error, 'Insufficient wallet balance.'));
    }
    return apiErrorMessage(error, fallback);
}

export function imageSizeAspectClass(imageSize) {
    if (imageSize === 'portrait_16_9') return 'aspect-[9/16]';
    if (imageSize === 'landscape_16_9' || imageSize === 'landscape_4_3') return 'aspect-video';
    return 'aspect-square';
}
