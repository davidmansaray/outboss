<script setup lang="ts">
import { ref, watch } from 'vue';

type PreviewPerson = {
    id: number;
    name: string | null;
    email: string;
};

const props = defineProps<{
    stepId: number;
    recipientId: number | null;
    people: PreviewPerson[];
    savedContentKey?: string;
}>();

const subject = ref('');
const body = ref('');
const to = ref('');
const error = ref('');
const loading = ref(false);
const isSample = ref(false);
let requestId = 0;

async function load(): Promise<void> {
    const current = ++requestId;
    loading.value = true;
    error.value = '';

    const params = new URLSearchParams();

    if (props.recipientId) {
        params.set('recipient_id', String(props.recipientId));
    }

    try {
        const response = await fetch(
            `/sequence-steps/${props.stepId}/preview${params.size ? `?${params.toString()}` : ''}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );
        const data = (await response.json()) as {
            subject?: string;
            body?: string;
            to?: string;
            recipient_id?: number | null;
            message?: string;
        };

        if (current !== requestId) {
            return;
        }

        if (!response.ok) {
            error.value = data.message ?? 'Preview could not be rendered.';
            subject.value = '';
            body.value = '';
            to.value = '';
            loading.value = false;

            return;
        }

        subject.value = data.subject ?? '';
        body.value = data.body ?? '';
        to.value = data.to ?? '';
        isSample.value = data.recipient_id == null;
    } catch {
        if (current !== requestId) {
            return;
        }

        error.value = 'Preview could not be rendered.';
    } finally {
        if (current === requestId) {
            loading.value = false;
        }
    }
}

watch(
    () => [props.stepId, props.recipientId, props.savedContentKey],
    () => {
        void load();
    },
    { immediate: true },
);
</script>

<template>
    <article class="sequence-preview">
        <p v-if="loading" role="status">Loading saved preview…</p>
        <p v-else-if="error" class="sequence-preview-error" role="alert">
            {{ error }}
        </p>
        <template v-else>
            <div class="sequence-preview-meta">
                <span>
                    To
                    <strong>{{ to || 'Add someone to preview' }}</strong>
                </span>
                <span>
                    Subject
                    <strong>{{ subject || 'No subject' }}</strong>
                </span>
            </div>
            <div class="sequence-preview-body">
                {{
                    loading ? 'Loading preview…' : body || 'No email body yet.'
                }}
            </div>
            <p v-if="isSample && !people.length">
                Sample data — add people to preview a real recipient.
            </p>
            <p v-else>
                Preview uses the enrolment snapshot the campaign will send.
            </p>
        </template>
    </article>
</template>
