<script setup lang="ts">
import { check, groupGames } from '@kancil-quiz/deck';
import type { KancilSet } from '@kancil-quiz/schema';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Copy,
    Download,
    Gamepad2,
    ListChecks,
    MonitorPlay,
    Pencil,
    Play,
    Volume2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
import CurriculumController from '@/actions/App/Http/Controllers/CurriculumController';
import SetController from '@/actions/App/Http/Controllers/SetController';
import SetCopyController from '@/actions/App/Http/Controllers/SetCopyController';
import SetExportController from '@/actions/App/Http/Controllers/SetExportController';
import PageMeta from '@/components/kancil/PageMeta.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';
import type { User } from '@/types';
import { KIND_NAMES, standaloneUrl } from '@/types/kancil';
import type {
    GameInfo,
    PageMeta as PageMetaData,
    SetKind,
    VocabEntryInput,
} from '@/types/kancil';

// 教材的一課（docs/SPEC.md T-18、S-06）：教材題組的詞彙與試玩。不需登入；
// 老師登入後另外可以直接建立活動、複製或挑詞，也看得到自己與共備庫中對應這一課的題組。
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
        // 例：CC BY-NC-ND 4.0；認得的授權才有條款網址
        license_name: string;
        license_url: string | null;
        authors: string[];
        can: {
            activity: boolean;
            copy: boolean;
            edit: boolean;
            export: boolean;
        };
    } | null;
    words: VocabEntryInput[];
    // 教材題組最新版本的內容，用來判斷哪些遊戲能玩（docs/SPEC.md 7.3）
    content: KancilSet | null;
    games: GameInfo[];
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
    // 共備庫中對應這一課的公開題組數；訪客只看得到數量
    sharedCount: number;
    libraryUrl: string;
    meta: PageMetaData;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: '教材', href: CurriculumController.index() }],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user as User | null);

// 只列出這一課能玩的遊戲，分成「遊戲」與「互動教材」（docs/SPEC.md 7.5）
const playable = computed(() => {
    const content = props.content;
    if (!content) {
        return [];
    }
    return groupGames(
        props.games.filter((game) => check(content, game.requires).ok),
    );
});

function playUrl(game: string): string {
    return CurriculumController.play.url({
        language: props.lesson.language.code,
        volume: props.lesson.volume,
        lesson: props.lesson.lesson,
        game,
    });
}

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
    <PageMeta :meta="meta" />

    <div class="flex flex-col gap-6 p-4" :class="user ? 'max-w-5xl' : ''">
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
            <!-- 老師的功能；訪客看到的是下面「老師：用這一課出作業」的說明 -->
            <div v-if="user" class="flex flex-wrap gap-2">
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
            <p v-if="user" class="text-sm text-muted-foreground">
                直接建立的活動會一直使用這一課最新的詞彙。想增減詞、換題目的呈現方式，請先複製成自己的題組。
            </p>

            <!-- 訪客：寬螢幕時「老師：用這一課出作業」放在右側；窄螢幕依序是試玩、說明、詞彙 -->
            <div
                class="grid gap-6"
                :class="
                    user
                        ? ''
                        : 'lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start'
                "
            >
                <section
                    v-if="playable.length > 0"
                    class="min-w-0 space-y-3 lg:col-start-1"
                    data-test="lesson-games"
                >
                    <div>
                        <h2 class="font-semibold">直接玩這一課</h2>
                        <p class="text-sm text-muted-foreground">
                            {{
                                user
                                    ? '試玩不會留下作答紀錄。要發給學生、看成績，請用上面的「選遊戲、建立活動」。'
                                    : '不必登入，也不會留下作答紀錄。'
                            }}
                        </p>
                    </div>
                    <div
                        v-for="group in playable"
                        :key="group.category.id"
                        class="space-y-2"
                    >
                        <h3 class="text-sm text-muted-foreground">
                            {{ group.category.title }}
                        </h3>
                        <ul class="flex flex-wrap gap-2">
                            <li v-for="game in group.games" :key="game.id">
                                <Button as-child variant="outline">
                                    <a
                                        :href="playUrl(game.id)"
                                        data-test="lesson-play"
                                        ><Play class="size-4" />
                                        {{ game.title['zh-TW'] }}</a
                                    >
                                </Button>
                            </li>
                        </ul>
                    </div>
                </section>

                <aside
                    v-if="!user"
                    class="space-y-2 rounded-xl border bg-muted/30 p-4 lg:sticky lg:top-4 lg:col-start-2 lg:row-span-2 lg:row-start-1"
                    data-test="lesson-teacher"
                >
                    <h2 class="font-semibold">老師：用這一課出作業</h2>
                    <p class="text-sm text-muted-foreground">
                        用 Google
                        帳號登入（第一次登入就建立帳號）後，可以用這一課建立活動，取得給學生的連結與
                        QR
                        code，設定開放與截止時間，看每位學生的成績；也能複製或挑詞做成自己的題組。
                        <template v-if="sharedCount > 0"
                            >共備庫中還有
                            {{ sharedCount }}
                            個其他老師對應這一課的題組。</template
                        >
                    </p>
                    <Button as-child size="sm">
                        <Link :href="login()">老師登入</Link>
                    </Button>
                </aside>

                <section class="min-w-0 space-y-3 lg:col-start-1">
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
                        <!-- 課名與詞彙照原教材標示作者與授權（docs/SPEC.md D-4），插圖是自製的 -->
                        課名與詞彙：{{ set.authors.join('、') }}，<a
                            v-if="set.license_url"
                            :href="set.license_url"
                            target="_blank"
                            rel="noopener license"
                            class="underline underline-offset-4"
                            >{{ set.license_name }}</a
                        ><template v-else>{{ set.license_name }}</template
                        >。
                        <template v-if="imageCredits.length > 0"
                            >插圖：{{ imageCredits.join('；') }}。</template
                        >
                        教材來源：國教署<a
                            href="https://mkm.k12ea.gov.tw/textbook"
                            target="_blank"
                            rel="noopener"
                            class="underline underline-offset-4"
                            >新住民子女教育資訊網</a
                        >。
                    </p>
                </section>
            </div>
        </template>
        <p v-else class="text-muted-foreground" data-test="lesson-empty">
            這一課還沒有匯入詞彙。<template v-if="user"
                >你仍然可以在下面找到其他老師對應這一課的題組。</template
            >
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

        <section v-if="user" class="space-y-3">
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
