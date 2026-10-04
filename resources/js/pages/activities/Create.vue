<script setup lang="ts">
import { check } from '@kancil-quiz/deck';
import type { CompatibilityReport } from '@kancil-quiz/deck';
import type { KancilSet } from '@kancil-quiz/schema';
import { Head, useForm } from '@inertiajs/vue3';
import { CheckCircle2, CircleAlert, Eye } from '@lucide/vue';
import { computed, ref } from 'vue';
import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
import SetController from '@/actions/App/Http/Controllers/SetController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ActivitySettingsFields from '@/components/kancil/ActivitySettingsFields.vue';
import GameOptionsForm from '@/components/kancil/GameOptionsForm.vue';
import { Button } from '@/components/ui/button';
import type {
    ActivitySettings,
    GameInfo,
    OptionValues,
    SetKind,
} from '@/types/kancil';

const props = defineProps<{
    set: { id: string; title: string; kind: SetKind };
    content: KancilSet | null;
    games: GameInfo[];
    // 預設：不必輸入名字，今天起開放一週（docs/SPEC.md 3.4）
    settings: ActivitySettings;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: '我的題組', href: SetController.index() }],
    },
});

// 只能選與題組相容的遊戲；不相容的列出原因（docs/SPEC.md 7.3、T-08）
const reports = computed(() =>
    Object.fromEntries(
        props.games.map((game): [string, CompatibilityReport] => [
            game.id,
            props.content
                ? check(props.content, game.requires)
                : {
                      ok: false,
                      issues: [
                          {
                              severity: 'error',
                              code: 'too-few-rounds',
                              message: '題組還沒有內容',
                          },
                      ],
                  },
        ]),
    ),
);

const selected = ref<GameInfo | null>(
    props.games.find((g) => reports.value[g.id].ok) ?? null,
);
const form = useForm({
    game_id: selected.value?.id ?? '',
    options: { ...selected.value?.defaultOptions } as OptionValues,
    ...props.settings,
});
const settings = computed<ActivitySettings>({
    get: () => ({
        require_label: form.require_label,
        opens_at: form.opens_at,
        closes_at: form.closes_at,
    }),
    set: (value) => Object.assign(form, value),
});

// 建立之前，以目前的遊戲與設定在新分頁試玩，不留作答紀錄（docs/SPEC.md T-08）
const previewUrl = computed(() =>
    selected.value
        ? ActivityController.preview.url(props.set.id, {
              query: {
                  game: selected.value.id,
                  options: JSON.stringify(form.options),
                  ...(form.require_label ? { require_label: 1 } : {}),
              },
          })
        : null,
);

function choose(game: GameInfo): void {
    if (!reports.value[game.id].ok) {
        return;
    }
    selected.value = game;
    form.game_id = game.id;
    form.options = { ...game.defaultOptions };
}
</script>

<template>
    <Head :title="`為「${set.title}」選遊戲`" />

    <form
        class="flex max-w-4xl flex-col gap-6 p-4"
        @submit.prevent="form.submit(ActivityController.store(set.id))"
    >
        <Heading
            :title="`為「${set.title}」選遊戲`"
            description="同一個題組可以建立多個活動，各用不同的遊戲。"
        />

        <ul class="grid gap-3 sm:grid-cols-2">
            <li v-for="game in games" :key="game.id">
                <button
                    type="button"
                    class="h-full w-full rounded-xl border p-4 text-left transition"
                    :class="[
                        selected?.id === game.id
                            ? 'border-primary ring-2 ring-primary/30'
                            : '',
                        reports[game.id].ok
                            ? 'hover:border-primary'
                            : 'cursor-not-allowed opacity-70',
                    ]"
                    :aria-disabled="!reports[game.id].ok"
                    @click="choose(game)"
                >
                    <span class="flex items-center gap-2 text-lg font-semibold">
                        <CheckCircle2
                            v-if="reports[game.id].ok"
                            class="size-5 text-green-600"
                        />
                        <CircleAlert v-else class="size-5 text-amber-600" />
                        {{ game.title['zh-TW'] }}
                    </span>
                    <ul class="mt-2 space-y-1 text-sm">
                        <li
                            v-for="issue in reports[game.id].issues"
                            :key="issue.message"
                            :class="
                                issue.severity === 'error'
                                    ? 'text-amber-700 dark:text-amber-400'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ issue.message }}
                        </li>
                        <li
                            v-if="reports[game.id].issues.length === 0"
                            class="text-muted-foreground"
                        >
                            可以使用
                        </li>
                    </ul>
                </button>
            </li>
        </ul>
        <InputError :message="form.errors.game_id" />

        <section v-if="selected" class="space-y-4 rounded-xl border p-4">
            <h2 class="font-semibold">{{ selected.title['zh-TW'] }}的設定</h2>
            <GameOptionsForm
                :key="selected.id"
                v-model="form.options"
                :game="selected"
            />
            <InputError :message="form.errors.options" />
        </section>

        <section class="space-y-4 rounded-xl border p-4">
            <div>
                <h2 class="font-semibold">給學生的設定</h2>
                <p class="text-sm text-muted-foreground">
                    建立之後也可以在活動頁修改。
                </p>
            </div>
            <ActivitySettingsFields v-model="settings" :errors="form.errors" />
        </section>

        <div class="flex flex-wrap gap-2">
            <Button type="submit" :disabled="!selected || form.processing"
                >建立活動</Button
            >
            <Button v-if="previewUrl" as-child variant="outline">
                <a :href="previewUrl" target="_blank" rel="noopener"
                    ><Eye class="size-4" /> 先試玩（不留紀錄）</a
                >
            </Button>
        </div>
    </form>
</template>
