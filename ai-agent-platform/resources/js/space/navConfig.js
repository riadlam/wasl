import {
    Bell,
    Bot,
    Boxes,
    CircleHelp,
    GitBranch,
    Images,
    Inbox,
    LayoutDashboard,
    Megaphone,
    Search,
    Settings,
    Share2,
    ShoppingBag,
    Users,
    UsersRound,
} from 'lucide-react';

/** Single source of truth for workspace navigation — icon + label tabs everywhere. */
export const workspaceNav = [
    { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { id: 'inbox', label: 'Inbox', icon: Inbox, permission: 'inbox.view' },
    { id: 'posts', label: 'Posts', icon: Images, permission: 'inbox.view' },
    { id: 'contacts', label: 'Contacts', icon: Users, permission: 'contacts.view' },
    { id: 'agents', label: 'AI Agents', icon: Bot, permission: 'agents.view', featured: true },
    { id: 'campaigns', label: 'AI Campaigns', icon: Megaphone, permission: 'agents.view', featured: true },
    { id: 'channels', label: 'Channels', icon: Share2, permission: 'settings.view' },
    { id: 'products', label: 'Products', icon: Boxes, permission: 'products.view' },
    { id: 'orders', label: 'Orders', icon: ShoppingBag, permission: 'orders.view' },
    { id: 'team', label: 'Team', icon: UsersRound, permission: 'team.manage' },
    { id: 'workflows', label: 'Workflows', icon: GitBranch, permission: 'settings.view' },
    { id: 'settings', label: 'Settings', icon: Settings, permission: 'settings.view' },
];

export const navUpperIds = ['dashboard', 'inbox', 'posts', 'contacts'];
export const navLowerIds = ['channels', 'products', 'orders', 'team', 'workflows', 'settings'];

export const workspaceTopTools = [
    { id: 'search', label: 'Search', icon: Search, modal: 'search' },
    { id: 'notifications', label: 'Alerts', icon: Bell, modal: 'notifications' },
];

export const workspaceUtility = [
    { id: 'help', label: 'Help', icon: CircleHelp, modal: 'help' },
];

export const mobilePrimaryNav = ['inbox', 'contacts', 'agents', 'channels'];
