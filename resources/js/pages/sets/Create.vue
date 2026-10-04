<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import SetController from '@/actions/App/Http/Controllers/SetController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Language, SetKind, TextbookLesson } from '@/types/kancil';

const props = defineProps<{
    languages: Language[];
    licenses: string[];
    language: string;
    textbook: TextbookLesson[];
    preset: { volume: number | null; lesson: number | null };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: '我的題組', href: SetController.index() },
            { title: '建立題組', href: SetController.create() },
        ],
    },
});

const form = useForm({
    kind: 'vocab' as SetKind,
    title: '',
    description: '',
    language_code: props.language,
    license: 'CC-BY-4.0',
    textbook_entries: [] as string[],
});

const kinds: { value: SetKind; title: string; description: string }[] = [
    {
        value: 'vocab',
        title: '詞彙組',
        description:
            '一組詞或短句，每個有目標語、中文，可附發音與圖片。能自動變成選擇題、配對、字卡等遊戲。',
    },
    {
        value: 'quiz',
        title: '問答組',
        description: '自己寫題幹與 2 到 6 個選項，標示一個正解。',
    },
];

// 從教材挑詞（docs/SPEC.md T-18）：只有詞彙組可以，且這個語言要有匯入詞彙的課
const lessons = computed(() =>
    props.textbook.filter((lesson) => lesson.words.length > 0),
);
const volumes = computed(() => [
    ...new Set(lessons.value.map((lesson) => lesson.volume)),
]);
const source = ref<'textbook' | 'blank'>(
    lessons.value.length > 0 ? 'textbook' : 'blank',
);
const fromTextbook = computed(
    () =>
        form.kind === 'vocab' &&
        source.value === 'textbook' &&
        lessons.value.length > 0,
);

const volume = ref<number | null>(
    props.preset.volume ?? volumes.value[0] ?? null,
);
const volumeLessons = computed(() =>
    lessons.value.filter((lesson) => lesson.volume === volume.value),
);
const picked = ref<Set<string>>(new Set());

function presetLesson(): void {
    const lesson = lessons.value.find(
        (item) =>
            item.volume === props.preset.volume &&
            item.lesson === props.preset.lesson,
    );
    if (lesson) {
        picked.value = new Set(lesson.words.map((word) => word.id));
    }
}
presetLesson();

function lessonState(lesson: TextbookLesson): boolean | 'mixed' {
    const count = lesson.words.filter((word) =>
        picked.value.has(word.id),
    ).length;
    return count === 0 ? false : count === lesson.words.length ? true : 'mixed';
}

function toggleLesson(lesson: TextbookLesson): void {
    const next = new Set(picked.value);
    const all = lessonState(lesson) === true;
    for (const word of lesson.words) {
        if (all) {
            next.delete(word.id);
        } else {
            next.add(word.id);
        }
    }
    picked.value = next;
}

function toggleWord(id: string): void {
    const next = new Set(picked.value);
    if (!next.delete(id)) {
        next.add(id);
    }
    picked.value = next;
}

// 依教材的順序送出
const pickedIds = computed(() =>
    lessons.value.flatMap((lesson) =>
        lesson.words
            .filter((word) => picked.value.has(word.id))
            .map((word) => word.id),
    ),
);

// 標題沒有手動改過時，依挑選的課自動產生
const titleEdited = ref(false);
const suggestedTitle = computed(() => {
    const chosen = lessons.value.filter(
        (lesson) => lessonState(lesson) !== false,
    );
    if (chosen.length === 0) {
        return '';
    }
    if (chosen.length === 1) {
        const [lesson] = chosen;
        return `第 ${lesson.volume} 冊第 ${lesson.lesson} 課 ${lesson.title_zh ?? ''}`.trim();
    }
    const byVolume = [...new Set(chosen.map((lesson) => lesson.volume))].map(
        (v) =>
            `第 ${v} 冊第 ${chosen
                .filter((lesson) => lesson.volume === v)
                .map((lesson) => lesson.lesson)
                .join('、')} 課`,
    );
    return `${byVolume.join('、')}複習`;
});
watch(
    [suggestedTitle, fromTextbook],
    () => {
        if (!titleEdited.value) {
            form.title = fromTextbook.value ? suggestedTitle.value : '';
        }
    },
    { immediate: true },
);

// 換語言時重新取得該語言的教材
watch(
    () => form.language_code,
    (code) => {
        picked.value = new Set();
        router.reload({
            data: { language: code, volume: undefined, lesson: undefined },
            only: ['textbook', 'language'],
            onSuccess: () => {
                volume.value = volumes.value[0] ?? null;
                source.value = lessons.value.length > 0 ? 'textbook' : 'blank';
            },
        });
    },
);

function submit(): void {
    form.transform((data) => ({
        ...data,
        textbook_entries: fromTextbook.value ? pickedIds.value : [],
    })).submit(SetController.store());
}
</script>

