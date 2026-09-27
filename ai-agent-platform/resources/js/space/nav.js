const VIEWS = new Set([
    'inbox',
    'dm_automations',
    'posts',
    'schedule',
    'dashboard',
    'leads',
    'contacts',
    'agents',
    'campaigns',
    'broadcasts',
    'workflows',
    'reports',
    'channels',
    'settings',
    'products',
    'delivery',
    'payments',
    'orders',
    'team',
]);

/** Canonical path for each workspace view (refresh-safe). */
const VIEW_PATHS = {
    dashboard: '/space/dashboard',
    inbox: '/space/inbox',
    dm_automations: '/space/inbox/automations',
    posts: '/space/posts',
    schedule: '/space/posts/schedule',
    contacts: '/space/contacts',
    leads: '/space/contacts/leads',
    products: '/space/products',
    delivery: '/space/products/delivery',
    payments: '/space/products/payments',
    orders: '/space/orders',
    agents: '/space/agents',
    campaigns: '/space/campaigns',
    team: '/space/team',
    broadcasts: '/space/broadcasts',
    workflows: '/space/workflows',
    reports: '/space/reports',
    channels: '/space/channels',
    settings: '/space/settings',
};

const PATH_TO_VIEW = Object.entries(VIEW_PATHS)
    .sort((a, b) => b[1].length - a[1].length)
    .map(([view, path]) => ({ view, path }));

export function isWorkspaceView(id) {
    return VIEWS.has(id);
}

export function pathForView(id) {
    return VIEW_PATHS[id] || '/space/inbox';
}

/** SPA URL for a workspace tab — never triggers a full document load by itself. */
export function workspaceHref(id, params = {}) {
    const path = pathForView(id);
    const search = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
        if (value == null || value === '') return;
        search.set(key, String(value));
    });
    const query = search.toString();
    return query ? `${path}?${query}` : path;
}

function viewFromPathname(pathname = window.location.pathname) {
    const raw = (pathname || '/').replace(/\/+$/, '') || '/';
    if (raw === '/space' || raw === '/') {
        return 'inbox';
    }
    if (raw === '/workflow' || raw === '/workflows') {
        return 'workflows';
    }
    for (const row of PATH_TO_VIEW) {
        if (raw === row.path) {
            return row.view;
        }
    }
    return null;
}

export function viewFromLocation(search = window.location.search, pathname = window.location.pathname) {
    const fromPath = viewFromPathname(pathname);
    if (fromPath && VIEWS.has(fromPath)) {
        return fromPath;
    }

    const queryView = new URLSearchParams(search).get('view');
    if (queryView && VIEWS.has(queryView)) {
        return queryView;
    }

    return 'inbox';
}

export function workflowNavFromLocation(search = window.location.search) {
    const params = new URLSearchParams(search);
    const wf = params.get('wf');
    const create = params.get('create');
    const accountId = params.get('account_id');
    const platformPostId = params.get('platform_post_id');
    if (!wf && !create) {
        return null;
    }
    return {
        wf: wf || null,
        create: create || null,
        account_id: accountId || null,
        platform_post_id: platformPostId || null,
    };
}

/**
 * Update the address bar without reloading. React state should be updated by the caller.
 */
export function pushWorkspaceView(id, params = {}) {
    if (!VIEWS.has(id)) return;
    const href = workspaceHref(id, params);
    const here = `${window.location.pathname}${window.location.search}`;
    if (here === href) return;
    window.history.pushState({ view: id, ...params }, '', href);
}

export function replaceWorkspaceView(id, params = {}) {
    if (!VIEWS.has(id)) return;
    window.history.replaceState({ view: id, ...params }, '', workspaceHref(id, params));
}

/**
 * Rewrite legacy `/space?view=…` (and /workflow) into canonical `/space/…` paths.
 * Call once on boot before React mounts.
 */
export function normalizeWorkspaceLocation() {
    const { pathname, search } = window.location;
    const params = new URLSearchParams(search);

    if (pathname === '/workflow' || pathname === '/workflows') {
        const next = new URLSearchParams(params);
        next.delete('view');
        replaceWorkspaceView('workflows', Object.fromEntries(next.entries()));
        return;
    }

    const queryView = params.get('view');
    if (pathname === '/space' || pathname === '/space/') {
        if (queryView && VIEWS.has(queryView)) {
            params.delete('view');
            replaceWorkspaceView(queryView, Object.fromEntries(params.entries()));
            return;
        }
        if (!queryView) {
            replaceWorkspaceView('inbox');
        }
        return;
    }

    // Already on a /space/... path but still carrying ?view= — drop the duplicate.
    if (pathname.startsWith('/space/') && queryView) {
        params.delete('view');
        const view = viewFromPathname(pathname) || queryView;
        replaceWorkspaceView(view, Object.fromEntries(params.entries()));
    }
}

/** @deprecated Use pushWorkspaceView + React setState — kept as alias for call sites. */
export function goToView(id) {
    pushWorkspaceView(id);
}

export function initialView() {
    return viewFromLocation();
}

export { VIEWS, VIEW_PATHS };
