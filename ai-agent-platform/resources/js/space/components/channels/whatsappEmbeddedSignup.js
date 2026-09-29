/**
 * Meta WhatsApp Embedded Signup with Coexistence
 * (existing WhatsApp Business app numbers — not creating a new number).
 *
 * @see https://docs.social-api.ai/connectors/whatsapp
 * @see https://developers.facebook.com/docs/whatsapp/embedded-signup/custom-flows/onboarding-business-app-users
 */

const FB_SDK_SRC = 'https://connect.facebook.net/en_US/sdk.js';
const FB_GRAPH_VERSION = 'v25.0';
const META_ORIGINS = new Set(['https://www.facebook.com', 'https://web.facebook.com']);

const FINISH_EVENTS = new Set([
    'FINISH',
    'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
]);

let sdkPromise = null;

function loadFacebookSdk(appId) {
    if (typeof window === 'undefined') {
        return Promise.reject(new Error('Facebook SDK requires a browser.'));
    }

    if (window.FB && typeof window.FB.login === 'function') {
        try {
            window.FB.init({
                appId: String(appId),
                autoLogAppEvents: true,
                xfbml: true,
                version: FB_GRAPH_VERSION,
            });
        } catch {
            // already initialized
        }
        return Promise.resolve(window.FB);
    }

    if (sdkPromise) {
        return sdkPromise;
    }

    sdkPromise = new Promise((resolve, reject) => {
        const previous = window.fbAsyncInit;
        window.fbAsyncInit = function fbAsyncInit() {
            try {
                if (typeof previous === 'function') {
                    previous();
                }
            } catch {
                // ignore prior init errors
            }
            try {
                window.FB.init({
                    appId: String(appId),
                    autoLogAppEvents: true,
                    xfbml: true,
                    version: FB_GRAPH_VERSION,
                });
                resolve(window.FB);
            } catch (err) {
                reject(err instanceof Error ? err : new Error('Facebook SDK init failed.'));
            }
        };

        const existing = document.querySelector(`script[src="${FB_SDK_SRC}"]`);
        if (!existing) {
            const script = document.createElement('script');
            script.src = FB_SDK_SRC;
            script.async = true;
            script.defer = true;
            script.onerror = () => {
                sdkPromise = null;
                reject(new Error('Could not load Facebook SDK.'));
            };
            document.body.appendChild(script);
        }
    });

    return sdkPromise;
}

function parseWaMessage(raw) {
    if (raw == null) {
        return null;
    }
    let data = raw;
    if (typeof raw === 'string') {
        try {
            data = JSON.parse(raw);
        } catch {
            return null;
        }
    }
    if (!data || typeof data !== 'object' || data.type !== 'WA_EMBEDDED_SIGNUP') {
        return null;
    }
    return data;
}

/**
 * Launch Meta Embedded Signup configured for existing WhatsApp Business app numbers (Coexistence).
 *
 * @param {{ app_id: string, config_id: string, solution_id?: string }} metadata
 * @returns {Promise<{ code: string, waba_id: string, phone_number_id: string, coexistence: boolean }>}
 */
export async function launchWhatsAppEmbeddedSignup(metadata) {
    const appId = String(metadata?.app_id || '').trim();
    const configId = String(metadata?.config_id || '').trim();
    const solutionId = String(metadata?.solution_id || '').trim();

    if (!appId || !configId) {
        throw new Error('WhatsApp connect is missing Meta app configuration.');
    }

    const FB = await loadFacebookSdk(appId);

    let wabaId = '';
    let phoneNumberId = '';
    let coexistence = false;
    let finished = false;
    let lastError = '';

    const onMessage = (event) => {
        if (!META_ORIGINS.has(event.origin)) {
            return;
        }
        const payload = parseWaMessage(event.data);
        if (!payload) {
            return;
        }

        const inner = payload.data && typeof payload.data === 'object' ? payload.data : {};
        const nextWaba = String(inner.waba_id || inner.wabaId || '').trim();
        const nextPhone = String(inner.phone_number_id || inner.phoneNumberId || '').trim();
        if (nextWaba) {
            wabaId = nextWaba;
        }
        if (nextPhone) {
            phoneNumberId = nextPhone;
        }

        const eventName = String(payload.event || '').toUpperCase();
        if (eventName === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING') {
            coexistence = true;
            finished = true;
        } else if (FINISH_EVENTS.has(eventName)) {
            finished = true;
        } else if (eventName === 'ERROR' || eventName === 'CANCEL') {
            lastError = String(inner.error_message || inner.message || eventName);
        }
    };
    window.addEventListener('message', onMessage);

    try {
        const extras = {
            // Meta Coexistence: keep the existing WhatsApp Business app number.
            featureType: 'whatsapp_business_app_onboarding',
            sessionInfoVersion: '3',
            setup: {},
        };
        if (solutionId) {
            extras.setup = { solutionID: solutionId };
        }

        const response = await new Promise((resolve, reject) => {
            try {
                FB.login((res) => resolve(res || {}), {
                    config_id: configId,
                    response_type: 'code',
                    override_default_response_type: true,
                    extras,
                });
            } catch (err) {
                reject(err instanceof Error ? err : new Error('Facebook login failed to open.'));
            }
        });

        const code = String(response?.authResponse?.code || '').trim();
        if (!code) {
            const status = String(response?.status || '');
            if (status === 'unknown' || status === 'not_authorized') {
                throw new Error(lastError || 'WhatsApp connect was cancelled.');
            }
            throw new Error(
                lastError
                || 'WhatsApp popup did not return an authorization code. '
                    + 'If this domain is not allowlisted on SocialAPI’s Meta app, ask support@social-api.ai to add '
                    + `${window.location.hostname}.`,
            );
        }

        const deadline = Date.now() + 10000;
        while ((!finished || !wabaId) && Date.now() < deadline) {
            // eslint-disable-next-line no-await-in-loop
            await new Promise((r) => setTimeout(r, 150));
        }

        if (!wabaId) {
            throw new Error(
                lastError
                || 'WhatsApp signup finished, but Meta did not send the business account id. Try again.',
            );
        }

        // Coexistence finish sometimes omits phone_number_id in the final event;
        // keep whatever we captured from earlier session messages.
        return {
            code,
            waba_id: wabaId,
            phone_number_id: phoneNumberId,
            coexistence,
        };
    } finally {
        window.removeEventListener('message', onMessage);
    }
}
