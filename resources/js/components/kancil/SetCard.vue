<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import SetController from '@/actions/App/Http/Controllers/SetController';
import { Badge } from '@/components/ui/badge';
import { KIND_NAMES, curriculumLabel } from '@/types/kancil';
import type { SetCardData } from '@/types/kancil';

// 共備庫與創作者頁面上的題組卡片（docs/SPEC.md T-13、T-20）。整張卡片連到題組頁（標題的連結以
// 偽元素撐滿卡片），擁有者的名字另外連到創作者頁面，所以卡片本身不是連結，兩個連結才不會巢狀。
// showOwner 為 false 時不顯示擁有者（創作者頁面上都是同一個人）。
withDefaults(defineProps<{ set: SetCardData; showOwner?: boolean }>(), {
    showOwner: true,
});
</script>

<template>
    <article
        class="relative flex h-full flex-col gap-2 rounded-xl border p-4 transition has-[a[data-card-link]:hover]:border-primary"
        data-test="library-set"
    >
        <div class="flex flex-wrap items-center gap-2">
            <Badge variant="secondary">{{ KIND_NAMES[set.kind] }}</Badge>
            <Badge variant="outline">{{ set.language }}</Badge>
            <Badge v-if="set.textbook">教材</Badge>
            <span class="text-sm text-muted-foreground"
                >{{ set.entries_count }} 題</span
            >
        </div>
        <h3 class="text-lg font-semibold">
            <Link
                :href="SetController.show(set.id)"
                class="after:absolute after:inset-0 after:rounded-xl after:content-['']"
                data-card-link
                >{{ set.title }}</Link
            >
        </h3>
        <p
            v-if="set.description"
            class="line-clamp-2 text-sm text-muted-foreground"
        >
            {{ set.description }}
        </p>
        <p v-if="showOwner" class="text-sm text-muted-foreground">
            <Link
                v-if="set.owner_url"
                :href="set.owner_url"
                class="relative z-10 underline-offset-4 hover:underline"
                data-test="set-owner"
                >{{ set.owner }}</Link
            ><template v-else>{{ set.owner }}</template
            ><template v-if="set.forked">（改編）</template>
        </p>
        <div
            v-if="set.curriculum.length > 0 || set.tags.length > 0"
            class="mt-auto flex flex-wrap gap-1"
        >
            <Badge
                v-for="item in set.curriculum"
                :key="`${item.volume}-${item.lesson}`"
                variant="outline"
                >{{ curriculumLabel(item) }}</Badge
            >
            <Badge v-for="tag in set.tags" :key="tag" variant="outline"
                >#{{ tag }}</Badge
            >
        </div>
    </article>
</template>
