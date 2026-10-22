<script setup lang="ts">
import { initials } from '@/lib/peopleAccountsFormatters';

defineProps<{
    name: string;
    secondary: string | null;
    person?: boolean;
}>();
</script>

<template>
    <span class="registry-identity">
        <span class="registry-avatar" :class="{ person }" aria-hidden="true">
            {{ initials(name) }}
        </span>
        <span class="registry-identity-copy">
            <strong>{{ name }}</strong>
            <small v-if="secondary">{{ secondary }}</small>
        </span>
    </span>
</template>

<style scoped>
.registry-identity {
    display: flex;
    min-width: 0;
    align-items: center;
    gap: 9px;
}

.registry-avatar {
    display: grid;
    flex: 0 0 30px;
    width: 30px;
    height: 30px;
    place-items: center;
    border-radius: 8px;
    background: #ece8e1;
    color: #5f584f;
    font-size: 10px;
    font-weight: 700;
}

.registry-avatar.person {
    border-radius: 50%;
}

.registry-identity-copy {
    display: flex;
    min-width: 0;
    flex-direction: column;
    text-align: left;
}

.registry-identity-copy strong,
.registry-identity-copy small {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.registry-identity-copy strong {
    color: #29251f;
    font-size: 12px;
    font-weight: 620;
}

.registry-identity-copy small {
    margin-top: 2px;
    color: #6b655c;
    font-size: 10px;
}
</style>
