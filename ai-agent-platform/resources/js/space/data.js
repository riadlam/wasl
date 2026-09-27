export const shop = {
    name: 'Maison Amira',
    owner: 'Amira',
    letter: 'W',
    trialEnds: 'Sep 21, 2026',
    trialDays: 7,
};

export const navItems = [
    { id: 'dashboard', label: 'Dashboard' },
    { id: 'inbox', label: 'Inbox', permission: 'inbox.view' },
    { id: 'contacts', label: 'Contacts', permission: 'contacts.view' },
    { id: 'channels', label: 'Channels', permission: 'settings.view' },
    { id: 'products', label: 'Products', permission: 'products.view' },
    { id: 'orders', label: 'Orders', permission: 'orders.view' },
    { id: 'agents', label: 'AI Agents', permission: 'agents.view' },
    { id: 'team', label: 'Team', permission: 'team.manage' },
    { id: 'broadcasts', label: 'Broadcasts' },
    { id: 'workflows', label: 'Workflows' },
    { id: 'reports', label: 'Reports' },
    { id: 'settings', label: 'Settings', permission: 'settings.view' },
];

export const inboxFilters = [
    { id: 'all', label: 'All' },
    { id: 'mine', label: 'Mine' },
    { id: 'collab', label: 'Collaborations' },
    { id: 'unassigned', label: 'Unassigned' },
    { id: 'calls', label: 'Incoming Calls' },
    { id: 'agents', label: 'Create AI Agent', badge: 'Beta' },
];

export const lifecycles = [
    { id: 'new', label: 'New Lead', emoji: '🆕' },
    { id: 'hot', label: 'Hot Lead', emoji: '🔥' },
    { id: 'payment', label: 'Payment', emoji: '💵' },
    { id: 'customer', label: 'Customer', emoji: '🤩' },
];

export const channels = ['WhatsApp', 'Instagram', 'Facebook', 'TikTok'];

export const checklist = [
    { id: 'shop', label: 'Name your shop', done: true },
    { id: 'channel', label: 'Connect a channel', done: true },
    { id: 'reply', label: 'Let the agent draft a reply', done: false },
    { id: 'lead', label: 'Tag your first hot lead', done: false },
    { id: 'post', label: 'Generate a carousel', done: false },
];

export const notifications = [
    { id: 'n1', title: 'Hot lead waiting', body: 'Amira asked about the beige set on Instagram.', time: '2m' },
    { id: 'n2', title: 'Unreplied WhatsApp', body: 'Sara is waiting on a COD answer for Oran.', time: '18m' },
];

export const contacts = [
    { id: 'p1', name: 'Amira B.', city: 'Oran', channel: 'Instagram', lifecycle: 'Hot', phone: '+213 555 01 22' },
    { id: 'p2', name: 'Karim M.', city: 'Alger Centre', channel: 'WhatsApp', lifecycle: 'Payment', phone: '+213 555 44 90' },
    { id: 'p3', name: 'Lina K.', city: 'Constantine', channel: 'Facebook', lifecycle: 'New', phone: '+213 555 77 13' },
    { id: 'p4', name: 'Yacine R.', city: 'Constantine', channel: 'WhatsApp', lifecycle: 'Customer', phone: '+213 555 12 08' },
    { id: 'p5', name: 'Sara H.', city: 'Oran', channel: 'Instagram', lifecycle: 'Hot', phone: '+213 555 33 61' },
    { id: 'p6', name: 'Nadir S.', city: 'Blida', channel: 'TikTok', lifecycle: 'New', phone: '+213 555 90 40' },
];

export const broadcasts = [
    { id: 'b1', name: 'Aid weekend drop', status: 'Draft', audience: 'Hot leads · Oran', when: 'Not scheduled' },
];

export const workflows = [
    { id: 'w1', name: 'New IG comment → lead tag', status: 'On', trigger: 'Tags New Lead, then asks the agent to reply.' },
];

export const workflowCategories = [
    { id: 'all', label: 'All templates' },
    { id: 'auto', label: 'Auto-reply' },
    { id: 'routing', label: 'Routing' },
    { id: 'shop', label: 'Shop processes' },
    { id: 'reports', label: 'Reports' },
    { id: 'whatsapp', label: 'WhatsApp ads' },
    { id: 'tiktok', label: 'TikTok ads' },
];

