<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps<{ html: string }>();

const frame = ref<HTMLIFrameElement | null>(null);
const height = ref(32);

let observer: ResizeObserver | null = null;

const doc = computed(() => {
    const parts = [
        '<!doctype html><html><head><meta charset="utf-8">',
        '<meta http-equiv="Content-Security-Policy" content="',
        "default-src 'none'; ",
        'img-src https: data:; ',
        "style-src 'unsafe-inline'; ",
        'font-src https: data:; ',
        "base-uri 'none'; ",
        "form-action 'none'",
        '">',
        '<base target="_blank">',
        '<style>',
        'html,body{margin:0;padding:0;background:transparent;}',
        "body{overflow-wrap:break-word;color:#29251f;font-family:'IBM Plex Sans',Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:14px;line-height:1.55;}",
        'p{margin:0 0 0.85em;}',
        'a{color:var(--ob-accent-strong);}',
        'body>:first-child{margin-top:0;}',
        'body>:last-child{margin-bottom:0;}',
        'img{max-width:100%;height:auto;}',
        '</style>',
        '</head><body>',
        props.html,
        '</body></html>',
    ];

    return parts.join('');
});

function resize() {
    const frameDoc = frame.value?.contentDocument;

    if (!frameDoc?.documentElement || !frameDoc.body) {
        return;
    }

    height.value = Math.max(
        frameDoc.documentElement.scrollHeight,
        frameDoc.body.scrollHeight,
        32,
    );
}

function onLoad() {
    observer?.disconnect();
    observer = null;
    resize();

    const body = frame.value?.contentDocument?.body;

    if (body && typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(resize);
        observer.observe(body);
    }
}

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <iframe
        ref="frame"
        class="msg-html"
        sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin"
        :srcdoc="doc"
        :style="{ height: `${height}px` }"
        title="Email body"
        @load="onLoad"
    />
</template>
