<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Language, TextbookLesson } from '@/types/kancil';

// 教材的課表：選語言，依冊列出每一課（docs/SPEC.md T-18、S-06）。首頁與教材頁共用。
const props = defineProps<{
    languages: Language[];
    language: string;
    lessons: TextbookLesson[];
    // 切換語言的網址：首頁與教材頁各自帶 ?language=
    languageHref: (code: string) => string;
    // 放在另一個 h2 之下時（首頁），冊名用 h3
    nested?: boolean;
}>();

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
</script>

<template>
    <div class="flex flex-col gap-6">
        <nav
            v-if="languages.length > 1"
            class="flex flex-wrap gap-2"
            aria-label="語言"
        >
            <Link
                v-for="item in languages"
                :key="item.code"
                :href="languageHref(item.code)"
                preserve-scroll
                class="rounded-full border px-4 py-1.5 text-sm transition hover:border-primary"
                :class="
                    item.code === language
                        ? 'border-primary bg-primary text-primary-foreground'
                        : ''
                "
                :aria-current="item.code === language ? 'page' : undefined"
            >
                {{ item.name_zh }}
            </Link>
        </nav>

        <p v-if="volumes.length === 0" class="text-muted-foreground">
            這個語言還沒有教材。管理員匯入教材的詞彙後就會出現在這裡。
        </p>

        <section v-for="group in volumes" :key="group.volume" class="space-y-3">
            <component :is="nested ? 'h3' : 'h2'" class="text-lg font-semibold"
                >第 {{ group.volume }} 冊</component
            >
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
            >的《新住民語文學習教材》。這裡只收錄課名與詞彙，不收錄課文、教材的插圖與音檔；插圖是本站自製的。
        </p>
    </div>
</template>
