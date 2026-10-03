<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import SetController from '@/actions/App/Http/Controllers/SetController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { KIND_NAMES } from '@/types/kancil';
import type { SetKind } from '@/types/kancil';

defineProps<{
    sets: {
        id: string;
        kind: SetKind;
        title: string;
        language: string;
        entries_count: number;
        activities_count: number;
        updated_at: string | null;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: '我的題組', href: SetController.index() }],
    },
});
</script>

<template>
    <Head title="我的題組" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="我的題組"
                description="題組是出題的單位，同一個題組可以切換成不同遊戲。"
            />
            <Button as-child>
                <Link :href="SetController.create()">
                    <Plus class="size-4" /> 建立題組
                </Link>
            </Button>
        </div>

        <p v-if="sets.length === 0" class="text-muted-foreground">
            還沒有題組。先建立一個詞彙組或問答組吧！
        </p>

        <ul class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <li v-for="set in sets" :key="set.id">
                <Link
                    :href="SetController.edit(set.id)"
                    class="block h-full rounded-xl border p-4 transition hover:border-primary"
                >
                    <div class="flex items-center gap-2">
                        <Badge variant="secondary">{{
                            KIND_NAMES[set.kind]
                        }}</Badge>
                        <Badge variant="outline">{{ set.language }}</Badge>
                    </div>
                    <h3 class="mt-2 text-lg font-semibold">{{ set.title }}</h3>
                    <p class="text-sm text-muted-foreground">
                        {{ set.entries_count }} 題・{{ set.activities_count }}
                        個活動
                    </p>
                </Link>
            </li>
        </ul>
    </div>
</template>
