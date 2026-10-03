<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: '外觀設定',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="外觀設定" />

    <h1 class="sr-only">外觀設定</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="外觀設定"
            description="調整帳號的外觀設定"
        />
        <AppearanceTabs />
    </div>
</template>