<template>
    <Head title="建立題組" />

    <form class="flex max-w-3xl flex-col gap-6 p-4" @submit.prevent="submit">
        <Heading title="建立題組" />

        <fieldset class="grid gap-3 sm:grid-cols-2">
            <legend class="mb-2 text-sm font-medium">題組種類</legend>
            <label
                v-for="kind in kinds"
                :key="kind.value"
                class="cursor-pointer rounded-xl border p-4"
                :class="
                    form.kind === kind.value
                        ? 'border-primary ring-2 ring-primary/30'
                        : ''
                "
            >
                <input
                    v-model="form.kind"
                    type="radio"
                    name="kind"
                    :value="kind.value"
                    class="sr-only"
                />
                <span class="font-semibold">{{ kind.title }}</span>
                <span class="mt-1 block text-sm text-muted-foreground">{{
                    kind.description
                }}</span>
            </label>
        </fieldset>

        <div class="grid gap-2">
            <Label for="language">語言</Label>
            <select
                id="language"
                v-model="form.language_code"
                class="h-9 rounded-md border bg-transparent px-3 text-base md:text-sm"
            >
                <option
                    v-for="language in languages"
                    :key="language.code"
                    :value="language.code"
                >
                    {{ language.name_zh }}（{{ language.name_native }}）
                </option>
            </select>
            <InputError :message="form.errors.language_code" />
        </div>

        <fieldset
            v-if="form.kind === 'vocab' && lessons.length > 0"
            class="flex flex-col gap-3"
        >
            <legend class="mb-2 text-sm font-medium">內容</legend>
            <div class="flex flex-wrap gap-4 text-sm">
                <label class="inline-flex items-center gap-2">
                    <input
                        v-model="source"
                        type="radio"
                        name="source"
                        value="textbook"
                    />
                    從教材挑詞
                </label>
                <label class="inline-flex items-center gap-2">
                    <input
                        v-model="source"
                        type="radio"
                        name="source"
                        value="blank"
                    />
                    自己出題
                </label>
            </div>

            <div
                v-if="fromTextbook"
                class="flex flex-col gap-3 rounded-xl border p-4"
                data-test="textbook-picker"
            >
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <label class="grid gap-1 text-sm">
                        <span class="font-medium">冊</span>
                        <select
                            v-model.number="volume"
                            class="h-9 rounded-md border bg-transparent px-3 text-base md:text-sm"
                        >
                            <option v-for="v in volumes" :key="v" :value="v">
                                第 {{ v }} 冊
                            </option>
                        </select>
                    </label>
                    <span
                        class="text-sm text-muted-foreground"
                        data-test="picked-count"
                        >已選 {{ pickedIds.length }} 個詞</span
                    >
                </div>

                <section
                    v-for="lesson in volumeLessons"
                    :key="lesson.lesson"
                    class="space-y-2"
                >
                    <label class="flex items-center gap-2 font-medium">
                        <input
                            type="checkbox"
                            :checked="lessonState(lesson) === true"
                            :indeterminate="lessonState(lesson) === 'mixed'"
                            @change="toggleLesson(lesson)"
                        />
                        第 {{ lesson.lesson }} 課
                        <span
                            v-if="lesson.title_native"
                            :lang="form.language_code"
                            >{{ lesson.title_native }}</span
                        >
                        <span class="font-normal text-muted-foreground">{{
                            lesson.title_zh
                        }}</span>
                    </label>
                    <ul class="flex flex-wrap gap-2 pl-6">
                        <li v-for="word in lesson.words" :key="word.id">
                            <label
                                class="inline-flex cursor-pointer items-center gap-2 rounded-lg border py-1 pr-3 pl-1 text-sm"
                                :class="
                                    picked.has(word.id)
                                        ? 'border-primary bg-primary/5'
                                        : 'text-muted-foreground'
                                "
                            >
                                <input
                                    type="checkbox"
                                    class="sr-only"
                                    :checked="picked.has(word.id)"
                                    @change="toggleWord(word.id)"
                                />
                                <img
                                    v-if="word.thumbnail_url"
                                    :src="word.thumbnail_url"
                                    alt=""
                                    class="size-8 rounded object-contain"
                                    loading="lazy"
                                />
                                <span :lang="form.language_code">{{
                                    word.text
                                }}</span>
                                <span class="text-muted-foreground">{{
                                    word.translation_zh
                                }}</span>
                            </label>
                        </li>
                    </ul>
                </section>
                <InputError :message="form.errors.textbook_entries" />
                <p class="text-sm text-muted-foreground">
                    挑好的詞會連同插圖複製到你的題組，之後可以自由增減、修改。
                </p>
            </div>
        </fieldset>

        <div class="grid gap-2">
            <Label for="title">標題</Label>
            <Input
                id="title"
                v-model="form.title"
                required
                placeholder="例：第 1 冊第 3 課 我的家人"
                @input="titleEdited = true"
            />
            <InputError :message="form.errors.title" />
        </div>

        <div class="grid gap-2">
            <Label for="license">授權</Label>
            <select
                id="license"
                v-model="form.license"
                class="h-9 rounded-md border bg-transparent px-3 text-base md:text-sm"
            >
                <option
                    v-for="license in licenses"
                    :key="license"
                    :value="license"
                >
                    {{ license }}
                </option>
            </select>
            <p class="text-sm text-muted-foreground">
                題組公開後，其他老師可以依這個授權複製與改編。
            </p>
        </div>

        <div>
            <Button
                type="submit"
                :disabled="
                    form.processing || (fromTextbook && pickedIds.length === 0)
                "
                >{{ fromTextbook ? '建立題組' : '建立並開始出題' }}</Button
            >
        </div>
    </form>
</template>
