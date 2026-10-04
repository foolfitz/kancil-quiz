<script setup lang="ts">
import { check, groupGames } from '@kancil-quiz/deck';
import type { KancilSet } from '@kancil-quiz/schema';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ChartColumn,
    Copy,
    ExternalLink,
    Eye,
    Pencil,
    Presentation,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
import ActivityResultsController from '@/actions/App/Http/Controllers/ActivityResultsController';
import SetController from '@/actions/App/Http/Controllers/SetController';
import Heading from '@/components/Heading.vue';
import ActivitySettingsFields from '@/components/kancil/ActivitySettingsFields.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { STATUS_NAMES, localTime } from '@/types/kancil';
import type {
    ActivitySettings,
    ActivitySettingsView,
    GameInfo,
    SetKind,
} from '@/types/kancil';

const props = defineProps<{
    activity: {
        id: string;
        game_id: string;
        options: Record<string, string | number | boolean | null>;
        created_at: string | null;
        attempts_count: number;
        settings: ActivitySettingsView;
    };
    set: { id: string; title: string; kind: SetKind; url: string };
    content: KancilSet | null;
    games: GameInfo[];
    siblings: { id: string; game_id: string }[];
    playUrl: string;
    qrSvg: string;
    defaults: ActivitySettings;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: '我的題組', href: SetController.index() }],
    },
});

const game = computed(() =>
    props.games.find((g) => g.id === props.activity.game_id),
);
const gameTitle = (id: string) =>
    props.games.find((g) => g.id === id)?.title['zh-TW'] ?? id;

// 同一題組一鍵換成其他遊戲（docs/SPEC.md T-10）：只列出相容的遊戲，依分類分組（7.5）
const otherGames = computed(() =>
    groupGames(
        props.games.filter(
            (g) =>
                g.id !== props.activity.game_id &&
                props.content &&
                check(props.content, g.requires).ok,
        ),
    ),
);

const copied = ref(false);
async function copy(): Promise<void> {
    await navigator.clipboard.writeText(props.playUrl);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}

// 換成其他遊戲時沿用這個活動的設定；已截止的改用預設時間，否則新活動一建立就截止
function switchTo(gameId: string): void {
    const settings = props.activity.settings;
    const times = settings.status === 'closed' ? props.defaults : settings;
    router.post(ActivityController.store(props.set.id), {
        game_id: gameId,
        require_label: settings.require_label,
        opens_at: times.opens_at,
        closes_at: times.closes_at,
    });
}

// 給學生的設定（docs/SPEC.md 3.4）：隨時可以修改
const editing = ref(false);
const settingsForm = useForm<ActivitySettings>({
    require_label: false,
    opens_at: null,
    closes_at: null,
});
const editedSettings = computed<ActivitySettings>({
    get: () => ({
        require_label: settingsForm.require_label,
        opens_at: settingsForm.opens_at,
        closes_at: settingsForm.closes_at,
    }),
    set: (value) => Object.assign(settingsForm, value),
});
function edit(): void {
    const { require_label, opens_at, closes_at } = props.activity.settings;
    Object.assign(settingsForm, { require_label, opens_at, closes_at });
    settingsForm.clearErrors();
    editing.value = true;
}
function saveSettings(): void {
    settingsForm.patch(ActivityController.update.url(props.activity.id), {
        preserveScroll: true,
        onSuccess: () => (editing.value = false),
    });
}
function closeNow(): void {
    if (
        window.confirm(
            '確定要立即截止？之後學生不能再開始作答，已經開始的仍可以送完。',
        )
    ) {
        router.post(
            ActivityController.close.url(props.activity.id),
            {},
            { preserveScroll: true },
        );
    }
}

function destroy(): void {
    if (window.confirm('確定要刪除這個活動？已經發出去的連結將無法再使用。')) {
        router.delete(ActivityController.destroy(props.activity.id));
    }
}

// 投影模式：大字、全螢幕（docs/SPEC.md T-09）
const projecting = ref(false);
const projection = ref<HTMLElement | null>(null);
async function project(): Promise<void> {
    projecting.value = true;
    await new Promise((resolve) => requestAnimationFrame(resolve));
    await projection.value?.requestFullscreen?.().catch(() => undefined);
}
function stopProjecting(): void {
    projecting.value = false;
    if (document.fullscreenElement) {
        void document.exitFullscreen();
    }
}
const onFullscreenChange = () => {
    if (!document.fullscreenElement) {
        projecting.value = false;
    }
};
document.addEventListener('fullscreenchange', onFullscreenChange);
onBeforeUnmount(() =>
    document.removeEventListener('fullscreenchange', onFullscreenChange),
);
</script>

