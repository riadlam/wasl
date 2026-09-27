import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import App from './space/App';
import { initialView, normalizeWorkspaceLocation } from './space/nav';
import { createQueryClient } from './space/query';

const el = document.getElementById('space-root');
const queryClient = createQueryClient();

if (el) {
    normalizeWorkspaceLocation();
    createRoot(el).render(
        <QueryClientProvider client={queryClient}>
            <App start={initialView()} />
        </QueryClientProvider>,
    );
}
