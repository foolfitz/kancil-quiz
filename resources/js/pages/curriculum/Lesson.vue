<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Copy,
    Download,
    Gamepad2,
    ListChecks,
    MonitorPlay,
    Pencil,
    Volume2,
} from '@lucide/vue';
import { ref } from 'vue';
import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
import CurriculumController from '@/actions/App/Http/Controllers/CurriculumController';
import SetController from '@/actions/App/Http/Controllers/SetController';
import SetCopyController from '@/actions/App/Http/Controllers/SetCopyController';
import SetExportController from '@/actions/App/Http/Controllers/SetExportController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { KIND_NAMES, standaloneUrl } from '@/types/kancil';
import type { SetKind, VocabEntryInput } from '@/types/kancil';

// 教材的一課（docs/SPEC.md T-18）：教材題組的詞彙，直接建立活動、複製或挑詞，
// 以及老師自己與共備庫中對應這一課的題組。
const props = defineProps<{
    lesson: {
        language: { code: string; name_zh: string };
        volume: number;
        lesson: number;
        title_zh: string | null;
        title_native: string | null;
    };
    set: {
        id: string;
        title: string;
        description: string | null;
        license: string;
        authors: string[];
        can: {
            activity: boolean;
            copy: boolean;
            edit: boolean;
            export: boolean;
        };
    } | null;
    words: VocabEntryInput[];
    imageCredits: string[];
    activities: {
        id: string;
        game: string;
        attempts_count: number;
        created_at: string | null;
    }[];
    mySets: { id: string; title: string }[];
    shared: {
        id: string;
        kind: SetKind;
        title: string;
        owner: string;
        entries_count: number;
    }[];
    libraryUrl: string;
    title: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: '教材', href: CurriculumController.index() }],
    },
});

const copying = ref(false);
function copy(): void {
    if (props.set) {
        router.post(
            SetCopyController(props.set.id),
            {},
            {
                onStart: () => (copying.value = true),
                onFinish: () => (copying.value = false),
            },
        );
    }
}

let player: HTMLAudioElement | null = null;
function play(url: string): void {
    player?.pause();
    player = new Audio(url);
    void player.play().catch(() => undefined);
}
</script>

