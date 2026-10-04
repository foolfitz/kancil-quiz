<script setup lang="ts">
import { CircleCheck, CircleMinus, CircleX } from '@lucide/vue';
import ResultFace from '@/components/kancil/ResultFace.vue';
import type { AttemptDetail } from '@/types/kancil';

// 一次作答的逐題明細（docs/SPEC.md T-11）：題目依作答當時的題組版本顯示。
defineProps<{
    rounds: AttemptDetail['rounds'];
    scored: boolean;
    lang: string;
}>();
</script>

<template>
    <ul class="divide-y" data-test="attempt-detail">
        <li
            v-for="round in rounds"
            :key="round.entry_id"
            class="flex flex-wrap items-start gap-x-6 gap-y-1 py-2"
        >
            <span class="flex w-20 shrink-0 items-center gap-1">
                <template v-if="!round.answered">
                    <CircleMinus class="size-4 text-muted-foreground" />
                    {{ scored ? '沒作答' : '沒看過' }}
                </template>
                <template v-else-if="round.correct === null">
                    <CircleCheck class="size-4 text-muted-foreground" />
                    看過
                </template>
                <template v-else-if="round.correct">
                    <CircleCheck class="size-4 text-green-600" />
                    答對
                </template>
                <template v-else>
                    <CircleX class="size-4 text-red-600" />
                    答錯
                </template>
            </span>
            <span class="min-w-40 flex-1">
                <ResultFace :face="round.question" :lang="lang" />
            </span>
            <span
                v-if="round.correct === false"
                class="flex flex-1 flex-col gap-1"
            >
                <span class="flex flex-wrap items-center gap-1">
                    <span class="text-muted-foreground">選了：</span>
                    <ResultFace
                        :face="round.selected"
                        :lang="lang"
                        missing="（不在題組中的選項）"
                    />
                </span>
                <span
                    v-if="round.answer"
                    class="flex flex-wrap items-center gap-1"
                >
                    <span class="text-muted-foreground">正解：</span>
                    <ResultFace :face="round.answer" :lang="lang" />
                </span>
            </span>
            <span v-if="round.tries > 1" class="text-muted-foreground"
                >共作答 {{ round.tries }} 次</span
            >
        </li>
    </ul>
</template>
