/**
 * Minimal JSON helpers for same-origin, session-authenticated endpoints (POS lookups).
 */
export class HttpError extends Error {
    constructor(
        public status: number,
        public body: { message?: string; errors?: Record<string, string[]> },
    ) {
        super(body.message ?? `Request failed (${status})`);
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function request<T>(url: string, init: RequestInit = {}): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...init,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(init.body ? { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() } : {}),
            ...init.headers,
        },
    });

    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new HttpError(response.status, body);
    }

    return body as T;
}

export const getJson = <T>(url: string, signal?: AbortSignal) => request<T>(url, { signal });

export const postJson = <T>(url: string, data: unknown) => request<T>(url, { method: 'POST', body: JSON.stringify(data) });
