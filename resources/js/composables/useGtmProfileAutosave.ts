import { computed, onBeforeUnmount, ref, toValue } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import { JsonHttpError, jsonClient } from '@/lib/jsonClient';

export type GtmAutosaveStatus =
    'idle' | 'scheduled' | 'dirty' | 'saving' | 'saved' | 'error' | 'conflict';

export type GtmAutosaveResponse = {
    profile?: unknown;
    version?: unknown;
    lock_version?: number;
    lockVersion?: number;
    [key: string]: unknown;
};

type AutosaveOptions<TResponse extends GtmAutosaveResponse> = {
    profileKey: MaybeRefOrGetter<string | number>;
    section: MaybeRefOrGetter<string>;
    expectedLockVersion: MaybeRefOrGetter<number>;
    delayMs?: number;
    endpoint?: MaybeRefOrGetter<string>;
    method?: 'patch' | 'put';
    payloadKey?: string;
    /**
     * Resolve the lock as close as possible to the actual request. This is
     * useful when two endpoint-specific queues share a profile lock and one
     * queue is waiting behind the other.
     */
    resolveExpectedLockVersionOnSend?: boolean;
    onSaved?: (response: TResponse) => void;
    onConflict?: (error: JsonHttpError) => void;
};

type SaveOperation = {
    clientOperationId: string;
    expectedLockVersion: number;
    payload: unknown;
    preserveExpectedLockVersion?: boolean;
};

const profileChains = new Map<string, Promise<unknown>>();