const trigger = (body) => ({ kind: 'trigger', title: 'Trigger', body, color: '#e91e63' });
const action = (title, body, color = '#03a9f4') => ({ kind: 'action', title, body, color });
const chip = { kind: 'chip', label: 'Success' };
const message = (text) => ({
    kind: 'message',
    title: 'Send message',
    color: '#00897b',
    message: text,
});

export const workflowTemplates = [
    {
        id: 'wa-fitting',
        category: 'whatsapp',
        title: 'Book a fitting from a WhatsApp ad',
        body: 'Send a calendar link so a shopper who clicked your WhatsApp ad can pick a fitting time in Oran or Alger.',
        tone: 'teal',
        summary: 'When someone starts a chat from a WhatsApp ad, send a fitting link and keep the conversation with you.',
        benefits: [
            'Turn ad clicks into a booked fitting the same day.',
            'Offer Oran and Alger times without typing the link again.',
        ],
        how: [
            'A shopper opens a chat from the ad.',
            'The fitting link is sent.',
            'The chat stays assigned to you.',
        ],
        steps: [
            trigger('WhatsApp ad chat'),
            action('Open conversation'),
            action('Assign to you', 'Select team', '#4caf50'),
            chip,
            message('Hi $contact.firstname, pick a fitting time in Oran or Alger and we will hold the piece.'),
        ],
    },
    {
        id: 'wa-assign',
        category: 'whatsapp',
        title: 'Assign the ad chat to you',
        body: 'Hand the conversation to you whenever someone starts a chat from a WhatsApp ad, so a hot lead is not left unassigned.',
        tone: 'accent',
        summary: 'Every new WhatsApp ad chat is assigned to you so a hot lead is never left in the queue.',
        benefits: [
            'No ad lead sits unassigned.',
            'You see the chat as soon as it opens.',
        ],
        how: [
            'A shopper starts a chat from the ad.',
            'The conversation opens.',
            'It is assigned to you.',
        ],
        steps: [
            trigger('WhatsApp ad chat'),
            action('Open conversation'),
            action('Assign to you', 'Select team', '#4caf50'),
            chip,
        ],
    },
    {
        id: 'wa-lookbook',
        category: 'whatsapp',
        title: 'Send the lookbook, then hand it to you',
        body: 'Share sizes and the lookbook, then pass interested shoppers from the ad to you for the close.',
        tone: 'apricot',
        summary: 'Send sizes and the lookbook, then hand interested shoppers from the ad to you.',
        benefits: [
            'Shoppers see the lookbook before they wait on you.',
            'You only step in once they are interested.',
        ],
        how: [
            'A shopper writes from the ad.',
            'The lookbook and sizes are sent.',
            'The chat is assigned to you.',
        ],
        steps: [
            trigger('WhatsApp ad chat'),
            action('Open conversation'),
            message('Hi $contact.firstname, here is the lookbook and the sizes we have in stock.'),
            chip,
            action('Assign to you', 'Select team', '#4caf50'),
        ],
    },
    {
        id: 'wa-payment',
        category: 'whatsapp',
        title: 'When payment lands, mark the lead',
        body: 'Move the contact to Customer when a COD or transfer is confirmed, so the week’s numbers stay honest.',
        tone: 'ink',
        summary: 'When a COD or transfer is confirmed, mark the contact as Customer and note the chat.',
        benefits: [
            'Paid orders leave the hot-lead list.',
            'The week’s numbers stay honest.',
        ],
        how: [
            'Payment is confirmed in the chat.',
            'The lifecycle becomes Customer.',
            'You get a short note.',
        ],
        steps: [
            trigger('Payment confirmed'),
            action('Mark as Customer', 'Lifecycle', '#4caf50'),
            chip,
            message('Payment received. $contact.firstname is now a customer.'),
        ],
    },
    {
        id: 'auto-hours',
        category: 'auto',
        title: 'After-hours salam',
        body: 'Reply in Darija when the shop is closed, with delivery cities and when you will be back.',
        tone: 'teal',
        summary: 'When the shop is closed, reply in Darija with the cities you deliver to and when you will be back.',
        benefits: [
            'Night messages still get a salam.',
            'Shoppers know Oran, Alger, and Constantine hours.',
        ],
        how: [
            'A message arrives after closing.',
            'The after-hours reply is sent.',
            'The chat waits for you in the morning.',
        ],
        steps: [
            trigger('Message after hours'),
            action('Open conversation'),
            chip,
            message('Salam $contact.firstname, the shop is closed. We deliver Oran, Alger, and Constantine — we will reply in the morning.'),
        ],
    },
    {
        id: 'auto-size',
        category: 'auto',
        title: 'Size and stock answer',
        body: 'Answer beige, M, and “kayn?” questions from the catalog, then ask them to confirm before you hold the piece.',
        tone: 'accent',
        summary: 'Answer size and stock questions from the catalog, then ask the shopper to confirm before you hold the piece.',
        benefits: [
            'Beige and M questions do not wait on you.',
            'Nothing is held until they confirm.',
        ],
        how: [
            'A size or stock question arrives.',
            'The catalog answer is sent.',
            'You are asked to confirm the hold.',
        ],
        steps: [
            trigger('Size or stock question'),
            action('Open conversation'),
            chip,
            message('Hi $contact.firstname, beige M is in stock. Reply yes and we will hold it until tonight.'),
        ],
    },
    {
        id: 'auto-cod',
        category: 'auto',
        title: 'COD for Oran and Alger',
        body: 'Send the cash-on-delivery fee and the next delivery window as soon as someone asks about COD.',
        tone: 'apricot',
        summary: 'Send the cash-on-delivery fee and the next window as soon as someone asks about COD.',
        benefits: [
            'The fee is the same every time.',
            'Oran and Alger windows go out without a manual reply.',
        ],
        how: [
            'Someone asks about COD.',
            'The fee and window are sent.',
            'The chat stays open for you.',
        ],
        steps: [
            trigger('COD question'),
            action('Open conversation'),
            chip,
            message('Oui $contact.firstname, COD is 400 DA in Oran and Alger. Next window is tomorrow before 18h.'),
        ],
    },
    {
        id: 'route-missed',
        category: 'routing',
        title: 'Follow up on a missed call',
        body: 'Automatically follow up with shoppers who called and reached no one, so every missed call gets a reply.',
        tone: 'coral',
        summary: 'Send a follow-up when a shopper calls and reaches no one, then assign the chat to you.',
        benefits: [
            'Reach them before they message another shop.',
            'Assign every missed call to you.',
            'Stop tracking calls by hand.',
        ],
        how: [
            'A shopper’s call goes unanswered.',
            'A conversation opens in the inbox.',
            'The chat is assigned to you.',
            'A follow-up message is sent.',
        ],
        steps: [
            trigger('Call ended'),
            action('Open conversation', null, '#03a9f4'),
            action('Assign to you', 'Select team', '#4caf50'),
            chip,
            message("Hi $contact.firstname, we missed your call. We'll get back to you as soon as possible."),
        ],
    },
    {
        id: 'route-unassigned',
        category: 'routing',
        title: 'Unassigned chat to you',
        body: 'If a new message sits unassigned for 10 minutes, assign it to you and mark it unreplied.',
        tone: 'accent',
        summary: 'If a new message sits unassigned for 10 minutes, assign it to you and mark it unreplied.',
        benefits: [
            'Quiet chats do not vanish in the queue.',
            'Unreplied stays visible on your list.',
        ],
        how: [
            'A message has no assignee for 10 minutes.',
            'The chat is assigned to you.',
            'It is marked unreplied.',
        ],
        steps: [
            trigger('Unassigned for 10 minutes'),
            action('Open conversation'),
            action('Assign to you', 'Select team', '#4caf50'),
            chip,
        ],
    },
    {
        id: 'route-hot',
        category: 'routing',
        title: 'Hot lead stays with you',
        body: 'When the agent tags Hot Lead, keep the chat on you and skip the shared queue.',
        tone: 'coral',
        summary: 'When a chat is tagged Hot Lead, keep it on you and skip the shared queue.',
        benefits: [
            'Hot leads are not shared out.',
            'You see the tag as soon as it lands.',
        ],
        how: [
            'The agent tags Hot Lead.',
            'The chat stays assigned to you.',
            'It leaves the shared queue.',
        ],
        steps: [
            trigger('Tagged Hot Lead'),
            action('Assign to you', 'Select team', '#4caf50'),
            chip,
            message('Hot lead kept with you. $contact.firstname is waiting.'),
        ],
    },
    {
        id: 'shop-comment',
        category: 'shop',
        title: 'Instagram comment → lead tag',
        body: 'Tag New Lead when a comment mentions a product, then ask the agent to draft a reply.',
        tone: 'teal',
        summary: 'Tag New Lead when an Instagram comment mentions a product, then draft a reply.',
        benefits: [
            'Product comments become leads.',
            'The reply is drafted, not sent, until you say so.',
        ],
        how: [
            'A comment mentions a product.',
            'The contact is tagged New Lead.',
            'A reply is drafted for you.',
        ],
        steps: [
            trigger('Instagram comment'),
            action('Tag New Lead', 'Lifecycle', '#4caf50'),
            chip,
            message('Draft a reply for $contact.firstname about the piece they mentioned.'),
        ],
    },
    {
        id: 'shop-parcel',
        category: 'shop',
        title: 'Parcel received → customer',
        body: 'When a shopper says the parcel arrived, mark them Customer and offer the matching piece.',
        tone: 'apricot',
        summary: 'When a shopper says the parcel arrived, mark them Customer and offer the matching piece.',
        benefits: [
            'Delivered orders become customers.',
            'The matching piece is offered while they are happy.',
        ],
        how: [
            'The shopper says the parcel arrived.',
            'Lifecycle becomes Customer.',
            'A short offer is sent.',
        ],
        steps: [
            trigger('Parcel received'),
            action('Mark as Customer', 'Lifecycle', '#4caf50'),
            chip,
            message('Glad it arrived, $contact.firstname. The matching piece is still in stock if you want it.'),
        ],
    },
    {
        id: 'reports-friday',
        category: 'reports',
        title: 'Friday shop note',
        body: 'Send you a short note each Friday: open chats, hot leads, and unreplied WhatsApp.',
        tone: 'ink',
        summary: 'Each Friday, send you a short note of open chats, hot leads, and unreplied WhatsApp.',
        benefits: [
            'The week closes with a readable note.',
            'Unreplied WhatsApp is not a surprise on Saturday.',
        ],
        how: [
            'Friday arrives.',
            'Open chats and hot leads are counted.',
            'The note is sent to you.',
        ],
        steps: [
            trigger('Friday'),
            action('Count the week', 'Reports', '#03a9f4'),
            chip,
            message('This week: open chats, hot leads, and unreplied WhatsApp are ready for you.'),
        ],
    },
    {
        id: 'reports-city',
        category: 'reports',
        title: 'Oran vs Alger this week',
        body: 'Compare conversations by city so you see which delivery zone is asking more.',
        tone: 'accent',
        summary: 'Compare conversations by city so you see whether Oran or Alger is asking more.',
        benefits: [
            'Delivery zones are visible without a spreadsheet.',
            'You can stock the city that is writing more.',
        ],
        how: [
            'The week’s chats are grouped by city.',
            'Oran and Alger are compared.',
            'The note is sent to you.',
        ],
        steps: [
            trigger('End of week'),
            action('Compare cities', 'Reports', '#03a9f4'),
            chip,
            message('Oran and Alger this week — the busier city is in the note.'),
        ],
    },
    {
        id: 'tt-link',
        category: 'tiktok',
        title: 'TikTok comment → WhatsApp link',
        body: 'When someone asks for the link under a video, send the WhatsApp thread for that product.',
        tone: 'ink',
        summary: 'When someone asks for the link under a TikTok video, send the WhatsApp thread for that product.',
        benefits: [
            'Comments do not die on the video.',
            'The shopper lands in WhatsApp with the right piece.',
        ],
        how: [
            'A comment asks for the link.',
            'The WhatsApp thread is sent.',
            'The chat is tagged New Lead.',
        ],
        steps: [
            trigger('TikTok comment'),
            action('Open conversation'),
            chip,
            message('Hi $contact.firstname, here is the WhatsApp thread for the piece in the video.'),
        ],
    },
    {
        id: 'tt-assign',
        category: 'tiktok',
        title: 'TikTok ad chat to you',
        body: 'Assign a new TikTok messaging-ad conversation to you and tag it New Lead.',
        tone: 'teal',
        summary: 'Assign a new TikTok ad conversation to you and tag it New Lead.',
        benefits: [
            'TikTok ad chats do not sit unassigned.',
            'They arrive already tagged New Lead.',
        ],
        how: [
            'A shopper writes from a TikTok ad.',
            'The chat is assigned to you.',
            'It is tagged New Lead.',
        ],
        steps: [
            trigger('TikTok ad chat'),
            action('Open conversation'),
            action('Assign to you', 'Select team', '#4caf50'),
            chip,
        ],
    },
];

