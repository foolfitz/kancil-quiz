<script setup lang="ts">
import { ImagePlus, Loader2, Mic, Play, X } from '@lucide/vue';
import { ref } from 'vue';
import { uploadMedia } from '@/lib/media';
import type { MediaRef } from '@/types/kancil';

// 一個音檔或圖片欄位：上傳、預覽、移除（docs/SPEC.md T-05）。
const props = defineProps<{
    kind: 'audio' | 'image';
    rightsConfirmed: boolean;
    label?: string;
}>();

const model = defineModel<MediaRef | null>({ required: true });
const emit = defineEmits<{ error: [message: string] }>();

const input = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
let player: HTMLAudioElement | null = null;

function pick(): void {
    if (!props.rightsConfirmed) {
        emit('error', '請先勾選上方的權利聲明，再上傳音檔或圖片。');
        return;
    }
    input.value?.click();
}

async function upload(event: Event): Promise<void> {
    const file = (event.target as HTMLInputElement).files?.[0];
    (event.target as HTMLInputElement).value = '';
    if (!file) {
        return;
    }
    uploading.value = true;
    try {
        model.value = await uploadMedia(file, props.kind);
    } catch (error) {
        emit('error', error instanceof Error ? error.message : '上傳失敗');
    } finally {
        uploading.value = false;
    }
}

function play(): void {
    if (model.value) {
        player?.pause();
        player = new Audio(model.value.url);
        void player.play();
    }
}
</script>

<template>
    <div class="inline-flex items-center gap-1">
        <input
            ref="input"
            type="file"
            class="hidden"
            :accept="
                kind === 'audio'
                    ? 'audio/*,.m4a,.mp3,.wav,.ogg,.webm'
                    : 'image/jpeg,image/png,image/webp'
            "
            @change="upload"
        />

        <template v-if="model">
            <button
                v-if="kind === 'audio'"
                type="button"
                class="inline-flex h-9 items-center gap-1 rounded-md border px-2 text-sm hover:bg-accent"
                :title="label ? `播放${label}` : '播放'"
                @click="play"
            >
                <Play class="size-4" />
                <span v-if="model.duration_ms">
                    {{ (model.duration_ms / 1000).toFixed(1) }} 秒
                </span>
            </button>
            <img
                v-else
                :src="model.thumbnail_url ?? model.url"
                alt=""
                class="size-9 rounded-md border object-cover"
            />
            <button
                type="button"
                class="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                :title="label ? `移除${label}` : '移除'"
                @click="model = null"
            >
                <X class="size-4" />
            </button>
        </template>

        <button
            v-else
            type="button"
            class="inline-flex h-9 items-center gap-1 rounded-md border border-dashed px-2 text-sm text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-50"
            :disabled="uploading"
            :title="label ? `上傳${label}` : '上傳'"
            @click="pick"
        >
            <Loader2 v-if="uploading" class="size-4 animate-spin" />
            <Mic v-else-if="kind === 'audio'" class="size-4" />
            <ImagePlus v-else class="size-4" />
            <span class="hidden sm:inline">{{
                uploading ? '處理中' : kind === 'audio' ? '音檔' : '圖片'
            }}</span>
        </button>
    </div>
</template>
