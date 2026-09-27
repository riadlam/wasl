export function connectedAccounts(socialAccounts = []) {
    return (socialAccounts || []).filter(
        (account) => account.platform && account.platform !== 'simulator' && account.status === 'connected',
    );
}

export function openConversations(conversations = []) {
    return (conversations || []).filter((c) => !c.blocked && c.status !== 'closed');
}

export function setupProgress({
    me,
    socialAccounts = [],
    conversations = [],
    aiEnabled = false,
    leadCount = 0,
    orderCount = 0,
    canView = () => false,
} = {}) {
    const items = [
        {
            id: 'shop',
            label: 'Name your shop',
            done: Boolean(String(me?.business?.name || '').trim()),
            view: 'settings',
        },
        {
            id: 'channel',
            label: 'Connect a channel',
            done: connectedAccounts(socialAccounts).length > 0,
            view: 'channels',
        },
        {
            id: 'inbox',
            label: 'Get your first conversation',
            done: openConversations(conversations).length > 0 || (conversations || []).length > 0,
            view: 'inbox',
        },
    ];

    if (canView('agents.view')) {
        items.push({
            id: 'agent',
            label: 'Turn the AI agent on',
            done: Boolean(aiEnabled),
            view: 'agents',
        });
    }
    if (canView('contacts.view')) {
        items.push({
            id: 'lead',
            label: 'Capture a lead',
            done: Number(leadCount) > 0,
            view: 'leads',
        });
    }
    if (canView('orders.view')) {
        items.push({
            id: 'order',
            label: 'Capture an order',
            done: Number(orderCount) > 0,
            view: 'orders',
        });
    }

    const done = items.filter((item) => item.done).length;

    return { items, done, total: items.length };
}

export function buildAlerts({ conversations = [], pendingOrders = [] } = {}) {
    const open = openConversations(conversations);
    const seen = new Set();
    const alerts = [];

    for (const chat of open.filter((c) => c.unreplied)) {
        seen.add(String(chat.id));
        alerts.push({
            id: `chat-${chat.id}`,
            kind: 'chat',
            title: 'Unreplied',
            name: chat.name,
            body: chat.preview || 'Waiting on a reply.',
            time: chat.time || '',
            conversation_id: chat.id,
            conversation_status: chat.status,
        });
    }

    for (const chat of open.filter((c) => c.lifecycle === 'hot' && !seen.has(String(chat.id)))) {
        alerts.push({
            id: `hot-${chat.id}`,
            kind: 'chat',
            title: 'Hot lead',
            name: chat.name,
            body: chat.preview || 'Marked hot from inbox.',
            time: chat.time || '',
            conversation_id: chat.id,
            conversation_status: chat.status,
        });
    }

    for (const order of pendingOrders || []) {
        alerts.push({
            id: `order-${order.id}`,
            kind: 'order',
            title: 'Pending order',
            name: order.order_number || order.name,
            body: [order.name, order.total != null ? `${order.total} ${order.currency || 'DZD'}` : '']
                .filter(Boolean)
                .join(' · ') || 'Needs fulfilment.',
            time: order.placed_at || '',
        });
    }

    return alerts.slice(0, 12);
}

export function weekActivity(conversations = []) {
    const labels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const start = new Date();
    start.setHours(0, 0, 0, 0);
    start.setDate(start.getDate() - 6);

    const buckets = Array.from({ length: 7 }, (_, index) => {
        const date = new Date(start);
        date.setDate(start.getDate() + index);
        return {
            key: date.toDateString(),
            day: labels[date.getDay()],
            value: 0,
        };
    });

    for (const conversation of conversations || []) {
        if (!conversation.sortAt) continue;
        const at = new Date(conversation.sortAt);
        if (Number.isNaN(at.getTime())) continue;
        at.setHours(0, 0, 0, 0);
        const bucket = buckets.find((row) => row.key === at.toDateString());
        if (bucket) bucket.value += 1;
    }

    return buckets;
}