<template>
    <Head :title="title" />

    <div class="flex max-w-5xl flex-col gap-6 p-4">
        <div>
            <Link
                :href="
                    CurriculumController.index({
                        query: { language: lesson.language.code },
                    })
                "
                class="text-sm text-muted-foreground hover:underline"
            >
                ← {{ lesson.language.name_zh }}教材
            </Link>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <Badge variant="outline">{{ lesson.language.name_zh }}</Badge>
                <span class="text-sm text-muted-foreground"
                    >第 {{ lesson.volume }} 冊第 {{ lesson.lesson }} 課</span
                >
            </div>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight">
                <span v-if="lesson.title_native" :lang="lesson.language.code">{{
                    lesson.title_native
                }}</span>
                <span
                    v-if="lesson.title_zh"
                    :class="
                        lesson.title_native
                            ? 'ml-2 text-lg font-normal text-muted-foreground'
                            : ''
                    "
                    >{{ lesson.title_zh }}</span
                >
            </h1>
        </div>

        <template v-if="set">
            <div class="flex flex-wrap gap-2">
                <Button v-if="set.can.activity" as-child>
                    <Link
                        :href="ActivityController.create(set.id)"
                        data-test="lesson-activity"
                        ><Gamepad2 class="size-4" /> 選遊戲、建立活動</Link
                    >
                </Button>
                <Button
                    v-if="set.can.copy"
                    type="button"
                    variant="outline"
                    :disabled="copying"
                    @click="copy"
                >
                    <Copy class="size-4" /> 複製成我的題組
                </Button>
                <Button as-child variant="outline">
                    <Link
                        :href="
                            SetController.create({
                                query: {
                                    language: lesson.language.code,
                                    volume: lesson.volume,
                                    lesson: lesson.lesson,
                                },
                            })
                        "
                        ><ListChecks class="size-4" /> 挑詞建立題組</Link
                    >
                </Button>
                <Button v-if="set.can.export" as-child variant="outline">
                    <a
                        :href="SetExportController.show.url(set.id)"
                        data-test="export-set"
                        ><Download class="size-4" /> 下載 zip</a
                    >
                </Button>
                <Button v-if="set.can.export" as-child variant="ghost">
                    <a
                        :href="standaloneUrl(set.id)"
                        target="_blank"
                        rel="noopener"
                        data-test="standalone-set"
                        ><MonitorPlay class="size-4" /> 用獨立播放器開啟</a
                    >
                </Button>
                <Button v-if="set.can.edit" as-child variant="ghost">
                    <Link :href="SetController.edit(set.id)"
                        ><Pencil class="size-4" /> 修正內容</Link
                    >
                </Button>
            </div>
            <p class="text-sm text-muted-foreground">
                直接建立的活動會一直使用這一課最新的詞彙。想增減詞、換題目的呈現方式，請先複製成自己的題組。
            </p>

            <section class="space-y-3">
                <h2 class="font-semibold">詞彙（{{ words.length }} 個）</h2>
                <ul
                    class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4"
                    data-test="lesson-words"
                >
                    <li
                        v-for="word in words"
                        :key="word.id ?? word.item.text"
                        class="flex flex-col overflow-hidden rounded-xl border"
                    >
                        <img
                            v-if="word.item.image"
                            :src="word.item.image.url"
                            :alt="word.item.translation_zh"
                            class="aspect-square w-full bg-muted/30 object-contain p-2"
                            loading="lazy"
                        />
                        <div class="flex items-start gap-2 p-3">
                            <div class="min-w-0 flex-1">
                                <p
                                    class="text-lg font-semibold break-words"
                                    :lang="lesson.language.code"
                                >
                                    {{ word.item.text }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{ word.item.translation_zh }}
                                </p>
                            </div>
                            <button
                                v-if="word.item.audio.length > 0"
                                type="button"
                                class="inline-flex size-9 shrink-0 items-center justify-center rounded-full hover:bg-muted"
                                title="播放發音"
                                @click="play(word.item.audio[0].url)"
                            >
                                <Volume2 class="size-4" />
                            </button>
                        </div>
                    </li>
                </ul>
                <p class="text-sm text-muted-foreground">
                    {{ set.description }}
                    <template v-if="imageCredits.length > 0"
                        >插圖：{{ imageCredits.join('；') }}。</template
                    >
                    授權：{{ set.license }}。
                </p>
            </section>
        </template>
        <p v-else class="text-muted-foreground">
            這一課還沒有匯入詞彙。你仍然可以在下面找到其他老師對應這一課的題組。
        </p>

        <section v-if="activities.length > 0" class="space-y-2">
            <h2 class="font-semibold">我用這一課建立的活動</h2>
            <ul class="flex flex-wrap gap-2">
                <li v-for="activity in activities" :key="activity.id">
                    <Link
                        :href="ActivityController.show(activity.id)"
                        class="inline-flex rounded-full border px-3 py-1 text-sm hover:border-primary"
                    >
                        {{ activity.game }}・{{ activity.attempts_count }}
                        次作答
                    </Link>
                </li>
            </ul>
        </section>

        <section v-if="mySets.length > 0" class="space-y-2">
            <h2 class="font-semibold">我的題組中對應這一課的</h2>
            <ul class="flex flex-wrap gap-2">
                <li v-for="mine in mySets" :key="mine.id">
                    <Link
                        :href="SetController.edit(mine.id)"
                        class="inline-flex rounded-full border px-3 py-1 text-sm hover:border-primary"
                    >
                        {{ mine.title }}
                    </Link>
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="font-semibold">共備庫中對應這一課的題組</h2>
                <Link
                    :href="libraryUrl"
                    class="text-sm text-muted-foreground hover:underline"
                    >在共備庫中查看</Link
                >
            </div>
            <p v-if="shared.length === 0" class="text-sm text-muted-foreground">
                還沒有其他老師公開對應這一課的題組。
            </p>
            <ul class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <li v-for="item in shared" :key="item.id">
                    <Link
                        :href="SetController.show(item.id)"
                        class="flex h-full flex-col gap-1 rounded-xl border p-4 transition hover:border-primary"
                        data-test="lesson-shared-set"
                    >
                        <span class="text-sm text-muted-foreground"
                            >{{ KIND_NAMES[item.kind] }}・{{
                                item.entries_count
                            }}
                            題</span
                        >
                        <span class="font-semibold">{{ item.title }}</span>
                        <span class="text-sm text-muted-foreground">{{
                            item.owner
                        }}</span>
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
