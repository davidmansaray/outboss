<script setup lang="ts">
import { Check, ChevronDown } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { canTransitionOffer, OFFER_STATUSES } from '@/types/offers';
import type { OfferStatus } from '@/types/offers';

const props = withDefaults(
    defineProps<{
        status: OfferStatus;
        isOwner?: boolean;
        disabled?: boolean;
    }>(),
    { isOwner: false, disabled: false },
);

const emit = defineEmits<{
    change: [status: OfferStatus];
}>();

const labels: Record<OfferStatus, string> = {
    draft: 'Draft',
    active: 'Active',
    paused: 'Paused',
    archived: 'Archived',
};
const statusOptions = OFFER_STATUSES.filter(
    (option): option is (typeof OFFER_STATUSES)[number] & { id: OfferStatus } =>
        option.id !== 'all',
);

function choose(status: OfferStatus): void {
    if (
        props.disabled ||
        !props.isOwner ||
        status === props.status ||
        !canTransitionOffer(props.status, status)
    ) {
        return;
    }

    emit('change', status);
}
</script>

<template>
    <DropdownMenu v-if="isOwner">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="offer-status-trigger"
                :disabled="disabled"
                :aria-label="`Change offer status, currently ${labels[status]}`"
                @click.stop
            >
                <span class="offer-status-dot" :class="`is-${status}`" />
                {{ labels[status] }}
                <ChevronDown aria-hidden="true" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="offer-status-menu">
            <DropdownMenuLabel>Change status</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                v-for="option in statusOptions"
                :key="option.id"
                :disabled="
                    option.id === status ||
                    !canTransitionOffer(status, option.id)
                "
                @select="choose(option.id)"
            >
                <span class="offer-status-dot" :class="`is-${option.id}`" />
                {{ option.label }}
                <Check
                    v-if="option.id === status"
                    class="offer-status-check"
                    aria-hidden="true"
                />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
    <span v-else class="offer-status-badge" :class="`is-${status}`">
        <span class="offer-status-dot" :class="`is-${status}`" />
        {{ labels[status] }}
    </span>
</template>

<style scoped>
.offer-status-trigger,
.offer-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    min-height: 26px;
    border-radius: 999px;
    padding: 4px 8px;
    font-size: 9px;
    font-weight: 650;
    line-height: 1;
    white-space: nowrap;
}

.offer-status-trigger {
    border: 1px solid var(--ob-border-strong);
    background: #fff;
    color: #6b655c;
    cursor: pointer;
}

.offer-status-trigger:hover {
    border-color: #bdb7ad;
    color: #29251f;
}

.offer-status-trigger:disabled {
    cursor: wait;
    opacity: 0.6;
}

.offer-status-trigger :deep(svg) {
    width: 12px;
    height: 12px;
}

.offer-status-badge {
    border: 1px solid #e6e1d9;
    color: #6b655c;
}

.offer-status-badge.is-active {
    border-color: #b8dec9;
    background: #f1faf5;
    color: #18794e;
}

.offer-status-badge.is-paused {
    border-color: #e8d5a8;
    background: #fffaf0;
    color: #9a6500;
}

.offer-status-badge.is-archived {
    border-color: var(--ob-border-strong);
    background: #f4f1ec;
    color: #8a847a;
}

.offer-status-dot {
    display: inline-block;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #aaa39a;
}

.offer-status-dot.is-active {
    background: #18794e;
}

.offer-status-dot.is-paused {
    background: #c18516;
}

.offer-status-dot.is-archived {
    background: #8a847a;
}

.offer-status-check {
    margin-left: auto;
    width: 13px;
    height: 13px;
}
</style>
