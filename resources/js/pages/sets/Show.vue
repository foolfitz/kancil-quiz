<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, Copy, Gamepad2, Pencil } from '@lucide/vue';
import { ref } from 'vue';
import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
import SetController from '@/actions/App/Http/Controllers/SetController';
import SetCopyController from '@/actions/App/Http/Controllers/SetCopyController';
import Heading from '@/components/Heading.vue';
import ResultFace from '@/components/kancil/ResultFace.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { KIND_NAMES, curriculumLabel } from '@/types/kancil';
import type { SetReviewEntry, SetView, SetViewEntry } from '@/types/kancil';

// 題組的唯讀檢視：共備庫、同事的分享連結、審核都用這一頁（docs/SPEC.md T-12、T-13、T-17、C-01）。
const props = defineProps<{
    set: SetView;
    entries: SetViewEntry[];
    can: { manage: boolean; edit: boolean; review: boolean };
    token: string | null;
    reviews: SetReviewEntry[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: '我的題組', href: SetController.index() }],
    },
});

const copying = ref(false);
function copy(): void {
    router.post(
        SetCopyController(props.set.id),
        props.token ? { token: props.token } : {},
        {
            onStart: () => (copying.value = true),
            onFinish: () => (copying.value = false),
        },
    );
}

const dateTime = (iso: string) =>
    new Date(iso).toLocaleString('zh-TW', {
        dateStyle: 'short',
        timeStyle: 'short',
    });
</script>

<template>
    <Head :title="set.title" />

    <div class="flex max-w-4xl flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge variant="secondary">{{
                        KIND_NAMES[set.kind]
                    }}</Badge>
                    <Badge variant="outline">{{ set.language.name_zh }}</Badge>
                    <Badge v-if="set.visibility === 'public'">已公開</Badge>
                </div>
                <Heading
                    :title="set.title"
                    :description="set.description ?? undefined"
                    class="mt-2"
                />
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    :disabled="copying"
                    data-test="copy-set"
                    @click="copy"
                >
                    <Copy class="size-4" />
                    {{ can.manage ? '複製一份' : '複製到我的題組' }}
                </Button>
                <Button v-if="can.edit" as-child variant="outline">
                    <Link :href="SetController.edit(set.id)"
                        ><Pencil class="size-4" />
                        {{ can.manage ? '編輯' : '修正內容' }}</Link
                    >
                </Button>
                <Button v-if="can.manage" as-child variant="outline">
                    <Link :href="ActivityController.create(set.id)"
                        ><Gamepad2 class="size-4" /> 選遊戲、建立活動</Link
                    >
                </Button>
            </div>
        </div>

        <dl
            class="grid gap-x-6 gap-y-2 rounded-xl border p-4 text-sm sm:grid-cols-[auto_1fr]"
        >
            <dt class="text-muted-foreground">作者</dt>
            <dd>{{ set.authors.join('、') }}</dd>
            <dt class="text-muted-foreground">授權</dt>
            <dd>{{ set.license }}</dd>
            <template v-if="set.curriculum.length > 0">
                <dt class="text-muted-foreground">對應教材</dt>
                <dd>{{ set.curriculum.map(curriculumLabel).join('、') }}</dd>
            </template>
            <template v-if="set.tags.length > 0">
                <dt class="text-muted-foreground">標籤</dt>
                <dd class="flex flex-wrap gap-1">
                    <Badge
                        v-for="tag in set.tags"
                        :key="tag"
                        variant="outline"
                        >{{ tag }}</Badge
                    >
                </dd>
            </template>
            <template v-if="set.forked_from">
                <dt class="text-muted-foreground">複製自</dt>
                <dd>
                    <Link
                        v-if="set.forked_from.viewable"
                        :href="SetController.show(set.forked_from.id)"
                        class="underline-offset-4 hover:underline"
                        >「{{ set.forked_from.title }}」</Link
                    >
                    <template v-else>「{{ set.forked_from.title }}」</template>
                    （{{ set.forked_from.owner }}）
                </dd>
            </template>
            <dt class="text-muted-foreground">版本</dt>
            <dd>
                第 {{ set.revision ?? 1 }} 版<template v-if="set.updated_at"
                    >，{{ dateTime(set.updated_at) }} 更新</template
                >
            </dd>
        </dl>

        <section class="space-y-3">
            <h2 class="font-semibold">內容（{{ entries.length }} 題）</h2>
            <ol class="divide-y rounded-xl border" data-test="set-entries">
                <li
                    v-for="(entry, i) in entries"
                    :key="entry.id"
                    class="flex gap-3 p-3"
                >
                    <span class="w-6 shrink-0 text-right text-muted-foreground"
                        >{{ i + 1 }}.</span
                    >
                    <div class="flex min-w-0 flex-col gap-2">
                        <ResultFace
                            :face="entry.question"
                            :lang="set.language.code"
                        />
                        <ul
                            v-if="entry.options.length > 0"
                            class="flex flex-wrap gap-2 text-sm"
                        >
                            <li
                                v-for="(option, j) in entry.options"
                                :key="j"
                                class="inline-flex items-center gap-1 rounded-md border px-2 py-1"
                                :class="
                                    option.correct
                                        ? 'border-green-600 dark:border-green-500'
                                        : ''
                                "
                            >
                                <Check
                                    v-if="option.correct"
                                    class="size-4 text-green-600"
                                    aria-label="正解"
                                />
                                <ResultFace
                                    :face="option.face"
                                    :lang="set.language.code"
                                />
                            </li>
                        </ul>
                    </div>
                </li>
            </ol>
        </section>

        <section v-if="reviews.length > 0" class="space-y-2">
            <h2 class="font-semibold">公開申請紀錄</h2>
            <ul class="space-y-2 text-sm">
                <li
                    v-for="(review, i) in reviews"
                    :key="i"
                    class="rounded-lg border p-3"
                >
                    <span class="font-medium">{{
                        {
                            requested: '申請公開',
                            withdrawn: '撤回申請',
                            approved: '審核通過',
                            rejected: '退回',
                            unpublished: '下架',
                        }[review.action]
                    }}</span>
                    <span class="text-muted-foreground">
                        ・{{ review.user }}・{{
                            dateTime(review.created_at)
                        }}</span
                    >
                    <p v-if="review.note" class="mt-1 whitespace-pre-line">
                        {{ review.note }}
                    </p>
                </li>
            </ul>
        </section>
    </div>
</template>
