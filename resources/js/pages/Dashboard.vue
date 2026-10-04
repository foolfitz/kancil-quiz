<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { GraduationCap, Plus } from '@lucide/vue';
import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
import CurriculumController from '@/actions/App/Http/Controllers/CurriculumController';
import SetController from '@/actions/App/Http/Controllers/SetController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { KIND_NAMES, STATUS_NAMES, localTime } from '@/types/kancil';
import type { ActivitySettingsView, SetKind } from '@/types/kancil';

defineProps<{
    sets: { id: string; kind: SetKind; title: string; entries_count: number }[];
    activities: {
        id: string;
        game: string;
        set_title: string | null;
        attempts_count: number;
        settings: ActivitySettingsView;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: '首頁', href: dashboard() }],
    },
});
</script>

<template>
    <Head title="首頁" />

    <div class="flex flex-col gap-8 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading title="首頁" description="出題、選遊戲、分享給學生。" />
            <div class="flex flex-wrap justify-end gap-2">
                <Button as-child variant="outline">
                    <Link :href="CurriculumController.index()"
                        ><GraduationCap class="size-4" /> 從教材開始</Link
                    >
                </Button>
                <Button as-child>
                    <Link :href="SetController.create()"
                        ><Plus class="size-4" /> 建立題組</Link
                    >
                </Button>
            </div>
        </div>

        <section class="space-y-3">
            <div class="flex items-baseline justify-between">
                <h2 class="text-lg font-semibold">最近的題組</h2>
                <Link
                    :href="SetController.index()"
                    class="text-sm text-muted-foreground hover:underline"
                    >全部題組</Link
                >
            </div>
            <p v-if="sets.length === 0" class="text-muted-foreground">
                還沒有題組。
            </p>
            <ul class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <li v-for="set in sets" :key="set.id">
                    <Link
                        :href="SetController.edit(set.id)"
                        class="block rounded-xl border p-4 hover:border-primary"
                    >
                        <span class="text-xs text-muted-foreground"
                            >{{ KIND_NAMES[set.kind] }}・{{
                                set.entries_count
                            }}
                            題</span
                        >
                        <span class="mt-1 block font-semibold">{{
                            set.title
                        }}</span>
                    </Link>
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">最近的活動</h2>
            <p v-if="activities.length === 0" class="text-muted-foreground">
                還沒有活動。在教材的某一課或自己的題組頁面選一個遊戲就能建立。
            </p>
            <ul class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <li v-for="activity in activities" :key="activity.id">
                    <Link
                        :href="ActivityController.show(activity.id)"
                        class="block rounded-xl border p-4 hover:border-primary"
                    >
                        <span class="text-xs text-muted-foreground"
                            >{{ activity.game }}・{{
                                activity.attempts_count
                            }}
                            次作答</span
                        >
                        <span class="mt-1 block font-semibold">{{
                            activity.set_title
                        }}</span>
                        <span class="mt-1 block text-xs text-muted-foreground"
                            >{{
                                activity.settings.status === 'open'
                                    ? activity.settings.closes_at
                                        ? `${localTime(activity.settings.closes_at)} 截止`
                                        : '不截止'
                                    : STATUS_NAMES[activity.settings.status]
                            }}{{
                                activity.settings.require_label ? '・記名' : ''
                            }}</span
                        >
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
