<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed, watchEffect } from 'vue';
import MobileAuthorizeController from '@/actions/App/Http/Controllers/Mobile/MobileAuthorizeController';
import InputError from '@/components/InputError.vue';
import { Spinner } from '@/components/ui/spinner';

type Workspace = {
    id: number;
    name: string;
    role: string;
};

type AuthorizationRequest = {
    client: string;
    redirect_uri: string;
    code_challenge: string;
    code_challenge_method: string;
    state: string;
    device_name?: string | null;
};

const props = defineProps<{
    invalid: boolean;
    request?: AuthorizationRequest;
    email?: string;
    workspaces?: Workspace[];
}>();

watchEffect(() => {
    setLayoutProps(
        props.invalid
            ? { title: 'Outboss for iPhone', description: '' }
            : {
                  title: 'Choose a workspace',
                  description: `Signed in as ${props.email ?? ''}. You can switch workspaces in the app at any time.`,
              },
    );
});

// The original request travels with both forms so the server can check it
// again before answering the app.
const requestFields = computed<Array<[string, string]>>(() => {
    if (!props.request) {
        return [];
    }

    const fields: Array<[string, string]> = [
        ['client', props.request.client],
        ['redirect_uri', props.request.redirect_uri],
        ['code_challenge', props.request.code_challenge],
        ['code_challenge_method', props.request.code_challenge_method],
        ['state', props.request.state],
    ];

    if (props.request.device_name) {
        fields.push(['device_name', props.request.device_name]);
    }

    return fields;
});
</script>

<template>
    <Head :title="invalid ? 'Outboss for iPhone' : 'Choose a workspace'" />

    <div
        v-if="invalid"
        class="space-y-3 rounded-lg border border-border p-4 text-sm"
        role="alert"
        data-test="mobile-authorize-invalid"
    >
        <p class="font-medium">This sign-in link isn't valid.</p>
        <p class="text-muted-foreground">
            It may have expired or been opened outside the app. Close this
            window and tap Sign in again in the Outboss app.
        </p>
    </div>

    <div v-else-if="request" class="space-y-5">
        <Form
            v-bind="MobileAuthorizeController.store.form()"
            v-slot="{ errors, processing }"
            data-test="mobile-authorize-form"
        >
            <input type="hidden" name="decision" value="allow" />
            <input
                v-for="[name, value] in requestFields"
                :key="name"
                type="hidden"
                :name="name"
                :value="value"
            />

            <ul class="grid gap-2" aria-label="Workspaces">
                <li v-for="workspace in workspaces" :key="workspace.id">
                    <button
                        type="submit"
                        name="workspace_id"
                        :value="workspace.id"
                        class="mobile-workspace-row"
                        :disabled="processing"
                        :data-test="`mobile-workspace-${workspace.id}`"
                    >
                        <span class="min-w-0 flex-1 truncate text-left">
                            {{ workspace.name }}
                        </span>
                        <span class="text-xs text-muted-foreground capitalize">
                            {{ workspace.role }}
                        </span>
                        <Spinner v-if="processing" class="size-4 shrink-0" />
                        <ChevronRight
                            v-else
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </button>
                </li>
            </ul>
            <InputError class="mt-2" :message="errors.workspace_id" />
        </Form>

        <Form
            v-bind="MobileAuthorizeController.store.form()"
            v-slot="{ processing }"
            class="text-center"
            data-test="mobile-authorize-cancel-form"
        >
            <input type="hidden" name="decision" value="deny" />
            <input
                v-for="[name, value] in requestFields"
                :key="name"
                type="hidden"
                :name="name"
                :value="value"
            />
            <button
                type="submit"
                class="mobile-cancel"
                :disabled="processing"
                data-test="mobile-authorize-cancel"
            >
                Cancel
            </button>
        </Form>
    </div>
</template>

<style scoped>
/* The auth layout sizes every input and fills every submit button; the
   workspace rows and the cancel link need their own treatment. */
button.mobile-workspace-row[type='submit'] {
    display: flex;
    width: 100%;
    min-height: 56px;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border: 1px solid #d8d1c7;
    border-radius: 10px;
    background: #fff;
    box-shadow: none;
    color: inherit;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
}
button.mobile-workspace-row[type='submit']:hover:not(:disabled) {
    border-color: var(--ob-ink, currentColor);
}
button.mobile-workspace-row[type='submit']:disabled {
    cursor: progress;
    opacity: 0.7;
}
button.mobile-cancel[type='submit'] {
    min-height: 0;
    padding: 4px 8px;
    border-radius: 6px;
    background: transparent;
    box-shadow: none;
    color: #716a61;
    font-size: 13px;
    font-weight: 500;
    text-decoration: underline;
    text-underline-offset: 3px;
    cursor: pointer;
}
</style>
