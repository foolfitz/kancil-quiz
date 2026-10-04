<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check, Copy, Download, Gamepad2, Pencil } from '@lucide/vue';
import { ref } from 'vue';
import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
import SetController from '@/actions/App/Http/Controllers/SetController';
import SetCopyController from '@/actions/App/Http/Controllers/SetCopyController';
import SetExportController from '@/actions/App/Http/Controllers/SetExportController';
import SetReviewController from '@/actions/App/Http/Controllers/SetReviewController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ResultFace from '@/components/kancil/ResultFace.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { library } from '@/routes';
import { KIND_NAMES, curriculumLabel } from '@/types/kancil';
import type {
    RevisionEntry,
    SetReviewEntry,
    SetView,
    SetViewEntry,
} from '@/types/kancil';

// 題組的唯讀檢視：共備庫、同事的分享連結、審核都用這一頁（docs/SPEC.md T-12、T-13、T-17、C-01）。
const props = defineProps<{
    set: SetView;
    entries: SetViewEntry[];
    can: {
        copy: boolean;
        manage: boolean;
        activity: boolean;
        edit: boolean;
        review: boolean;
        export: boolean;
    };
    token: string | null;
    reviews: SetReviewEntry[];
    revisions: RevisionEntry[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: '共備庫', href: library() }],
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

// 審核（docs/SPEC.md C-01）：退回與下架要附上意見
const review = useForm({ decision: '', note: '' });
function decide(decision: 'approve' | 'reject' | 'unpublish'): void {
    review.decision = decision;
    review.submit(SetReviewController.store(props.set.id), {
        preserveScroll: true,
        onSuccess: () => review.reset(),
    });
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
                    <Badge v-if="set.textbook_url">教材</Badge>
                    <Badge v-else-if="set.visibility === 'public'"
                        >已公開</Badge
                    >
                </div>
                <Heading
                    :title="set.title"
                    :description="set.description ?? undefined"
                    class="mt-2"
                />
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="can.copy"
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
                <Button v-if="can.activity" as-child variant="outline">
                    <Link :href="ActivityController.create(set.id)"
                        ><Gamepad2 class="size-4" /> 選遊戲、建立活動</Link
                    >
                </Button>
                <Button v-if="can.export" as-child variant="outline">
                    <a
                        :href="
                            token
                                ? SetExportController.shared.url(token)
                                : SetExportController.show.url(set.id)
                        "
                        data-test="export-set"
                        ><Download class="size-4" /> 下載 zip</a
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
                <dd>
                    <Link
                        v-if="set.textbook_url"
                        :href="set.textbook_url"
                        class="underline-offset-4 hover:underline"
                        >{{
                            set.curriculum.map(curriculumLabel).join('、')
                        }}</Link
                    >
                    <template v-else>{{
                        set.curriculum.map(curriculumLabel).join('、')
                    }}</template>
                </dd>
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

        <section
            v-if="can.review"
            class="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-950/40"
            data-test="review-panel"
        >
            <h2 class="font-semibold">審核</h2>
            <p v-if="set.review_status === 'pending'" class="text-sm">
                {{ set.owner }}
                申請把這個題組公開到共備庫。請確認內容正確、適合學生，作者與授權也沒有問題。
            </p>
            <p v-else class="text-sm">
                這個題組已經公開。內容有小錯誤時可以直接「修正內容」；不適合公開時，附上原因下架。
            </p>
            <textarea
                v-model="review.note"
                rows="3"
                maxlength="2000"
                class="w-full rounded-md border bg-background p-2 text-base md:text-sm"
                placeholder="給擁有者的意見（退回、下架時必填）"
            />
            <InputError :message="review.errors.note" />
            <div class="flex flex-wrap gap-2">
                <template v-if="set.review_status === 'pending'">
                    <Button
                        type="button"
                        :disabled="review.processing"
                        @click="decide('approve')"
                        >通過並公開</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="review.processing"
                        @click="decide('reject')"
                        >退回</Button
                    >
                </template>
                <Button
                    v-else
                    type="button"
                    variant="outline"
                    class="text-destructive"
                    :disabled="review.processing"
                    @click="decide('unpublish')"
                    >下架</Button
                >
            </div>
        </section>

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

        <section
            v-if="revisions.length > 0"
            id="revisions"
            class="scroll-mt-4 space-y-2"
            data-test="revisions"
        >
            <h2 class="font-semibold">修訂紀錄</h2>
            <p class="text-sm text-muted-foreground">
                每次儲存都會產生一個版本，舊的作答紀錄依當時的版本判定。
            </p>
            <ol class="space-y-2 text-sm">
                <li
                    v-for="revision in revisions"
                    :key="revision.number"
                    class="rounded-lg border p-3"
                >
                    <div>
                        <span class="font-medium"
                            >第 {{ revision.number }} 版</span
                        >
                        <span class="text-muted-foreground">
                            ・{{ revision.created_by ?? '—'
                            }}<template v-if="revision.created_at"
                                >・{{ dateTime(revision.created_at) }}</template
                            ></span
                        >
                    </div>
                    <ul class="mt-1 space-y-1">
                        <li
                            v-for="(change, i) in revision.changes"
                            :key="i"
                            class="break-words"
                        >
                            <span
                                class="mr-1 inline-block rounded px-1.5 text-xs"
                                :class="{
                                    'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300':
                                        change.kind === 'added',
                                    'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300':
                                        change.kind === 'removed',
                                    'bg-muted': !['added', 'removed'].includes(
                                        change.kind,
                                    ),
                                }"
                                >{{ change.label }}</span
                            >
                            <template
                                v-if="
                                    change.before !== null &&
                                    change.after !== null
                                "
                            >
                                <span
                                    class="text-muted-foreground line-through"
                                    >{{ change.before }}</span
                                >
                                → {{ change.after }}
                            </template>
                            <template v-else>{{
                                change.after ?? change.before ?? ''
                            }}</template>
                        </li>
                        <li
                            v-if="revision.changes.length === 0"
                            class="text-muted-foreground"
                        >
                            沒有內容上的差異
                        </li>
                    </ul>
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
