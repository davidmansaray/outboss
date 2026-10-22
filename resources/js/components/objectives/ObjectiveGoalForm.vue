<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    returnCampaign?: { id: number; name: string } | null;
    objective?: {
        id: string;
        name: string;
        description: string | null;
        measurement: string;
        target_count: number | null;
        deadline: string | null;
        lock_version: number;
    };
}>();
const form = useForm({
    ...(props.returnCampaign ? { from_campaign: props.returnCampaign.id } : {}),
    name: props.objective?.name ?? '',
    description: props.objective?.description ?? '',
    measurement: props.objective?.measurement ?? 'meeting_booked',
    creation_key: Array.from(
        crypto.getRandomValues(new Uint8Array(24)),
        (byte) => byte.toString(16).padStart(2, '0'),
    ).join(''),
    lock_version: props.objective?.lock_version ?? 1,
});
function save() {
    if (props.objective) {
        form.patch(`/objectives/${props.objective.id}`, {
            preserveScroll: true,
        });
    } else {
        form.post('/objectives');
    }
}
</script>

<template>
    <form class="goal-form" @submit.prevent="save">
        <label
            >Name<input
                v-model="form.name"
                required
                maxlength="160"
                placeholder="Book customer discovery meetings"
        /></label>
        <label
            >Success measure<select v-model="form.measurement">
                <option value="meeting_booked">Meeting booked</option>
            </select></label
        >
        <p class="hint">
            Count confirmed bookings, not replies or expressions of interest.
            Qualification is not implied.
        </p>
        <p class="hint">
            Reusable across campaigns. Set targets and sending dates when
            preparing a campaign.
        </p>
        <label
            >Description <span class="hint">Optional</span
            ><textarea v-model="form.description" rows="3" maxlength="4000" />
        </label>
        <div v-if="Object.keys(form.errors).length" role="alert">
            <p v-for="(error, field) in form.errors" :key="field" class="error">
                {{ error }}
            </p>
        </div>
        <p class="hint">
            This saves an independent Objective. It does not select prospects,
            approve a campaign, or send anything.
        </p>
        <p v-if="objective" class="hint">
            Edits create a new version. Existing campaigns keep their selected
            version until you explicitly change it in campaign setup.
        </p>
        <footer>
            <button type="submit" :disabled="form.processing">
                {{ form.processing ? 'Saving…' : 'Save objective' }}</button
            ><Link href="/objectives">Back to Objectives</Link>
        </footer>
    </form>
</template>

<style scoped>
.goal-form {
    display: grid;
    gap: 20px;
    max-width: 620px;
}
label {
    display: grid;
    gap: 8px;
    font-weight: 500;
}
input,
select,
textarea {
    width: 100%;
    border: 1px solid var(--ob-border);
    border-radius: 6px;
    padding: 10px 12px;
    background: var(--ob-surface);
    font: inherit;
}
input:focus-visible,
select:focus-visible,
textarea:focus-visible {
    outline: 2px solid var(--ob-focus);
    outline-offset: 2px;
}
.hint {
    color: var(--ob-muted);
    font-size: 13px;
    font-weight: 400;
}
.error {
    color: #a12622;
}
footer {
    display: flex;
    align-items: center;
    gap: 20px;
}
button {
    background: #191918;
    color: white;
    border-radius: 6px;
    padding: 10px 16px;
}
button:disabled {
    opacity: 0.5;
}
</style>