export const weekly = [
    { day: 'Mon', value: 18 },
    { day: 'Tue', value: 24 },
    { day: 'Wed', value: 21 },
    { day: 'Thu', value: 32 },
    { day: 'Fri', value: 41 },
    { day: 'Sat', value: 47 },
    { day: 'Sun', value: 29 },
];

export const seedConversations = [
    {
        id: 'c1',
        name: 'Amira B.',
        city: 'Oran',
        channel: 'Instagram',
        assignment: 'mine',
        lifecycle: 'hot',
        unreplied: true,
        blocked: false,
        status: 'open',
        sortAt: 70,
        time: '2m',
        preview: 'Kayn f beige, taille M?',
        tags: ['Hot', 'Fashion', 'Darija'],
        calls: [],
        messages: [
            { id: 'm1', from: 'them', text: 'Salam! Kayn f beige, taille M? Nbghi nconfirmi today 🧡' },
            { id: 'm2', from: 'agent', text: 'Oui Amira — beige M is in stock in Oran. I can hold it until tonight if you confirm.' },
        ],
    },
    {
        id: 'c2',
        name: 'Karim M.',
        city: 'Alger Centre',
        channel: 'WhatsApp',
        assignment: 'mine',
        lifecycle: 'payment',
        unreplied: false,
        blocked: false,
        status: 'open',
        sortAt: 60,
        time: '14m',
        preview: 'COD for Alger centre?',
        tags: ['Payment', 'COD'],
        calls: [{ id: 'call1', when: 'Today · 11:02', length: '3m 12s', result: 'Answered', note: 'Confirmed COD, waiting for size chart.' }],
        messages: [
            { id: 'm3', from: 'them', text: 'COD pour Alger centre? Et livraison demain possible?' },
            { id: 'm4', from: 'me', text: 'Oui Karim, COD Alger centre 400 DA. Demain avant 18h si tu confirmes maintenant.' },
        ],
    },
    {
        id: 'c3',
        name: 'Sara H.',
        city: 'Oran',
        channel: 'Instagram',
        assignment: 'mine',
        lifecycle: 'hot',
        unreplied: true,
        blocked: false,
        status: 'open',
        sortAt: 50,
        time: '18m',
        preview: 'The green set, still available?',
        tags: ['Hot', 'Oran'],
        calls: [],
        messages: [
            { id: 'm5', from: 'them', text: 'Loved the story — is the green set still available?' },
        ],
    },
    {
        id: 'c4',
        name: 'Lina K.',
        city: 'Constantine',
        channel: 'Facebook',
        assignment: 'unassigned',
        lifecycle: 'new',
        unreplied: true,
        blocked: false,
        status: 'open',
        sortAt: 40,
        time: '31m',
        preview: 'Prix du set orange?',
        tags: ['New', 'Facebook'],
        calls: [],
        messages: [
            { id: 'm6', from: 'them', text: 'Prix du set orange? Vous livrez Constantine?' },
        ],
    },
    {
        id: 'c5',
        name: 'Yacine R.',
        city: 'Constantine',
        channel: 'WhatsApp',
        assignment: 'collab',
        lifecycle: 'customer',
        unreplied: false,
        blocked: false,
        status: 'open',
        sortAt: 30,
        time: '1h',
        preview: 'Received the parcel, merci',
        tags: ['Customer'],
        calls: [{ id: 'call2', when: 'Yesterday · 16:40', length: '1m 04s', result: 'Missed', note: 'Called back on WhatsApp.' }],
        messages: [
            { id: 'm7', from: 'them', text: 'Voice note: parcel arrived, the beige fits. Merci 🌿' },
            { id: 'm8', from: 'me', text: 'So glad it fits. I’ll note you as a customer — message us if you want the matching scarf.' },
        ],
    },
    {
        id: 'c6',
        name: 'Nadir S.',
        city: 'Blida',
        channel: 'TikTok',
        assignment: 'unassigned',
        lifecycle: 'new',
        unreplied: true,
        blocked: false,
        status: 'open',
        sortAt: 20,
        time: '2h',
        preview: 'Link for the black sneakers?',
        tags: ['New', 'TikTok'],
        calls: [],
        messages: [
            { id: 'm9', from: 'them', text: 'Saw the video — send the WhatsApp link for the black sneakers?' },
        ],
    },
    {
        id: 'c7',
        name: 'Blocked shopper',
        city: 'Unknown',
        channel: 'Instagram',
        assignment: 'mine',
        lifecycle: 'new',
        unreplied: false,
        blocked: true,
        status: 'open',
        sortAt: 10,
        time: '3d',
        preview: 'Spam link',
        tags: ['Blocked'],
        calls: [],
        messages: [
            { id: 'm10', from: 'them', text: 'Win a prize, click this link…' },
        ],
    },
];
