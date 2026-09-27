import axios from 'axios';

const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

export const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
    },
});

export async function bootCsrf() {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

/** Flatten Laravel / PHP error payloads so the UI never renders a raw array. */
export function apiErrorMessage(error, fallback = 'Something went wrong.') {
    const data = error?.response?.data;
    if (data?.errors && typeof data.errors === 'object') {
        const first = Object.values(data.errors).flat().find((v) => v != null && String(v).trim() !== '');
        if (first) return String(first);
    }
    const msg = data?.message ?? error?.message;
    if (typeof msg === 'string' && msg.trim() !== '') return msg.trim();
    if (Array.isArray(msg)) {
        const flat = msg.flat(Infinity).map((v) => (typeof v === 'string' ? v : '')).filter(Boolean);
        if (flat.length) return flat.join(' ');
    }
    if (msg && typeof msg === 'object') {
        try {
            return JSON.stringify(msg);
        } catch {
            /* ignore */
        }
    }
    return fallback;
}

export function can(me, key) {
    if (!me) return false;
    if (me.user?.platform_role === 'super_admin') return true;
    return (me.permissions || []).includes(key);
}
