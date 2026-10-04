<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronRight,
    CircleCheck,
    CircleMinus,
    CircleX,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
import ActivityResultsController from '@/actions/App/Http/Controllers/ActivityResultsController';
import SetController from '@/actions/App/Http/Controllers/SetController';
import Heading from '@/components/Heading.vue';
import ResultFace from '@/components/kancil/ResultFace.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type {
    AttemptDetail,
    AttemptRow,
    Paginated,
    QuestionResult,
    SetKind,
} from '@/types/kancil';

// 老師檢視活動的作答紀錄與逐題答錯率（docs/SPEC.md T-11）。
// 對錯一律以伺服器的判定為準，每題以第一筆作答計算（7.4）。
const props = defineProps<{
    activity: {
        id: string;
        game_id: string;
        game_title: string;
        scored: boolean;
    };
    set: { id: string; title: string; kind: SetKind; language: string };
    revisions: {
        id: string;
        number: number;
        attempts_count: number;
        current: boolean;
    }[];
    revision: string | null;
    summary: {
        attempts: number;
        completed: number;
        average_rate: number | null;
    };
    questions: QuestionResult[];
    attempts: Paginated<AttemptRow>;
    detail: AttemptDetail | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: '我的題組', href: SetController.index() }],
    },
});

const percent = (value: number) => `${Math.round(value * 100)}%`;
const wrongRate = (q: QuestionResult) =>
    q.answered === 0 ? null : q.wrong / q.answered;

// 題組有多個版本時要標示出來（docs/SPEC.md 3.3）
const multipleRevisions = computed(() => props.revisions.length > 1);
const shownRevisions = computed(() =>
    props.revisions.filter((r) => !props.revision || r.id === props.revision),
);
const newestShown = computed(() => shownRevisions.value[0]?.number ?? 0);

function filterRevision(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    router.get(
        ActivityResultsController.url(props.activity.id, {
            query: value ? { revision: value } : {},
        }),
        {},
        { preserveScroll: true },
    );
}

// 逐題答錯率：預設把最多人答錯的題目排在前面
const sortByRate = ref(true);
const sortedQuestions = computed(() => {
    if (!sortByRate.value) {
        return props.questions;
    }
    return [...props.questions].sort(
        (a, b) => (wrongRate(b) ?? -1) - (wrongRate(a) ?? -1),
    );
});

// 作答明細：展開時以 partial reload 取得
const expanded = ref<string | null>(props.detail?.id ?? null);
const loading = ref<string | null>(null);
watch(
    () => props.detail,
    (detail) => {
        if (detail && loading.value === detail.id) {
            loading.value = null;
        }
    },
);

function toggle(attempt: AttemptRow): void {
    if (expanded.value === attempt.id) {
        expanded.value = null;
        return;
    }
    expanded.value = attempt.id;
    if (props.detail?.id !== attempt.id) {
        loading.value = attempt.id;
        router.reload({
            only: ['detail'],
            data: { attempt: attempt.id },
            onFinish: () => {
                loading.value = null;
            },
        });
    }
}

const dateTime = (iso: string) =>
    new Date(iso).toLocaleString('zh-TW', {
        dateStyle: 'short',
        timeStyle: 'short',
    });

function duration(ms: number | null): string {
    if (ms === null) {
        return '—';
    }
    const seconds = Math.round(ms / 1000);
    return seconds < 60
        ? `${seconds} 秒`
        : `${Math.floor(seconds / 60)} 分 ${seconds % 60} 秒`;
}
</script>

