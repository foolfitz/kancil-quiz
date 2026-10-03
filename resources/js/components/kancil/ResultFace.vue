<script setup lang="ts">
import { Volume2 } from '@lucide/vue';
import type { ResultFace } from '@/types/kancil';

// 成績頁中的一個題目或答案：圖片縮圖、文字與中文意思、發音按鈕。
const props = defineProps<{
    face: ResultFace | null;
    lang?: string;
    // 找不到選項（例如之後被刪除）時顯示的文字
    missing?: string;
}>();

let player: HTMLAudioElement | null = null;
function play(): void {
    if (props.face?.audio) {
        player?.pause();
        player = new Audio(props.face.audio);
        void player.play().catch(() => undefined);
    }
}
</script>

<template>
    <span
        v-if="face && (face.text || face.note || face.image || face.audio)"
        class="inline-flex items-center gap-2"
    >
        <img
            v-if="face.image"
            :src="face.image"
            :alt="face.text ?? ''"
            class="size-10 shrink-0 rounded object-cover"
            loading="lazy"
        />
        <span class="flex flex-col leading-snug">
            <span v-if="face.text" :lang="lang">{{ face.text }}</span>
            <span v-if="face.note" class="text-sm text-muted-foreground">{{
                face.note
            }}</span>
            <span
                v-if="!face.text && !face.note && face.image"
                class="text-sm text-muted-foreground"
                >（圖片）</span
            >
        </span>
        <button
            v-if="face.audio"
            type="button"
            class="inline-flex size-8 shrink-0 items-center justify-center rounded-full hover:bg-muted"
            title="播放發音"
            @click.stop="play"
        >
            <Volume2 class="size-4" />
        </button>
    </span>
    <span v-else class="text-sm text-muted-foreground">{{
        missing ?? '—'
    }}</span>
</template>
