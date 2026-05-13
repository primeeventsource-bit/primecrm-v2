import axios from 'axios';

declare global {
    interface Window {
        axios: typeof axios;
    }
}

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Convert JS booleans to '1' / '0' in query strings.
 *
 * Why: Laravel's `boolean` validation rule accepts only
 * `true | false | 0 | 1 | '0' | '1'` (in_array strict). axios's
 * default serializer encodes JS `true` as the URL string `"true"`,
 * which Laravel rejects with a 422.
 *
 * Switching every callsite to pass `1`/`0` instead of `true`/`false`
 * would work too but is repetitive and easy to forget. Setting a
 * global paramsSerializer here is the one-shot fix — every
 * `axios.get(url, { params: { live: true } })` now goes out as
 * `?live=1`, which Laravel accepts and the controller's
 * `$request->boolean('live')` reads correctly.
 *
 * URLSearchParams handles array values and `undefined` (drops them);
 * we only intercept booleans.
 */
window.axios.defaults.paramsSerializer = (params: Record<string, unknown>): string => {
    const sp = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value === undefined || value === null) continue;
        if (typeof value === 'boolean') {
            sp.append(key, value ? '1' : '0');
        } else if (Array.isArray(value)) {
            for (const v of value) {
                if (v === undefined || v === null) continue;
                sp.append(`${key}[]`, typeof v === 'boolean' ? (v ? '1' : '0') : String(v));
            }
        } else {
            sp.append(key, String(value));
        }
    }
    return sp.toString();
};

const token = document.head.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}
