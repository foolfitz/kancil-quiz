<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { PageMeta } from '@/types/kancil';

// 公開頁面的 <head>（docs/SPEC.md 10.3）。head-key 與 resources/views/partials/page-meta.blade.php
// 在伺服器端輸出的 data-inertia 相同，Inertia 接手後原地更新，不會重複。
defineProps<{ meta: PageMeta }>();
</script>

<template>
    <Head :title="meta.title">
        <meta
            head-key="description"
            name="description"
            :content="meta.description"
        />
        <link head-key="canonical" rel="canonical" :href="meta.url" />
        <meta head-key="og:type" property="og:type" content="website" />
        <meta head-key="og:title" property="og:title" :content="meta.title" />
        <meta
            head-key="og:description"
            property="og:description"
            :content="meta.description"
        />
        <meta head-key="og:url" property="og:url" :content="meta.url" />
        <meta
            v-if="meta.image"
            head-key="og:image"
            property="og:image"
            :content="meta.image"
        />
    </Head>
</template>