<template>
    <Head :title="`作答結果｜${set.title}`" />

    <div class="flex max-w-5xl flex-col gap-6 p-4">
        <div>
            <Link
                :href="ActivityController.show(activity.id)"
                class="text-sm text-muted-foreground hover:underline"
            >
                ← {{ set.title }}｜{{ activity.game_title }}
            </Link>
            <Heading title="作答結果" class="mt-2" />
        </div>

        <section
            v-if="multipleRevisions"
            class="flex flex-col gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-700 dark:bg-amber-950/40"
        >
            <p>
                這些作答使用了不同版本的題組（{{
                    revisions.map((r) => `第 ${r.number} 版`).join('、')
                }}）。每次作答都依當時的版本判定對錯；逐題統計以題目合併，題目文字以較新的版本顯示。
            </p>
            <label class="flex flex-wrap items-center gap-2">
                <span class="font-medium">只看</span>
                <select
                    class="h-9 rounded-md border bg-background px-3"
                    :value="revision ?? ''"
                    @change="filterRevision"
                >
                    <option value="">全部版本</option>
                    <option v-for="r in revisions" :key="r.id" :value="r.id">
                        第 {{ r.number }} 版{{
                            r.current ? '（目前）' : ''
                        }}・{{ r.attempts_count }}
                        次作答
                    </option>
                </select>
            </label>
        </section>

        <p
            v-if="summary.attempts === 0"
            class="rounded-xl border p-6 text-muted-foreground"
        >
            還沒有人作答。把活動的連結或 QR code
            分享給學生後，作答結果會出現在這裡。
        </p>

        <template v-else>
            <dl class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border p-4">
                    <dt class="text-sm text-muted-foreground">作答次數</dt>
                    <dd class="mt-1 text-2xl font-semibold">
                        {{ summary.attempts }}
                    </dd>
                </div>
                <div class="rounded-xl border p-4">
                    <dt class="text-sm text-muted-foreground">玩完的次數</dt>
                    <dd class="mt-1 text-2xl font-semibold">
                        {{ summary.completed }}
                    </dd>
                </div>
                <div v-if="activity.scored" class="rounded-xl border p-4">
                    <dt class="text-sm text-muted-foreground">
                        平均答對率（只算玩完的）
                    </dt>
                    <dd class="mt-1 text-2xl font-semibold">
                        {{
                            summary.average_rate === null
                                ? '—'
                                : percent(summary.average_rate)
                        }}
                    </dd>
                </div>
            </dl>

            <section class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">
                        {{ activity.scored ? '逐題答錯率' : '逐題統計' }}
                    </h2>
                    <div
                        v-if="activity.scored"
                        class="inline-flex gap-1 rounded-lg bg-muted p-1 text-sm"
                    >
                        <button
                            type="button"
                            class="rounded-md px-3 py-1"
                            :class="sortByRate ? 'bg-background shadow-xs' : ''"
                            :aria-pressed="sortByRate"
                            @click="sortByRate = true"
                        >
                            最常答錯的在前
                        </button>
                        <button
                            type="button"
                            class="rounded-md px-3 py-1"
                            :class="
                                !sortByRate ? 'bg-background shadow-xs' : ''
                            "
                            :aria-pressed="!sortByRate"
                            @click="sortByRate = false"
                        >
                            題組順序
                        </button>
                    </div>
                </div>
                <p class="text-sm text-muted-foreground">
                    同一位學生在同一題答了好幾次（例如迷宮答錯後重試）時，只算第一次。
                </p>

                <!-- 以容器寬度決定欄數：側邊欄展開時，平板橫向的內容區也很窄 -->
                <ul class="@container divide-y rounded-xl border">
                    <li
                        v-for="q in sortedQuestions"
                        :key="q.entry_id"
                        class="grid grid-cols-2 gap-3 p-4 @3xl:grid-cols-[minmax(0,1fr)_11rem_minmax(0,13rem)] @3xl:items-center"
                        data-test="question-result"
                    >
                        <div
                            class="col-span-2 flex flex-col gap-1 @3xl:col-span-1"
                        >
                            <ResultFace
                                :face="q.question"
                                :lang="set.language"
                            />
                            <span
                                v-if="q.answer"
                                class="flex flex-wrap items-center gap-1 text-sm"
                            >
                                <span class="text-muted-foreground"
                                    >正解：</span
                                >
                                <ResultFace
                                    :face="q.answer"
                                    :lang="set.language"
                                />
                            </span>
                            <span class="flex flex-wrap gap-1">
                                <Badge v-if="q.changed" variant="outline"
                                    >各版本的內容不同</Badge
                                >
                                <Badge
                                    v-if="!q.revisions.includes(newestShown)"
                                    variant="outline"
                                    >第 {{ newestShown }} 版已刪除這題</Badge
                                >
                            </span>
                        </div>

                        <div v-if="activity.scored" class="text-sm">
                            <template v-if="wrongRate(q) !== null">
                                <div class="flex items-baseline gap-2">
                                    <span class="text-lg font-semibold">{{
                                        percent(wrongRate(q) ?? 0)
                                    }}</span>
                                    <span class="text-muted-foreground"
                                        >答錯 {{ q.wrong }} /
                                        {{ q.answered }}</span
                                    >
                                </div>
                                <div
                                    class="mt-1 h-2 overflow-hidden rounded-full bg-muted"
                                    role="presentation"
                                >
                                    <div
                                        class="h-full rounded-full bg-amber-500"
                                        :style="{
                                            width: percent(wrongRate(q) ?? 0),
                                        }"
                                    />
                                </div>
                            </template>
                            <span v-else class="text-muted-foreground"
                                >還沒有人作答</span
                            >
                        </div>
                        <div v-else class="text-sm">
                            看過 {{ q.responses }} 次
                        </div>

                        <div v-if="activity.scored" class="text-sm">
                            <template v-if="q.common_mistake">
                                <span class="text-muted-foreground"
                                    >最多人選錯成（{{
                                        q.common_mistake.count
                                    }}
                                    次）</span
                                >
                                <ResultFace
                                    :face="q.common_mistake.face"
                                    :lang="set.language"
                                    missing="（已刪除的選項）"
                                />
                            </template>
                        </div>
                    </li>
                </ul>
            </section>

            <section class="space-y-3">
                <h2 class="font-semibold">每次作答</h2>
                <p class="text-sm text-muted-foreground">
                    點一下可以看這次作答每一題選了什麼。題目依作答當時的題組版本顯示。
                </p>

                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="w-8 p-3"></th>
                                <th class="p-3 font-medium">開始時間</th>
                                <th class="p-3 font-medium">暱稱／座號</th>
                                <th
                                    v-if="activity.scored"
                                    class="p-3 font-medium"
                                >
                                    答對
                                </th>
                                <th class="p-3 font-medium">用時</th>
                                <th
                                    v-if="multipleRevisions"
                                    class="p-3 font-medium"
                                >
                                    版本
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <template
                                v-for="attempt in attempts.data"
                                :key="attempt.id"
                            >
                                <tr
                                    class="cursor-pointer border-t hover:bg-muted/40"
                                    data-test="attempt-row"
                                    @click="toggle(attempt)"
                                >
                                    <td class="p-3">
                                        <button
                                            type="button"
                                            class="flex"
                                            :aria-expanded="
                                                expanded === attempt.id
                                            "
                                            :aria-label="
                                                expanded === attempt.id
                                                    ? '收合'
                                                    : '展開'
                                            "
                                            @click.stop="toggle(attempt)"
                                        >
                                            <ChevronDown
                                                v-if="expanded === attempt.id"
                                                class="size-4"
                                            />
                                            <ChevronRight
                                                v-else
                                                class="size-4"
                                            />
                                        </button>
                                    </td>
                                    <td class="p-3 whitespace-nowrap">
                                        {{ dateTime(attempt.started_at) }}
                                    </td>
                                    <td class="p-3">
                                        {{ attempt.player_label ?? '—' }}
                                    </td>
                                    <td
                                        v-if="activity.scored"
                                        class="p-3 whitespace-nowrap"
                                    >
                                        <template
                                            v-if="attempt.completed_at !== null"
                                        >
                                            {{ attempt.correct_count }} /
                                            {{ attempt.round_count }}
                                        </template>
                                        <span
                                            v-else
                                            class="text-muted-foreground"
                                            >沒有玩完</span
                                        >
                                    </td>
                                    <td class="p-3 whitespace-nowrap">
                                        {{ duration(attempt.duration_ms) }}
                                    </td>
                                    <td
                                        v-if="multipleRevisions"
                                        class="p-3 whitespace-nowrap"
                                    >
                                        第 {{ attempt.revision_number }} 版
                                    </td>
                                </tr>
                                <tr
                                    v-if="expanded === attempt.id"
                                    class="bg-muted/20"
                                >
                                    <td
                                        :colspan="multipleRevisions ? 6 : 5"
                                        class="p-3"
                                    >
                                        <p
                                            v-if="
                                                loading === attempt.id ||
                                                detail?.id !== attempt.id
                                            "
                                            class="text-muted-foreground"
                                        >
                                            載入中…
                                        </p>
                                        <ul
                                            v-else
                                            class="divide-y"
                                            data-test="attempt-detail"
                                        >
                                            <li
                                                v-for="round in detail?.rounds ??
                                                []"
                                                :key="round.entry_id"
                                                class="flex flex-wrap items-start gap-x-6 gap-y-1 py-2"
                                            >
                                                <span
                                                    class="flex w-20 shrink-0 items-center gap-1"
                                                >
                                                    <template
                                                        v-if="!round.answered"
                                                    >
                                                        <CircleMinus
                                                            class="size-4 text-muted-foreground"
                                                        />
                                                        {{
                                                            activity.scored
                                                                ? '沒作答'
                                                                : '沒看過'
                                                        }}
                                                    </template>
                                                    <template
                                                        v-else-if="
                                                            round.correct ===
                                                            null
                                                        "
                                                    >
                                                        <CircleCheck
                                                            class="size-4 text-muted-foreground"
                                                        />
                                                        看過
                                                    </template>
                                                    <template
                                                        v-else-if="
                                                            round.correct
                                                        "
                                                    >
                                                        <CircleCheck
                                                            class="size-4 text-green-600"
                                                        />
                                                        答對
                                                    </template>
                                                    <template v-else>
                                                        <CircleX
                                                            class="size-4 text-red-600"
                                                        />
                                                        答錯
                                                    </template>
                                                </span>
                                                <span class="min-w-40 flex-1">
                                                    <ResultFace
                                                        :face="round.question"
                                                        :lang="set.language"
                                                    />
                                                </span>
                                                <span
                                                    v-if="
                                                        round.correct === false
                                                    "
                                                    class="flex flex-1 flex-col gap-1"
                                                >
                                                    <span
                                                        class="flex flex-wrap items-center gap-1"
                                                    >
                                                        <span
                                                            class="text-muted-foreground"
                                                            >選了：</span
                                                        >
                                                        <ResultFace
                                                            :face="
                                                                round.selected
                                                            "
                                                            :lang="set.language"
                                                            missing="（不在題組中的選項）"
                                                        />
                                                    </span>
                                                    <span
                                                        v-if="round.answer"
                                                        class="flex flex-wrap items-center gap-1"
                                                    >
                                                        <span
                                                            class="text-muted-foreground"
                                                            >正解：</span
                                                        >
                                                        <ResultFace
                                                            :face="round.answer"
                                                            :lang="set.language"
                                                        />
                                                    </span>
                                                </span>
                                                <span
                                                    v-if="round.tries > 1"
                                                    class="text-muted-foreground"
                                                    >共作答
                                                    {{ round.tries }} 次</span
                                                >
                                            </li>
                                        </ul>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <nav
                    v-if="attempts.last_page > 1"
                    class="flex items-center justify-between gap-2 text-sm"
                    aria-label="作答紀錄分頁"
                >
                    <span class="text-muted-foreground"
                        >第 {{ attempts.from }}–{{ attempts.to }} 筆，共
                        {{ attempts.total }} 筆</span
                    >
                    <span class="flex gap-2">
                        <Button
                            v-if="attempts.prev_page_url"
                            as-child
                            variant="outline"
                            size="sm"
                        >
                            <Link :href="attempts.prev_page_url" preserve-scroll
                                >上一頁</Link
                            >
                        </Button>
                        <Button
                            v-if="attempts.next_page_url"
                            as-child
                            variant="outline"
                            size="sm"
                        >
                            <Link :href="attempts.next_page_url" preserve-scroll
                                >下一頁</Link
                            >
                        </Button>
                    </span>
                </nav>
            </section>
        </template>
    </div>
</template>
