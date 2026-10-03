<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import SetController from '@/actions/App/Http/Controllers/SetController';
import SetReviewController from '@/actions/App/Http/Controllers/SetReviewController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { KIND_NAMES } from '@/types/kancil';
import type { SetKind, SetReviewEntry } from '@/types/kancil';

// 審核者的待審清單（docs/SPEC.md C-01）：只列出負責語言中、別人的題組。
defineProps<{
    pending: {
        id: string;
        kind: SetKind;
        title: string;
        language: string;
        owner: string;
        entries_count: number;
        requested_at: string | null;
        note: string | null;
    }[];
    recent: {
        set_id: string;
        title: string;
        action: SetReviewEntry['action'];
        note: string | null;
        user: string;
        created_at: string;
    }[];
    // null 表示管理員，可以審核所有語言
    languages: string[] | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: '待審題組', href: SetReviewController.index() }],
    },
});

const ACTION_NAMES: Record<string, string> = {
    approved: '通過',
    rejected: '退回',
    unpublished: '下架',
};

const dateTime = (iso: string) =>
    new Date(iso).toLocaleString('zh-TW', {
        dateStyle: 'short',
        timeStyle: 'short',
    });
</script>

<template>
    <Head title="待審題組" />

    <div class="flex max-w-4xl flex-col gap-6 p-4">
        <Heading
            title="待審題組"
            :description="
                languages === null
                    ? '管理員可以審核所有語言的題組。'
                    : languages.length > 0
                      ? `你負責審核：${languages.join('、')}`
                      : '管理員還沒有指定你負責審核的語言。'
            "
        />

        <p v-if="pending.length === 0" class="text-muted-foreground">
            目前沒有待審的題組。
        </p>
        <ul v-else class="grid gap-3">
            <li v-for="set in pending" :key="set.id">
                <Link
                    :href="SetController.show(set.id)"
                    class="block rounded-xl border p-4 transition hover:border-primary"
                    data-test="pending-set"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge variant="secondary">{{
                            KIND_NAMES[set.kind]
                        }}</Badge>
                        <Badge variant="outline">{{ set.language }}</Badge>
                        <span class="text-sm text-muted-foreground">
                            {{ set.owner }}・{{ set.entries_count }} 題<template
                                v-if="set.requested_at"
                                >・{{
                                    dateTime(set.requested_at)
                                }}
                                申請</template
                            >
                        </span>
                    </div>
                    <h3 class="mt-2 text-lg font-semibold">{{ set.title }}</h3>
                    <p
                        v-if="set.note"
                        class="mt-1 text-sm whitespace-pre-line text-muted-foreground"
                    >
                        「{{ set.note }}」
                    </p>
                </Link>
            </li>
        </ul>

        <section v-if="recent.length > 0" class="space-y-2">
            <h2 class="font-semibold">最近的審核</h2>
            <ul class="divide-y rounded-xl border text-sm">
                <li v-for="(item, i) in recent" :key="i" class="p-3">
                    <Link
                        :href="SetController.show(item.set_id)"
                        class="font-medium hover:underline"
                        >{{ item.title }}</Link
                    >
                    <span class="text-muted-foreground">
                        ・{{ ACTION_NAMES[item.action] ?? item.action }}・{{
                            item.user
                        }}・{{ dateTime(item.created_at) }}</span
                    >
                    <p v-if="item.note" class="mt-1 whitespace-pre-line">
                        {{ item.note }}
                    </p>
                </li>
            </ul>
        </section>
    </div>
</template>