<template>
    <Head
        :title="`${set.title}｜${game?.title['zh-TW'] ?? activity.game_id}`"
    />

    <div class="flex max-w-4xl flex-col gap-6 p-4">
        <div>
            <Link
                :href="set.url"
                class="text-sm text-muted-foreground hover:underline"
            >
                ← {{ set.title }}
            </Link>
            <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
                <Heading
                    :title="`${game?.title['zh-TW'] ?? activity.game_id}`"
                    :description="`已有 ${activity.attempts_count} 次作答`"
                />
                <Button as-child variant="outline">
                    <Link :href="ActivityResultsController(activity.id)"
                        ><ChartColumn class="size-4" /> 作答結果</Link
                    >
                </Button>
            </div>
        </div>

        <section
            class="grid gap-6 rounded-xl border p-4 md:grid-cols-[auto_1fr]"
        >
            <div
                class="mx-auto size-48 rounded-lg bg-white p-2 [&_svg]:size-full"
                v-html="qrSvg"
            />
            <div class="flex flex-col gap-3">
                <h2 class="font-semibold">分享給學生</h2>
                <p class="text-sm text-muted-foreground">
                    學生用平板掃描 QR code，或開啟下面的連結就能玩，不需要帳號。
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <code
                        class="rounded bg-muted px-2 py-1 text-sm break-all"
                        >{{ playUrl }}</code
                    >
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="copy"
                    >
                        <Copy class="size-4" />
                        {{ copied ? '已複製' : '複製連結' }}
                    </Button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button type="button" @click="project"
                        ><Presentation class="size-4" /> 投影</Button
                    >
                    <Button as-child variant="outline">
                        <a
                            :href="`${playUrl}?preview=1`"
                            target="_blank"
                            rel="noopener"
                            ><Eye class="size-4" /> 預覽（不留紀錄）</a
                        >
                    </Button>
                    <Button as-child variant="ghost">
                        <a :href="playUrl" target="_blank" rel="noopener"
                            ><ExternalLink class="size-4" /> 開啟</a
                        >
                    </Button>
                </div>
            </div>
        </section>

        <section
            class="space-y-3 rounded-xl border p-4"
            data-test="activity-settings"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 font-semibold">
                    給學生的設定
                    <Badge
                        :variant="
                            activity.settings.status === 'open'
                                ? 'default'
                                : 'secondary'
                        "
                        data-test="activity-status"
                        >{{ STATUS_NAMES[activity.settings.status] }}</Badge
                    >
                </h2>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="activity.settings.status !== 'closed'"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="closeNow"
                        >立即截止</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="edit"
                        ><Pencil class="size-4" /> 修改設定</Button
                    >
                </div>
            </div>
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground">名字或座號</dt>
                    <dd>
                        {{
                            activity.settings.require_label
                                ? '學生要先輸入'
                                : '不需要（不記名）'
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">開放時間</dt>
                    <dd>
                        {{
                            activity.settings.opens_at
                                ? localTime(activity.settings.opens_at)
                                : '立刻開放'
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">截止時間</dt>
                    <dd>
                        {{
                            activity.settings.closes_at
                                ? localTime(activity.settings.closes_at)
                                : '不截止'
                        }}
                    </dd>
                </div>
            </dl>
        </section>

        <Dialog v-model:open="editing">
            <DialogContent>
                <form class="space-y-6" @submit.prevent="saveSettings">
                    <DialogHeader>
                        <DialogTitle>給學生的設定</DialogTitle>
                        <DialogDescription>
                            修改後立即生效，已經發出去的連結不變。
                        </DialogDescription>
                    </DialogHeader>
                    <ActivitySettingsFields
                        v-model="editedSettings"
                        :errors="settingsForm.errors"
                    />
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="secondary"
                                >取消</Button
                            >
                        </DialogClose>
                        <Button
                            type="submit"
                            :disabled="settingsForm.processing"
                            >儲存</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <section v-if="otherGames.length > 0" class="space-y-2">
            <h2 class="font-semibold">換成其他遊戲</h2>
            <p class="text-sm text-muted-foreground">
                用同一個題組建立新的活動，不需要重新出題。
            </p>
            <div
                v-for="group in otherGames"
                :key="group.category.id"
                class="flex flex-wrap items-center gap-2"
            >
                <span class="w-20 text-sm text-muted-foreground">{{
                    group.category.title
                }}</span>
                <Button
                    v-for="other in group.games"
                    :key="other.id"
                    type="button"
                    variant="secondary"
                    @click="switchTo(other.id)"
                >
                    {{ other.title['zh-TW'] }}
                </Button>
            </div>
        </section>

        <section v-if="siblings.length > 0" class="space-y-2">
            <h2 class="font-semibold">這個題組的其他活動</h2>
            <ul class="flex flex-wrap gap-2">
                <li v-for="sibling in siblings" :key="sibling.id">
                    <Link
                        :href="ActivityController.show(sibling.id)"
                        class="inline-flex rounded-full border px-3 py-1 text-sm hover:border-primary"
                    >
                        {{ gameTitle(sibling.game_id) }}
                    </Link>
                </li>
            </ul>
        </section>

        <div>
            <Button
                type="button"
                variant="ghost"
                class="text-destructive"
                @click="destroy"
                >刪除活動</Button
            >
        </div>

        <div
            v-if="projecting"
            ref="projection"
            class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-6 bg-white p-8 text-black"
        >
            <button
                type="button"
                class="absolute top-4 right-4 rounded-full p-2 hover:bg-black/10"
                title="關閉投影"
                @click="stopProjecting"
            >
                <X class="size-8" />
            </button>
            <h1 class="text-center text-5xl font-bold">{{ set.title }}</h1>
            <p class="text-3xl">{{ game?.title['zh-TW'] }}</p>
            <div
                class="aspect-square h-[55vh] [&_svg]:size-full"
                v-html="qrSvg"
            />
            <p class="text-3xl font-semibold break-all">{{ playUrl }}</p>
        </div>
    </div>
</template>
