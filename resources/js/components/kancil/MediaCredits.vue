<script setup lang="ts">
import { computed, inject } from 'vue';
import { Input } from '@/components/ui/input';
import { MEDIA_CONTEXT } from '@/lib/media';
import { licenseName } from '@/types/kancil';
import type { MediaRef } from '@/types/kancil';

// 一題所有媒體的署名（docs/SPEC.md 第 9 節）：收合時只顯示摘要，展開後逐個修改作者、出處與授權。
// 自己上傳的才能改；別人上傳的（教材的插圖、複製來的題組的媒體）只顯示，不會被蓋掉。
const props = defineProps<{
    media: { label: string; ref: MediaRef }[];
}>();
const emit = defineEmits<{
    update: [
        media: MediaRef,
        patch: Partial<Pick<MediaRef, 'author' | 'source' | 'license'>>,
    ];
}>();

const context = inject(MEDIA_CONTEXT, null);

// 授權選單：老師能選的授權，加上媒體原本的授權（例如沿用舊資料的）
const licenses = computed(() => {
    const base = context?.value.licenses ?? [];
    const extra = props.media
        .map((item) => item.ref.license)
        .filter((license): license is string =>
            Boolean(license && !base.includes(license)),
        );
    return [...base, ...new Set(extra)];
});

const inherited = computed(() =>
    context ? `沿用題組（${licenseName(context.value.license)}）` : '沿用題組',
);

function describe(media: MediaRef): string {
    return [
        media.author,
        media.license ? licenseName(media.license) : inherited.value,
        media.source,
    ]
        .filter(Boolean)
        .join('・');
}

const summary = computed(() =>
    [...new Set(props.media.map((item) => describe(item.ref)))].join('；'),
);
</script>

<template>
    <details class="text-sm" data-test="media-credits">
        <summary class="cursor-pointer text-muted-foreground">
            <span class="font-medium text-foreground">署名</span>
            <span data-test="media-credits-summary">：{{ summary }}</span>
        </summary>
        <ul class="mt-2 space-y-2">
            <li
                v-for="item in media"
                :key="item.ref.id"
                class="grid gap-1 sm:grid-cols-[6rem_1fr_1fr_auto] sm:items-center"
            >
                <span class="text-muted-foreground">{{ item.label }}</span>
                <template v-if="item.ref.editable">
                    <Input
                        :model-value="item.ref.author"
                        class="h-8"
                        placeholder="作者"
                        :aria-label="`${item.label}的作者`"
                        @update:model-value="
                            emit('update', item.ref, {
                                author: String($event),
                            })
                        "
                    />
                    <Input
                        :model-value="item.ref.source ?? ''"
                        class="h-8"
                        placeholder="出處（選填）"
                        :aria-label="`${item.label}的出處`"
                        @update:model-value="
                            emit('update', item.ref, {
                                source: String($event) || null,
                            })
                        "
                    />
                    <select
                        :value="item.ref.license ?? ''"
                        class="h-8 rounded-md border bg-transparent px-2 text-sm"
                        :aria-label="`${item.label}的授權`"
                        @change="
                            emit('update', item.ref, {
                                license:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">{{ inherited }}</option>
                        <option
                            v-for="license in licenses"
                            :key="license"
                            :value="license"
                        >
                            {{ licenseName(license) }}
                        </option>
                    </select>
                </template>
                <span v-else class="sm:col-span-3">
                    {{ describe(item.ref) }}
                    <span class="text-muted-foreground"
                        >（別人上傳的，不能在這裡修改）</span
                    >
                </span>
            </li>
        </ul>
    </details>
</template>
