import { QueryClient } from '@tanstack/react-query';

export const queryKeys = {
    products: ['products'],
    deliveryZones: ['delivery-zones'],
    paymentMethods: ['payment-methods'],
    wilayas: ['wilayas'],
    workflows: ['workflows'],
    workflowTemplates: ['workflows', 'templates'],
    team: ['team'],
    orders: (status = 'all', search = '') => ['orders', status, search],
    socialAccounts: ['social-accounts'],
    agent: ['agent'],
    agentChats: ['agent', 'chats'],
    agentChat: (chatId) => ['agent', 'chat', chatId ?? 'none'],
    agentBehaviorRules: ['agent', 'behavior-rules'],
    profileInterview: ['agent', 'profile-interview'],
    customerAi: ['customer-ai-settings'],
    mcpTokens: ['mcp-tokens'],
    telegram: ['telegram'],
    campaigns: ['agent', 'campaigns'],
    campaign: (id) => ['agent', 'campaigns', id],
};

export function createQueryClient() {
    return new QueryClient({
        defaultOptions: {
            queries: {
                staleTime: 60_000,
                gcTime: 15 * 60_000,
                refetchOnWindowFocus: false,
                retry: 1,
            },
        },
    });
}
