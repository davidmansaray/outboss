export type JsonErrorBag = Record<string, string>;

export class JsonHttpError extends Error {
    readonly status: number;
    readonly data: unknown;
    readonly errors: JsonErrorBag;
    readonly currentLockVersion: number | null;
    readonly currentSection: Record<string, unknown> | null;
    readonly errorType: string | null;

    constructor(status: number, data: unknown) {
        super(
            extractMessage(data) ?? `JSON request failed with status ${status}`,
        );
        this.name = 'JsonHttpError';
        this.status = status;
        this.data = data;
        this.errors = extractErrors(data);
        this.currentLockVersion = extractCurrentLockVersion(data);
        this.currentSection = extractCurrentSection(data);
        this.errorType = extractErrorType(data);
    }
}

type JsonRequestOptions = Omit<RequestInit, 'body' | 'headers'> & {
    body?: unknown;
    headers?: HeadersInit;
};

function normalizedErrorBag(value: unknown): JsonErrorBag {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return {};
    }

    return Object.entries(value).reduce<JsonErrorBag>(
        (result, [key, value]) => {
            if (typeof value === 'string') {
                result[key] = value;
            } else if (Array.isArray(value) && typeof value[0] === 'string') {
                result[key] = value[0];
            }

            return result;
        },
        {},
    );
}

/**
 * Laravel's default validation response is `{ message, errors }`, while the
 * session JSON exception renderer wraps that bag as
 * `{ error: { type, message, errors } }`. Prefer the deepest real field bag
 * so callers see `company.name`, not an empty top-level object.
 */
function extractErrors(data: unknown): JsonErrorBag {
    const record = asRecord(data);

    if (!record) {
        return {};
    }

    const nestedError = asRecord(record.error);
    const candidates = [nestedError?.errors, record.errors];

    for (const candidate of candidates) {
        const bag = normalizedErrorBag(candidate);

        if (Object.keys(bag).length > 0) {
            const message = extractMessage(data);

            if (message && bag.message === undefined) {
                bag.message = message;
            }

            return bag;
        }
    }

    const message = extractMessage(data);

    return message ? { message } : {};
}

function asRecord(value: unknown): Record<string, unknown> | null {
    return typeof value === 'object' && value !== null
        ? (value as Record<string, unknown>)
        : null;
}

function parseLockVersion(value: unknown): number | null {
    const parsed =
        typeof value === 'number'
            ? value
            : typeof value === 'string' && value.trim() !== ''
              ? Number(value)
              : NaN;

    return Number.isSafeInteger(parsed) && parsed >= 0 ? parsed : null;
}

function extractMessage(data: unknown): string | null {
    const record = asRecord(data);

    if (!record) {
        return null;
    }

    const direct = record.message;

    if (typeof direct === 'string' && direct.trim() !== '') {
        return direct;
    }

    const error = asRecord(record.error);
    const nested = error?.message;

    return typeof nested === 'string' && nested.trim() !== '' ? nested : null;
}

function extractErrorType(data: unknown): string | null {
    const record = asRecord(data);
    const error = record ? asRecord(record.error) : null;
    const type = error?.type ?? record?.type;

    return typeof type === 'string' && type.trim() !== '' ? type : null;
}

/**
 * Conflict responses put the authoritative aggregate lock in a few
 * different envelopes across the API. Keep that detail in the JSON client
 * so mutation callers cannot accidentally retry with their stale lock.
 */
function extractCurrentLockVersion(data: unknown): number | null {
    const record = asRecord(data);

    if (!record) {
        return null;
    }

    for (const key of ['current_lock_version', 'lock_version']) {
        const lockVersion = parseLockVersion(record[key]);

        if (lockVersion !== null) {
            return lockVersion;
        }
    }

    for (const key of [
        'conflict',
        'error',
        'errors',
        'profile',
        'current_profile',
        'data',
    ]) {
        const lockVersion = extractCurrentLockVersion(record[key]);

        if (lockVersion !== null) {
            return lockVersion;
        }
    }

    return null;
}

function extractCurrentSection(data: unknown): Record<string, unknown> | null {
    const record = asRecord(data);

    if (!record) {
        return null;
    }

    const section = asRecord(record.current_section);

    if (section) {
        return section;
    }

    for (const key of ['conflict', 'error', 'data']) {
        const nested = extractCurrentSection(record[key]);

        if (nested) {
            return nested;
        }
    }

    return null;
}

function csrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const cookie = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    if (!cookie) {
        return null;
    }

    return decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
}

async function request<T>(
    url: string,
    options: JsonRequestOptions = {},
): Promise<T> {
    const headers = new Headers(options.headers);
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');

    const body = options.body;

    if (body !== undefined) {
        headers.set('Content-Type', 'application/json');
    }

    const token = csrfToken();

    if (token) {
        headers.set('X-XSRF-TOKEN', token);
    }

    const response = await fetch(url, {
        ...options,
        body: body === undefined ? undefined : JSON.stringify(body),
        credentials: options.credentials ?? 'same-origin',
        headers,
    });
    const contentType = response.headers.get('content-type') ?? '';
    const data = contentType.includes('application/json')
        ? await response.json()
        : await response.text();

    if (!response.ok) {
        throw new JsonHttpError(response.status, data);
    }

    return data as T;
}

export const jsonClient = {
    delete<T>(url: string, options?: JsonRequestOptions): Promise<T> {
        return request<T>(url, { ...options, method: 'DELETE' });
    },
    get<T>(url: string, options?: JsonRequestOptions): Promise<T> {
        return request<T>(url, { ...options, method: 'GET' });
    },
    patch<T>(
        url: string,
        body?: unknown,
        options?: JsonRequestOptions,
    ): Promise<T> {
        return request<T>(url, { ...options, body, method: 'PATCH' });
    },
    post<T>(
        url: string,
        body?: unknown,
        options?: JsonRequestOptions,
    ): Promise<T> {
        return request<T>(url, { ...options, body, method: 'POST' });
    },
    put<T>(
        url: string,
        body?: unknown,
        options?: JsonRequestOptions,
    ): Promise<T> {
        return request<T>(url, { ...options, body, method: 'PUT' });
    },
};
