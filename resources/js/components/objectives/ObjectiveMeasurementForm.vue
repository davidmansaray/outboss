<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
const props = defineProps<{
    returnCampaign?: { id: number; name: string } | null;
    objective: {
        id: string;
        name: string;
        description: string | null;
        measurement: string;
        lock_version: number;
    };
    definitionVersionId: string | null;
    definitions: { id: string; name: string }[];
}>();
const measurement = useForm(
    props.returnCampaign ? { from_campaign: props.returnCampaign.id } : {},
);
const form = useForm({
    ...(props.returnCampaign ? { from_campaign: props.returnCampaign.id } : {}),
    name: props.objective.name,
    description: props.objective.description,
    measurement: props.objective.measurement,
    lock_version: props.objective.lock_version,
    conversion_definition_version_id: props.definitionVersionId ?? '',
});
</script>
<template>
    <section class="measurement">
        <h2>What counts</h2>
        <p>
            Choose the recorded outcome that counts toward this Objective.
            Campaign targets and sending allowances are configured separately.
        </p>
        <form
            @submit.prevent="
                form.patch(`/objectives/${objective.id}`, {
                    preserveScroll: true,
                })
            "
        >
            <label
                >Outcome definition
                <select
                    aria-label="Outcome definition"
                    v-model="form.conversion_definition_version_id"
                    required
                >
                    <option value="" disabled>
                        Choose a workspace measurement
                    </option>
                    <option
                        v-for="definition in definitions"
                        :key="definition.id"
                        :value="definition.id"
                    >
                        {{ definition.name }}
                    </option>
                </select>
            </label>
            <template v-if="definitions.length === 0">
                <p>
                    Create a workspace meeting measurement to define success. No
                    Offer is required.
                </p>
                <button
                    type="button"
                    :disabled="measurement.processing"
                    @click="
                        measurement.post(
                            `/objectives/${objective.id}/measurement`,
                            { preserveScroll: true },
                        )
                    "
                >
                    Create meeting measurement
                </button>
                <p
                    v-for="(error, key) in measurement.errors"
                    :key="key"
                    role="alert"
                >
                    {{ error }}
                </p>
            </template>
            <p v-for="(error, key) in form.errors" :key="key" role="alert">
                {{ error }}
            </p>
            <button
                type="submit"
                :disabled="form.processing || definitions.length === 0"
            >
                {{ form.processing ? 'Saving…' : 'Save measurement' }}
            </button>
        </form>
    </section>
</template>
<style scoped>
.measurement {
    max-width: 720px;
    margin-top: 36px;
    padding-top: 24px;
    border-top: 1px solid var(--ob-border);
}
h2 {
    font-size: 18px;
    font-weight: 600;
}
p {
    color: var(--ob-muted);
    font-size: 13px;
    margin: 12px 0;
}
form {
    display: grid;
    gap: 18px;
}
label {
    display: grid;
    gap: 8px;
    font-size: 13px;
    font-weight: 500;
}
select {
    width: 100%;
    border: 1px solid var(--ob-border);
    padding: 10px 12px;
    border-radius: 6px;
    background: var(--ob-surface);
}
button {
    justify-self: start;
    padding: 10px 16px;
    border-radius: 6px;
    background: #191918;
    color: white;
}
button:disabled {
    opacity: 0.5;
}
[role='alert'] {
    color: #a12622;
}
</style>
