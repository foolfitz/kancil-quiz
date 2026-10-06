<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink, Pencil, School } from '@lucide/vue';
import ContributionCalendar from '@/components/kancil/ContributionCalendar.vue';
import SetCard from '@/components/kancil/SetCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { library } from '@/routes';
import { edit as editCreator } from '@/routes/creator';
import type {
    ContributionCalendar as Calendar,
    Paginated,
    SetCardData,
    TeacherProfile,
    TeacherStats,
} from '@/types/kancil';

// 創作者頁面（docs/SPEC.md T-20）：只給登入的老師看。署名、學校、教的語言、簡介由老師自己填；
// 貢獻統計與日曆由公開的內容算出來，不是老師填的。不顯示 email。
const props = defineProps<{
    teacher: TeacherProfile;
    isSelf: boolean;
    stats: TeacherStats;
    calendar: Calendar;
    sets: Paginated<SetCardData>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: '共備庫', href: library() }],
    },
});

const tiles: { label: string; value: number; hint: string }[] = [
    {
        label: '公開的題組',
        value: props.stats.public_sets,
        hint: '目前公開在共備庫的題組',
    },
    { label: '題數', value: props.stats.entries, hint: '公開題組中的題數' },
    {
        label: '音檔與圖片',
        value: props.stats.media,
        hint: '自己上傳、用在公開題組中的媒體',
    },
    {
        label: '被複製',
        value: props.stats.copies,
        hint: '其他老師複製公開題組的次數',
    },
    {
        label: '被用來建立活動',
        value: props.stats.activities,
        hint: '其他老師用這些題組或複製品建立的活動',
    },
];

// 署名附的網址只顯示主機名稱，太長的網址才不會撐開版面
function hostname(url: string): string {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return url;
    }
}
</script>

<template>
    <Head :title="teacher.name" />

    <div class="flex flex-col gap-8 p-4">
        <header class="flex flex-col gap-3" data-test="teacher-header">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 space-y-1">
                    <h1
                        class="text-2xl font-semibold tracking-tight break-words"
                    >
                        {{ teacher.name }}
                    </h1>
                    <p
                        v-if="teacher.school"
                        class="flex items-center gap-1.5 text-muted-foreground"
                    >
                        <School class="size-4 shrink-0" aria-hidden="true" />
                        <span class="sr-only">學校：</span>{{ teacher.school }}
                    </p>
                    <p v-if="teacher.url" class="text-sm">
                        <a
                            :href="teacher.url"
                            target="_blank"
                            rel="noopener nofollow ugc"
                            class="inline-flex items-center gap-1 underline-offset-4 hover:underline"
                            data-test="teacher-url"
                        >
                            <ExternalLink
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            />{{ hostname(teacher.url) }}</a
                        >
                    </p>
                </div>
                <Button v-if="isSelf" as-child variant="outline" size="sm">
                    <Link :href="editCreator()" data-test="edit-creator"
                        ><Pencil class="size-4" /> 編輯創作者資料</Link
                    >
                </Button>
            </div>
            <div
                v-if="teacher.languages.length > 0"
                class="flex flex-wrap items-center gap-1.5 text-sm"
            >
                <span class="text-muted-foreground">教的語言：</span>
                <Badge
                    v-for="language in teacher.languages"
                    :key="language.code"
                    variant="secondary"
                    >{{ language.name_zh }}（{{ language.name_native }}）</Badge
                >
            </div>
            <p
                v-if="teacher.bio"
                class="max-w-prose text-sm leading-relaxed whitespace-pre-line"
                data-test="teacher-bio"
            >
                {{ teacher.bio }}
            </p>
        </header>

        <section aria-labelledby="stats-heading" class="space-y-3">
            <h2 id="stats-heading" class="text-lg font-semibold">貢獻統計</h2>
            <p class="text-sm text-muted-foreground">
                由公開在共備庫的內容自動算出來，不是自己填的；只有數字，不涉及學生。
            </p>
            <dl
                class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5"
                data-test="teacher-stats"
            >
                <div
                    v-for="tile in tiles"
                    :key="tile.label"
                    class="rounded-xl border p-3"
                    :title="tile.hint"
                >
                    <dt class="text-xs text-muted-foreground">
                        {{ tile.label }}
                    </dt>
                    <dd class="text-2xl font-semibold tabular-nums">
                        {{ tile.value }}
                    </dd>
                </div>
            </dl>
            <ContributionCalendar :calendar="calendar" />
        </section>

        <section aria-labelledby="sets-heading" class="space-y-3">
            <h2 id="sets-heading" class="text-lg font-semibold">
                公開的題組
                <span class="text-base font-normal text-muted-foreground"
                    >（{{ sets.total }}）</span
                >
            </h2>
            <p v-if="sets.data.length === 0" class="text-muted-foreground">
                還沒有公開的題組。
            </p>
            <ul class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <li v-for="set in sets.data" :key="set.id">
                    <SetCard :set="set" :show-owner="false" />
                </li>
            </ul>
            <nav
                v-if="sets.last_page > 1"
                class="flex items-center justify-between gap-2 text-sm"
                aria-label="題組分頁"
            >
                <span class="text-muted-foreground"
                    >第 {{ sets.from }}–{{ sets.to }} 個，共
                    {{ sets.total }} 個</span
                >
                <span class="flex gap-2">
                    <Button
                        v-if="sets.prev_page_url"
                        as-child
                        variant="outline"
                        size="sm"
                    >
                        <Link :href="sets.prev_page_url">上一頁</Link>
                    </Button>
                    <Button
                        v-if="sets.next_page_url"
                        as-child
                        variant="outline"
                        size="sm"
                    >
                        <Link :href="sets.next_page_url">下一頁</Link>
                    </Button>
                </span>
            </nav>
        </section>
    </div>
</template>