function operationId(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return `gtm-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function errorMessage(error: unknown): string {
    if (error instanceof JsonHttpError) {
        return (
            error.errors.message ??
            error.errors.payload ??
            'Outboss could not save this section. Try again.'
        );
    }

    return 'Outboss could not save this section. Check your connection and try again.';
}

export function useGtmProfileAutosave<
    TResponse extends GtmAutosaveResponse = GtmAutosaveResponse,
>(options: AutosaveOptions<TResponse>) {
    const status = ref<GtmAutosaveStatus>('idle');
    const error = ref<string | null>(null);
    const conflict = ref<JsonHttpError | null>(null);
    const responseError = ref<JsonHttpError | null>(null);
    const pendingPayload = ref<unknown | null>(null);
    const lastFailedOperation = ref<SaveOperation | null>(null);
    const retryOperation = ref<SaveOperation | null>(null);
    const savingPromise = ref<Promise<boolean> | null>(null);
    let timer: ReturnType<typeof setTimeout> | null = null;

    const hasPending = computed(
        () => pendingPayload.value !== null || status.value === 'saving',
    );
    const conflictDraft = computed(
        () =>
            pendingPayload.value ?? lastFailedOperation.value?.payload ?? null,
    );

    function clearTimer(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    function requestEndpoint(): string {
        const configured = options.endpoint ? toValue(options.endpoint) : null;

        return (
            configured ??
            `/settings/gtm-profile/sections/${encodeURIComponent(String(toValue(options.section)))}`
        );
    }

    function conflictLockVersion(error: JsonHttpError): number | null {
        const lockVersion = error.currentLockVersion;

        return typeof lockVersion === 'number' &&
            Number.isSafeInteger(lockVersion) &&
            lockVersion >= 0
            ? lockVersion
            : null;
    }

    function queueIsHealthy(): boolean {
        const current: GtmAutosaveStatus = status.value;

        return current !== 'error' && current !== 'conflict';
    }

    async function send(operation: SaveOperation): Promise<boolean> {
        const profileKey = String(toValue(options.profileKey));
        const previous = profileChains.get(profileKey) ?? Promise.resolve();
        let sentOperation = operation;
        const request = previous
            .catch(() => undefined)
            .then(() => {
                status.value = 'saving';

                const expectedLockVersion =
                    options.resolveExpectedLockVersionOnSend === true &&
                    operation.preserveExpectedLockVersion !== true
                        ? Number(toValue(options.expectedLockVersion))
                        : operation.expectedLockVersion;
                sentOperation = {
                    ...operation,
                    expectedLockVersion,
                    preserveExpectedLockVersion: true,
                };
                const payloadKey = options.payloadKey ?? 'payload';
                const body = {
                    client_operation_id: sentOperation.clientOperationId,
                    expected_lock_version: expectedLockVersion,
                    [payloadKey]: sentOperation.payload,
                };

                if ((options.method ?? 'patch') === 'put') {
                    return jsonClient.put<TResponse>(requestEndpoint(), body);
                }

                return jsonClient.patch<TResponse>(requestEndpoint(), body);
            });
        profileChains.set(profileKey, request);

        try {
            const response = await request;
            options.onSaved?.(response);
            lastFailedOperation.value = null;
            retryOperation.value = null;
            error.value = null;
            conflict.value = null;
            responseError.value = null;
            status.value = 'saved';

            return true;
        } catch (caught) {
            const jsonError = caught instanceof JsonHttpError ? caught : null;
            const conflictError = jsonError?.status === 409 ? jsonError : null;
            lastFailedOperation.value = sentOperation;
            error.value = errorMessage(caught);
            conflict.value = conflictError;
            responseError.value = jsonError;
            status.value = conflict.value ? 'conflict' : 'error';

            if (conflict.value) {
                // A stale operation is frozen exactly as it was sent. It may
                // only be discarded or explicitly reapplied as a new client
                // operation after the owner reviews the server section.
                retryOperation.value = null;
                options.onConflict?.(conflict.value);
            }

            return false;
        } finally {
            if (profileChains.get(profileKey) === request) {
                profileChains.delete(profileKey);
            }
        }
    }

    async function flush(): Promise<boolean> {
        clearTimer();

        if (status.value === 'conflict') {
            // Never convert a normal Retry/flush into a stale-lock rebase.
            // The explicit reapplyConflict() path below creates a fresh
            // operation only after the UI shows the latest server section.
            return false;
        }

        if (savingPromise.value) {
            await savingPromise.value;

            if (pendingPayload.value !== null) {
                return flush();
            }

            return queueIsHealthy();
        }

        if (pendingPayload.value === null) {
            return queueIsHealthy();
        }

        const payload = pendingPayload.value;
        pendingPayload.value = null;
        const operation = retryOperation.value ?? {
            clientOperationId: operationId(),
            expectedLockVersion: Number(toValue(options.expectedLockVersion)),
            payload,
        };
        retryOperation.value = null;

        savingPromise.value = send(operation);

        try {
            const succeeded = await savingPromise.value;
            savingPromise.value = null;

            if (!succeeded) {
                pendingPayload.value ??= payload;

                if (!conflict.value) {
                    retryOperation.value ??=
                        lastFailedOperation.value ?? operation;
                }

                return false;
            }

            if (pendingPayload.value !== null) {
                return flush();
            }

            return true;
        } finally {
            savingPromise.value = null;
        }
    }

    function schedule(payload: unknown): void {
        pendingPayload.value = payload;

        if (status.value === 'conflict') {
            // Keep accepting local typing so the draft is not lost, but hold
            // the network queue until the owner chooses reload or reapply.
            clearTimer();

            return;
        }

        retryOperation.value = null;
        error.value = null;
        conflict.value = null;
        responseError.value = null;
        status.value = savingPromise.value ? 'dirty' : 'scheduled';
        clearTimer();
        timer = setTimeout(() => {
            timer = null;
            void flush();
        }, options.delayMs ?? 800);
    }

    function retry(): Promise<boolean> {
        if (conflict.value) {
            return Promise.resolve(false);
        }

        if (lastFailedOperation.value) {
            const failedOperation = lastFailedOperation.value;
            pendingPayload.value ??= failedOperation.payload;
            retryOperation.value = {
                ...failedOperation,
                preserveExpectedLockVersion: true,
            };
        }

        error.value = null;
        responseError.value = null;
        status.value = 'dirty';

        return flush();
    }

    function reapplyConflict(): Promise<boolean> {
        const staleConflict = conflict.value;
        const expectedLockVersion = staleConflict
            ? conflictLockVersion(staleConflict)
            : null;
        const payload = conflictDraft.value;

        if (
            !staleConflict?.currentSection ||
            expectedLockVersion === null ||
            payload === null
        ) {
            return Promise.resolve(false);
        }

        pendingPayload.value = payload;
        retryOperation.value = {
            clientOperationId: operationId(),
            expectedLockVersion,
            payload,
            preserveExpectedLockVersion: true,
        };
        error.value = null;
        conflict.value = null;
        responseError.value = null;
        status.value = 'dirty';

        return flush();
    }

    function discard(): void {
        clearTimer();
        pendingPayload.value = null;
        lastFailedOperation.value = null;
        retryOperation.value = null;
        error.value = null;
        conflict.value = null;
        responseError.value = null;
        status.value = 'idle';
    }

    onBeforeUnmount(() => {
        clearTimer();
    });

    return {
        conflict,
        conflictDraft,
        discard,
        error,
        flush,
        hasPending,
        reapplyConflict,
        responseError,
        retry,
        schedule,
        status,
    };
}
