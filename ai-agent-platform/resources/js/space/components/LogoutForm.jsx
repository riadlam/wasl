/**
 * Shared logout form fields (Sanctum session).
 */
export function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

export function LogoutForm({ className = '', buttonClassName = '', label = 'Log out' }) {
    return (
        <form method="POST" action="/logout" className={className}>
            <input type="hidden" name="_token" value={csrfToken()} />
            <button type="submit" className={buttonClassName}>
                {label}
            </button>
        </form>
    );
}
