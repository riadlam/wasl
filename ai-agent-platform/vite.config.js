import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const hmrHost = env.VITE_DEV_HOST || '192.168.1.9';

    return {
        plugins: [
            laravel({
                input: [
                    'resources/css/app.css',
                    'resources/js/app.js',
                    'resources/js/landing.jsx',
                    'resources/js/space.jsx',
                    'resources/js/admin.jsx',
                    'resources/js/auth.jsx',
                ],
                refresh: true,
            }),
            react(),
            tailwindcss(),
        ],
        server: {
            host: '0.0.0.0',
            port: 5174,
            strictPort: false,
            cors: true,
            hmr: {
                host: hmrHost,
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
