<script setup lang="ts">
import CurriculumController from '@/actions/App/Http/Controllers/CurriculumController';
import Heading from '@/components/Heading.vue';
import LessonCatalog from '@/components/kancil/LessonCatalog.vue';
import PageMeta from '@/components/kancil/PageMeta.vue';
import type {
    Language,
    PageMeta as PageMetaData,
    TextbookLesson,
} from '@/types/kancil';

// 教材（docs/SPEC.md T-18、S-06）：依語言、冊、課瀏覽教材的詞彙。不需登入；
// 已登入的老師在側邊欄的版面中看到同一頁（resources/js/app.ts）。
defineProps<{
    languages: Language[];
    language: string;
    lessons: TextbookLesson[];
    meta: PageMetaData;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: '教材', href: CurriculumController.index() }],
    },
});

function languageHref(code: string): string {
    return CurriculumController.index.url({ query: { language: code } });
}
</script>

<template>
    <PageMeta :meta="meta" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="教材"
            description="國教署《新住民語文學習教材》各冊各課的詞彙，配上自製的插圖。每一課都能直接玩；老師登入後可以用它建立活動，也可以挑詞做成自己的題組。"
        />
        <LessonCatalog
            :languages="languages"
            :language="language"
            :lessons="lessons"
            :language-href="languageHref"
        />
    </div>
</template>
