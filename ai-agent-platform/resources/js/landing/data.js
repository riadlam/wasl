export const brand = {
    name: 'Wasl',
    nameAr: 'وصل',
    tagline: 'Your shop’s AI inbox — messages, leads, and content in one place.',
};

export const nav = {
    product: [
        { title: 'Unified inbox', desc: 'Instagram, Facebook and WhatsApp in one thread.', href: '#inbox' },
        { title: 'Lead scoring', desc: 'Tag hot buyers automatically, skip window shoppers.', href: '#journey' },
        { title: 'AI agent', desc: 'Answers questions, writes posts, pulls your stats.', href: '#agent' },
        { title: 'Content studio', desc: 'Carousels, captions and campaign ideas on demand.', href: '#agent' },
        { title: 'Shop analytics', desc: 'Ask how last week went — in plain language.', href: '#agent' },
    ],
    industries: [
        { title: 'Fashion & apparel', href: '#stories' },
        { title: 'Beauty & cosmetics', href: '#stories' },
        { title: 'Electronics', href: '#stories' },
        { title: 'Food & local brands', href: '#stories' },
        { title: 'Home & living', href: '#stories' },
    ],
};

export const hero = {
    eyebrow: 'Built for Algerian e-commerce',
    headline: 'Sell from every chat — without losing a single customer.',
    sub: 'Wasl unifies Instagram, Facebook and WhatsApp for your shop. Classify leads, let an AI agent reply, generate carousel posts, and ask about yesterday’s numbers — all from one joyful dashboard.',
    primary: 'Start free',
    secondary: 'See how it works',
};

export const channels = [
    'WhatsApp',
    'Instagram',
    'Facebook',
    'Messenger',
    'TikTok',
    'Comments',
    'Voice notes',
    'Product DMs',
];

export const journey = [
    {
        id: 'capture',
        title: 'Capture',
        heading: 'Every DM becomes a clean lead',
        body: 'Shops in Algeria live on Instagram, Facebook and WhatsApp. Wasl pulls them into one queue so a comment, a story reply, or a voice note never disappears into a personal phone.',
        points: [
            'One profile per customer, across every channel',
            'Auto-tags for city, product interest, and language',
            'Nothing stuck in a staff member’s personal chat',
        ],
        mock: 'inbox',
    },
    {
        id: 'convert',
        title: 'Convert',
        heading: 'AI replies. You close.',
        body: 'The agent answers size, stock, delivery and payment questions in Darija, French or Arabic. Hot buyers get scored and handed to you — browsers stay handled without burning your evening.',
        points: [
            'Instant answers on hours you cannot staff',
            'Lead scores so you know who is ready to buy',
            'You jump in the moment a conversation is worth it',
        ],
        mock: 'leads',
    },
    {
        id: 'retain',
        title: 'Retain',
        heading: 'Content and stats, in the same chat',
        body: 'Ask the agent to draft a carousel, compare last week’s leads, or nudge customers who never checked out. Follow-up stops being a spreadsheet you never open.',
        points: [
            'Carousel posts with captions, ready to publish',
            'Ask “how did Oran do this week?” in plain language',
            'Gentle reminders that feel like a shopkeeper, not a blast',
        ],
        mock: 'content',
    },
];

export const inbox = {
    heading: 'Every channel. One thread.',
    body: 'A customer who DMs on Instagram and then voice-notes you on WhatsApp is still one person. Wasl keeps the history together so your reply never sounds lost.',
};

export const agent = {
    heading: 'An agent that actually does the work',
    body: 'Not a FAQ bot. Ask it anything about your shop — then put it to work generating posts, classifying leads, and reading your numbers out loud.',
    chips: [
        {
            label: 'Generate a carousel for our summer dresses',
            reply: 'Drafted a 5-slide carousel: hook, 3 looks, and a WhatsApp CTA. Captions in French + Darija. Want me to swap slide 3 for the orange set?',
        },
        {
            label: 'How many hot leads this week?',
            reply: '47 new conversations. 19 scored hot — mostly Algiers and Oran, asking about delivery before Aid. 8 are waiting on your size chart.',
        },
        {
            label: 'Reply to a size question on WhatsApp',
            reply: '“Salam 🌿 The beige set runs true to size. For 1m68 / 58kg I’d go M. I can hold one until tomorrow if you want — nchallah it fits perfectly.”',
        },
    ],
};

export const stats = [
    { value: 1, suffix: '', label: 'inbox for every channel' },
    { value: 24, suffix: '/7', label: 'AI replies while you pack orders' },
    { value: 3, suffix: '×', label: 'faster follow-up on hot leads' },
    { value: 2, suffix: '', label: 'languages your customers already use' },
];

export const stories = [
    {
        quote: 'I was answering DMs at 1am between packing boxes. Now Wasl replies, tags the serious buyers, and I only open the ones that matter.',
        name: 'Amira B.',
        role: 'Fashion boutique · Oran',
    },
    {
        quote: 'Lead tags finally tell me who’s ready to buy versus who’s just browsing. My evenings belong to my family again.',
        name: 'Karim M.',
        role: 'Electronics · Alger Centre',
    },
    {
        quote: 'I asked the agent for a carousel about our new serum. It wrote the copy, laid out five slides, and suggested a WhatsApp CTA that actually converted.',
        name: 'Lina K.',
        role: 'Cosmetics · Constantine',
    },
];

export const why = [
    {
        title: 'Designed for DZ shops',
        body: 'Instagram commerce, WhatsApp-first buyers, cash on delivery, and cities that matter — Algiers, Oran, Constantine, and beyond.',
    },
    {
        title: 'Speaks how your customers speak',
        body: 'Darija-ready replies with French and Arabic when it fits. No stiff corporate tone that makes a boutique feel like a bank.',
    },
    {
        title: 'An agent that ships work',
        body: 'Generate carousels, classify leads, pull stats, draft replies. You stay in control — the agent does the heavy lifting.',
    },
    {
        title: 'You grow with it',
        body: 'Start with the inbox. Add the agent. Add content. No black-box enterprise stack — just a shop dashboard that stays joyful as you scale.',
    },
];

export const finalCta = {
    heading: 'Give your shop an inbox that never sleeps.',
    body: 'Join Algerian founders who want their DMs, leads, and content in one calm place.',
    primary: 'Start free',
    secondary: 'Talk to us',
};

export const footer = {
    blurb: 'Wasl (وصل) connects Algerian shops to every customer conversation — with an AI agent that replies, creates, and reports.',
    columns: [
        {
            title: 'Product',
            links: [
                { label: 'Inbox', href: '#inbox' },
                { label: 'AI agent', href: '#agent' },
                { label: 'Leads', href: '#journey' },
                { label: 'Pricing', href: '#cta' },
            ],
        },
        {
            title: 'Channels',
            links: [
                { label: 'WhatsApp', href: '#inbox' },
                { label: 'Instagram', href: '#inbox' },
                { label: 'Facebook', href: '#inbox' },
                { label: 'TikTok', href: '#inbox' },
            ],
        },
        {
            title: 'Company',
            links: [
                { label: 'Stories', href: '#stories' },
                { label: 'Why Wasl', href: '#why' },
                { label: 'Contact', href: '#cta' },
            ],
        },
    ],
};
