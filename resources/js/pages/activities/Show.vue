<script setup lang="ts">
import { check } from '@kancil-quiz/deck';
import type { KancilSet } from '@kancil-quiz/schema';
import { Head, Link, router } from '@inertiajs/vue3';
import { Copy, ExternalLink, Eye, Presentation, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
import SetController from '@/actions/App/Http/Controllers/SetController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import type { GameInfo, SetKind } from '@/types/kancil';

const props = defineProps<{
    activity: {
        id: string;
        game_id: string;
        options: Record<string, string | number | boolean | null>;
        created_at: string | null;
        attempts_count: number;
    };
    set: { id: string; title: string; kind: SetKind };
    content: KancilSet | null;
    games: GameInfo[];
    siblings: { id: string; game_id: string }[];
    playUrl: string;
    qrSvg: string;
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

// 同一題組一鍵換成其他遊戲（docs/SPEC.md T-10）：只列出相容的遊戲
const otherGames = computed(() =>
    props.games.filter(
        (g) =>
            g.id !== props.activity.game_id &&
            props.content &&
            check(props.content, g.requires).ok,
    ),
);

const copied = ref(false);
async function copy(): Promise<void> {
    await navigator.clipboard.writeText(props.playUrl);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}

function switchTo(gameId: string): void {
    router.post(ActivityController.store(props.set.id), { game_id: gameId });
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
                :href="SetController.edit(set.id)"
                class="text-sm text-muted-foreground hover:underline"
            >
                ← {{ set.title }}
            </Link>
            <Heading
                :title="`${game?.title['zh-TW'] ?? activity.game_id}`"
                :description="`已有 ${activity.attempts_count} 次作答`"
                class="mt-2"
            />
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

        <section v-if="otherGames.length > 0" class="space-y-2">
            <h2 class="font-semibold">換成其他遊戲</h2>
            <p class="text-sm text-muted-foreground">
                用同一個題組建立新的活動，不需要重新出題。
            </p>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="other in otherGames"
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
