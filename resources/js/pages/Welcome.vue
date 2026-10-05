<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import LessonCatalog from '@/components/kancil/LessonCatalog.vue';
import PageMeta from '@/components/kancil/PageMeta.vue';
import { Button } from '@/components/ui/button';
import { dashboard, home, login } from '@/routes';
import type { User } from '@/types';
import type {
    Language,
    PageMeta as PageMetaData,
    TextbookLesson,
} from '@/types/kancil';

// 首頁（docs/SPEC.md S-06）：對象是老師。專案剛起步，老師不見得願意先註冊，
// 所以直接列出教材的每一課，不登入就能玩；要建立活動、看成績時再登入。
defineProps<{
    languages: Language[];
    language: string;
    lessons: TextbookLesson[];
    meta: PageMetaData;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user as User | null);

function languageHref(code: string): string {
    return home.url({ query: { language: code } });
}
</script>

<template>
    <PageMeta :meta="meta" />

    <div class="flex flex-col gap-10 px-4 py-10">
        <section class="flex max-w-3xl flex-col gap-4">
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                新住民語文教材的每一課，都能直接變成遊戲
            </h1>
            <p class="text-base leading-relaxed text-muted-foreground">
                國教署《新住民語文學習教材》的課名與詞彙，配上自製的插圖，選一課就能玩。老師用
                Google 帳號登入後，可以用同一課建立活動，取得給學生的連結與 QR
                code、看每位學生的成績，也能挑詞做成自己的題組。
            </p>
            <div class="flex flex-wrap items-center gap-3">
                <Button as-child size="lg">
                    <a href="#lessons">找到你教的那一課</a>
                </Button>
                <Button v-if="user" as-child size="lg" variant="outline">
                    <Link :href="dashboard()">我的首頁</Link>
                </Button>
                <Button v-else as-child size="lg" variant="outline">
                    <Link :href="login()">老師登入</Link>
                </Button>
            </div>
        </section>

        <section id="lessons" class="flex scroll-mt-4 flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight">選一課</h2>
            <LessonCatalog
                :languages="languages"
                :language="language"
                :lessons="lessons"
                :language-href="languageHref"
                nested
            />
        </section>
    </div>
</template>
