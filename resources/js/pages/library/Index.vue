<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Search, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import SetCard from '@/components/kancil/SetCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { library } from '@/routes';
import { lessonLabel } from '@/types/kancil';
import type {
    CurriculumRef,
    Language,
    Paginated,
    SetCardData,
} from '@/types/kancil';

// 共備庫（docs/SPEC.md T-13）：依語言、冊、課、標籤瀏覽與搜尋公開的題組。
type Filters = {
    language?: string;
    volume?: number;
    lesson?: number;
    tag?: string;
    q?: string;
};

const props = defineProps<{
    sets: Paginated<SetCardData>;
    filters: Filters;
    languages: Language[];
    curriculumRefs: CurriculumRef[];
    tags: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: '共備庫', href: library() }],
    },
});

function apply(changes: Partial<Filters>): void {
    const next: Filters = { ...props.filters, ...changes };
    const query = Object.fromEntries(
        Object.entries(next).filter(
            ([, value]) => value !== undefined && value !== '',
        ),
    );
    router.get(library.url({ query }), {}, { preserveScroll: true });
}

const q = ref(props.filters.q ?? '');

// 冊課只列出所選語言的對照表，先選冊再選課；沒選語言時不顯示
const refs = computed(() =>
    props.curriculumRefs.filter(
        (item) => item.language_code === props.filters.language,
    ),
);
const volumes = computed(() => [
    ...new Set(refs.value.map((item) => item.volume)),
]);
// 網址參數是字串
const volume = computed(() => Number(props.filters.volume) || undefined);
const lessons = computed(() =>
    refs.value.filter((item) => item.volume === volume.value),
);
const selectValue = (event: Event) =>
    Number((event.target as HTMLSelectElement).value) || undefined;

const filtered = computed(() =>
    Object.values(props.filters).some((value) => value !== undefined),
);
</script>

<template>
    <Head title="共備庫" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="共備庫"
            description="其他老師公開的題組都經過審核者確認；標示「教材」的是由教材詞彙匯入的題組。複製到自己的題組後就可以改編。"
        />

        <form
            class="flex flex-wrap items-end gap-3"
            role="search"
            @submit.prevent="apply({ q: q.trim() || undefined })"
        >
            <label class="grid gap-1 text-sm">
                <span class="font-medium">語言</span>
                <select
                    class="h-9 rounded-md border bg-transparent px-3 text-base md:text-sm"
                    :value="filters.language ?? ''"
                    @change="
                        apply({
                            language:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                            volume: undefined,
                            lesson: undefined,
                        })
                    "
                >
                    <option value="">全部</option>
                    <option
                        v-for="language in languages"
                        :key="language.code"
                        :value="language.code"
                    >
                        {{ language.name_zh }}
                    </option>
                </select>
            </label>
            <label v-if="volumes.length > 0" class="grid gap-1 text-sm">
                <span class="font-medium">冊</span>
                <select
                    class="h-9 rounded-md border bg-transparent px-3 text-base md:text-sm"
                    :value="volume ?? ''"
                    @change="
                        apply({
                            volume: selectValue($event),
                            lesson: undefined,
                        })
                    "
                >
                    <option value="">全部</option>
                    <option v-for="v in volumes" :key="v" :value="v">
                        第 {{ v }} 冊
                    </option>
                </select>
            </label>
            <label v-if="lessons.length > 0" class="grid gap-1 text-sm">
                <span class="font-medium">課</span>
                <select
                    class="h-9 rounded-md border bg-transparent px-3 text-base md:text-sm"
                    :value="Number(filters.lesson) || ''"
                    @change="apply({ lesson: selectValue($event) })"
                >
                    <option value="">全部</option>
                    <option
                        v-for="item in lessons"
                        :key="item.id"
                        :value="item.lesson"
                    >
                        {{ lessonLabel(item) }}
                    </option>
                </select>
            </label>
            <label class="grid min-w-56 flex-1 gap-1 text-sm">
                <span class="font-medium">關鍵字</span>
                <Input
                    v-model="q"
                    type="search"
                    placeholder="標題或詞，例如：家人、ayah"
                />
            </label>
            <Button type="submit"><Search class="size-4" /> 搜尋</Button>
            <Button
                v-if="filtered"
                type="button"
                variant="ghost"
                @click="router.get(library.url())"
                ><X class="size-4" /> 清除條件</Button
            >
        </form>

        <div v-if="tags.length > 0" class="flex flex-wrap items-center gap-2">
            <span class="text-sm text-muted-foreground">標籤：</span>
            <button
                v-for="tag in tags"
                :key="tag"
                type="button"
                class="rounded-full border px-3 py-0.5 text-sm transition hover:border-primary"
                :class="
                    filters.tag === tag
                        ? 'border-primary bg-primary text-primary-foreground'
                        : ''
                "
                :aria-pressed="filters.tag === tag"
                @click="apply({ tag: filters.tag === tag ? undefined : tag })"
            >
                {{ tag }}
            </button>
        </div>

        <p v-if="sets.data.length === 0" class="text-muted-foreground">
            {{
                filtered
                    ? '找不到符合條件的題組，試試別的關鍵字或清除條件。'
                    : '共備庫還沒有題組。你可以在自己的題組頁「申請公開」，審核通過後就會出現在這裡。'
            }}
        </p>

        <ul class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <li v-for="set in sets.data" :key="set.id">
                <SetCard :set="set" />
            </li>
        </ul>

        <nav
            v-if="sets.last_page > 1"
            class="flex items-center justify-between gap-2 text-sm"
            aria-label="共備庫分頁"
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
    </div>
</template>
