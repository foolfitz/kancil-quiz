<script setup lang="ts">
import { computed, watch } from 'vue';
import {
    MB,
    WARN_RATIO,
    formatBytes,
    setUploadQuota,
    uploadQuota,
} from '@/lib/uploadQuota';
import type { UploadQuota } from '@/lib/uploadQuota';

// 編輯頁的「已上傳多少／上限」（docs/SPEC.md 第 9 節）：以 prop 帶入頁面載入時的用量，
// 之後每次上傳或錄音由 uploadMedia() 更新，老師不必等到被擋下才知道快滿了。
const props = defineProps<{ quota: UploadQuota }>();

watch(() => props.quota, setUploadQuota, { immediate: true });

const quota = computed(() => uploadQuota.value ?? props.quota);
const ratio = computed(() =>
    quota.value.limit_bytes === null
        ? null
        : quota.value.limit_bytes === 0
          ? 1
          : Math.min(1, quota.value.used_bytes / quota.value.limit_bytes),
);
const level = computed(() =>
    ratio.value === null
        ? 'unlimited'
        : ratio.value >= 1
          ? 'full'
          : ratio.value >= WARN_RATIO
            ? 'warn'
            : 'ok',
);
const label = computed(() => {
    const used = formatBytes(quota.value.used_bytes);
    return quota.value.limit_bytes === null
        ? `已上傳 ${used}（管理員不限總量）`
        : `已上傳 ${used}／${Math.round(quota.value.limit_bytes / MB)} MB`;
});
</script>

<template>
    <div
        class="flex flex-col gap-1.5 text-sm"
        data-test="upload-quota"
        :data-used-bytes="quota.used_bytes"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
            <span
                :class="
                    level === 'full'
                        ? 'font-medium text-destructive'
                        : 'text-muted-foreground'
                "
                >{{ label }}</span
            >
            <span
                v-if="level === 'full'"
                class="text-destructive"
                role="status"
                data-test="upload-quota-full"
            >
                已到達上限，不能再上傳；需要更多空間請聯絡網站管理員。
            </span>
            <span
                v-else-if="level === 'warn'"
                class="text-amber-700 dark:text-amber-400"
                role="status"
                data-test="upload-quota-warn"
            >
                快到上限了。
            </span>
        </div>
        <div
            v-if="ratio !== null"
            class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
            role="meter"
            aria-label="上傳的總量"
            :aria-valuenow="Math.round(ratio * 100)"
            aria-valuemin="0"
            aria-valuemax="100"
        >
            <div
                class="h-full rounded-full transition-[width] duration-300"
                :class="
                    level === 'full'
                        ? 'bg-destructive'
                        : level === 'warn'
                          ? 'bg-amber-500'
                          : 'bg-primary/60'
                "
                :style="{ width: `${Math.round(ratio * 100)}%` }"
            />
        </div>
    </div>
</template>
