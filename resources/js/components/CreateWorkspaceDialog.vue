<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{
    'update:open': [value: boolean];
    navigated: [];
}>();

type WorkspaceForm = {
    name: string;
    website_url: string | null;
    client_operation_id: string;
};

const step = ref<'name' | 'website'>('name');
const clientOperationId = ref<string | null>(null);
const form = useForm<WorkspaceForm>({
    name: '',
    website_url: null,
    client_operation_id: '',
});

function newClientOperationId(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return `workspace-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function resetForm(): void {
    step.value = 'name';
    clientOperationId.value = null;
    form.reset();
    form.clearErrors();
}

function focusStep(): void {
    void nextTick(() => {
        if (step.value === 'website') {
            document.getElementById('workspace-website')?.focus();

            return;
        }

        document.getElementById('workspace-name')?.focus();
    });
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            focusStep();
        }
    },
);

function closeDialog(): void {
    if (form.processing) {
        return;
    }

    emit('update:open', false);

    if (clientOperationId.value === null) {
        resetForm();
    }
}

function continueToWebsite(): void {
    if (!form.name.trim()) {
        form.setError('name', 'Enter a workspace name.');
        focusStep();

        return;
    }

    form.clearErrors('name');
    step.value = 'website';
    focusStep();
}

function createWorkspace(skipWebsite = false): void {
    if (form.processing) {
        return;
    }

    if (!form.name.trim()) {
        form.setError('name', 'Enter a workspace name.');
        step.value = 'name';
        focusStep();

        return;
    }

    clientOperationId.value ??= newClientOperationId();
    form.name = form.name.trim();
    form.website_url = skipWebsite ? null : form.website_url?.trim() || null;
    form.client_operation_id = clientOperationId.value;
    form.post('/workspaces', {
        preserveScroll: false,
        onError: (errors) => {
            if (errors.website_url) {
                step.value = 'website';
            } else if (errors.name) {
                step.value = 'name';
            }

            focusStep();
        },
        onSuccess: () => {
            emit('update:open', false);
            resetForm();
            emit('navigated');
        },
    });
}
</script>

<template>
    <Dialog :open="props.open" @update:open="closeDialog">
        <DialogContent
            class="workspace-create-dialog sm:max-w-md"
            :show-close-button="!form.processing"
            :aria-busy="form.processing ? 'true' : undefined"
        >
            <DialogHeader v-if="step === 'name'">
                <span class="workspace-dialog-eyebrow"
                    >New workspace · 1 of 2</span
                >
                <DialogTitle>Name your workspace</DialogTitle>
                <DialogDescription>
                    Use the company or team name people will recognize.
                </DialogDescription>
            </DialogHeader>
            <DialogHeader v-else>
                <span class="workspace-dialog-eyebrow"
                    >New workspace · 2 of 2</span
                >
                <DialogTitle>What’s the company website?</DialogTitle>
                <DialogDescription>
                    Outboss can use it to prepare the first GTM profile.
                </DialogDescription>
            </DialogHeader>
            <form
                class="workspace-create-form"
                @submit.prevent="
                    step === 'name' ? continueToWebsite() : createWorkspace()
                "
            >
                <div v-if="step === 'name'" class="grid gap-2">
                    <Label for="workspace-name">Workspace name</Label>
                    <Input
                        id="workspace-name"
                        v-model="form.name"
                        name="name"
                        maxlength="120"
                        autocomplete="organization"
                        :disabled="form.processing"
                        :aria-invalid="form.errors.name ? 'true' : undefined"
                        :aria-describedby="
                            form.errors.name
                                ? 'workspace-name-error'
                                : undefined
                        "
                    />
                    <p
                        v-if="form.errors.name"
                        id="workspace-name-error"
                        class="workspace-form-error"
                        role="alert"
                    >
                        {{ form.errors.name }}
                    </p>
                </div>
                <div v-else class="grid gap-2">
                    <Label for="workspace-website">
                        Company website <span>Optional</span>
                    </Label>
                    <Input
                        id="workspace-website"
                        :model-value="form.website_url ?? ''"
                        name="website_url"
                        type="text"
                        inputmode="url"
                        autocomplete="url"
                        placeholder="https://example.com"
                        :disabled="form.processing"
                        @update:model-value="
                            (value) => (form.website_url = String(value))
                        "
                        :aria-invalid="
                            form.errors.website_url ? 'true' : undefined
                        "
                        :aria-describedby="
                            form.errors.website_url
                                ? 'workspace-website-error'
                                : undefined
                        "
                    />
                    <p class="workspace-field-note">
                        Public pages only. You can review and edit everything
                        Outboss finds.
                    </p>
                    <p
                        v-if="form.errors.website_url"
                        id="workspace-website-error"
                        class="workspace-form-error"
                        role="alert"
                    >
                        {{ form.errors.website_url }}
                    </p>
                </div>
                <p
                    v-if="form.errors.client_operation_id"
                    class="workspace-form-error"
                    role="alert"
                >
                    {{ form.errors.client_operation_id }}
                </p>
                <DialogFooter>
                    <Button
                        v-if="step === 'website'"
                        type="button"
                        variant="secondary"
                        class="workspace-create-back"
                        :disabled="form.processing"
                        @click="
                            step = 'name';
                            focusStep();
                        "
                    >
                        Back
                    </Button>
                    <Button
                        v-if="step === 'name'"
                        type="button"
                        variant="secondary"
                        class="workspace-create-cancel"
                        :disabled="form.processing"
                        @click="closeDialog"
                    >
                        Cancel
                    </Button>
                    <Button
                        v-if="step === 'website'"
                        type="button"
                        variant="ghost"
                        class="workspace-create-skip"
                        :disabled="form.processing"
                        @click="createWorkspace(true)"
                    >
                        Skip website
                    </Button>
                    <Button
                        type="submit"
                        class="workspace-create-submit"
                        :disabled="form.processing"
                    >
                        <span v-if="form.processing">Creating…</span>
                        <span v-else-if="step === 'name'">Continue</span>
                        <span v-else>Create workspace</span>
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
