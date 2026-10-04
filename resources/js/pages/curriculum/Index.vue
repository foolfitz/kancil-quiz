<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import CurriculumController from '@/actions/App/Http/Controllers/CurriculumController';
import Heading from '@/components/Heading.vue';
import type { Language, TextbookLesson } from '@/types/kancil';

// 教材（docs/SPEC.md T-18）：依語言、冊、課瀏覽教材的詞彙。
const props = defineProps<{
    languages: Language[];
    language: string;
    lessons: TextbookLesson[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: '教材', href: CurriculumController.index() }],
    },
});

const volumes = computed(() => {
    const groups = new Map<number, TextbookLesson[]>();
    for (const lesson of props.lessons) {
        groups.set(lesson.volume, [
            ...(groups.get(lesson.volume) ?? []),
            lesson,
        ]);
    }
    return [...groups].map(([volume, lessons]) => ({ volume, lessons }));
});

function choose(code: string): void {
    router.get(CurriculumController.index.url({ query: { language: code } }));
}
</script>

<template>
    <Head title="教材" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="教材"
            description="國教署《新住民語文學習教材》各冊各課的詞彙，配上自製的插圖。選一課就能直接建立活動，也可以挑詞做成自己的題組。"
        />

        <div
            v-if="languages.length > 1"
            class="flex flex-wrap gap-2"
            role="group"
            aria-label="語言"
        >
            <button
                v-for="item in languages"
                :key="item.code"
                type="button"
                class="rounded-full border px-4 py-1.5 text-sm transition hover:border-primary"
                :class="
                    item.code === language
                        ? 'border-primary bg-primary text-primary-foreground'
                        : ''
                "
                :aria-pressed="item.code === language"
                @click="choose(item.code)"
            >
                {{ item.name_zh }}
            </button>
        </div>

        <p v-if="volumes.length === 0" class="text-muted-foreground">
            這個語言還沒有教材。管理員匯入教材的詞彙後就會出現在這裡。
        </p>

        <section v-for="group in volumes" :key="group.volume" class="space-y-3">
            <h2 class="text-lg font-semibold">第 {{ group.volume }} 冊</h2>
            <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <li v-for="lesson in group.lessons" :key="lesson.lesson">
                    <Link
                        :href="lesson.url"
                        class="flex h-full flex-col gap-2 rounded-xl border p-4 transition hover:border-primary"
                        data-test="curriculum-lesson"
                    >
                        <span class="text-sm text-muted-foreground"
                            >第 {{ lesson.lesson }} 課</span
                        >
                        <span class="flex flex-col">
                            <span
                                v-if="lesson.title_native"
                                class="text-lg font-semibold"
                                :lang="language"
                                >{{ lesson.title_native }}</span
                            >
                            <span
                                :class="
                                    lesson.title_native
                                        ? 'text-sm'
                                        : 'text-lg font-semibold'
                                "
                                >{{ lesson.title_zh }}</span
                            >
                        </span>
                        <span
                            v-if="lesson.words.length > 0"
                            class="mt-auto flex items-center gap-1"
                        >
                            <template
                                v-for="word in lesson.words.slice(0, 4)"
                                :key="word.id"
                            >
                                <img
                                    v-if="word.thumbnail_url"
                                    :src="word.thumbnail_url"
                                    alt=""
                                    class="size-12 rounded-md bg-muted/40 object-contain"
                                    loading="lazy"
                                />
                            </template>
                            <span class="ml-auto text-sm text-muted-foreground"
                                >{{ lesson.words.length }} 個詞</span
                            >
                        </span>
                        <span
                            v-else
                            class="mt-auto text-sm text-muted-foreground"
                            >還沒有詞彙</span
                        >
                    </Link>
                </li>
            </ul>
        </section>

        <p class="text-sm text-muted-foreground">
            教材來源：國教署<a
                href="https://mkm.k12ea.gov.tw/textbook"
                target="_blank"
                rel="noopener"
                class="underline underline-offset-4"
                >新住民子女教育資訊網</a
            >。這裡只收錄課名與詞彙，不收錄課文、教材的插圖與音檔。
        </p>
    </div>
</template>
