<script setup lang="ts">
import { Check, Loader2, Mic, RotateCcw, Square } from '@lucide/vue';
import { computed, inject, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { MEDIA_CONTEXT, uploadMedia } from '@/lib/media';
import {
    MAX_SECONDS,
    Recording,
    SUPPORT_MESSAGES,
    recordingErrorMessage,
    recordingSupport,
} from '@/lib/recorder';
import type { MediaRef } from '@/types/kancil';

// 錄一段發音（docs/SPEC.md T-06）：錄音、在本機試聽、重錄或採用；採用後才上傳。
// shortcuts 時：空白鍵開始或停止，Enter 採用（逐詞錄音用）。
const props = defineProps<{ shortcuts?: boolean }>();
const emit = defineEmits<{ uploaded: [media: MediaRef] }>();

type State = 'idle' | 'starting' | 'recording' | 'recorded' | 'uploading';
const state = ref<State>('idle');
const error = ref('');
const support = recordingSupport();
const unsupported = support === 'ok' ? null : SUPPORT_MESSAGES[support];
// 錄音的署名與上傳相同（docs/SPEC.md 第 9 節）：作者是老師自己，授權是題組的授權
const mediaContext = inject(MEDIA_CONTEXT, null);

let recording: Recording | null = null;
const file = ref<File | null>(null);
const previewUrl = ref<string | null>(null);
const elapsed = ref(0);
const level = ref(0);
let frame = 0;

function tick(): void {
    if (recording && state.value === 'recording') {
        elapsed.value = recording.elapsedMs;
        level.value = recording.level();
        frame = requestAnimationFrame(tick);
    }
}

function clearPreview(): void {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
    }
    previewUrl.value = null;
    file.value = null;
}

async function start(): Promise<void> {
    if (state.value !== 'idle' && state.value !== 'recorded') {
        return;
    }
    clearPreview();
    error.value = '';
    state.value = 'starting';
    try {
        recording = await Recording.start(() => void stop());
    } catch (e) {
        error.value = recordingErrorMessage(e);
        state.value = 'idle';
        return;
    }
    elapsed.value = 0;
    state.value = 'recording';
    frame = requestAnimationFrame(tick);
}

async function stop(): Promise<void> {
    if (!recording || state.value !== 'recording') {
        return;
    }
    cancelAnimationFrame(frame);
    level.value = 0;
    try {
        file.value = await recording.stop();
        previewUrl.value = URL.createObjectURL(file.value);
        state.value = 'recorded';
    } catch (e) {
        error.value = e instanceof Error ? e.message : '錄音失敗';
        state.value = 'idle';
    } finally {
        recording = null;
    }
}

async function accept(): Promise<void> {
    if (state.value !== 'recorded' || !file.value) {
        return;
    }
    state.value = 'uploading';
    error.value = '';
    try {
        const media = await uploadMedia(
            file.value,
            'audio',
            mediaContext?.value,
        );
        clearPreview();
        state.value = 'idle';
        emit('uploaded', media);
    } catch (e) {
        error.value = e instanceof Error ? e.message : '上傳失敗';
        state.value = 'recorded';
    }
}

function toggle(): void {
    if (state.value === 'recording') {
        void stop();
    } else {
        void start();
    }
}

const seconds = computed(() => Math.floor(elapsed.value / 1000));
const clock = (s: number) =>
    `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

// 焦點在按鈕或輸入欄上時交給瀏覽器處理，否則按鈕會被觸發兩次
function onKeydown(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;
    if (
        target?.closest('button, input, textarea, select, a, audio') ||
        event.repeat
    ) {
        return;
    }
    if (event.key === ' ') {
        event.preventDefault();
        toggle();
    } else if (event.key === 'Enter' && state.value === 'recorded') {
        event.preventDefault();
        void accept();
    }
}

onMounted(() => {
    if (props.shortcuts) {
        window.addEventListener('keydown', onKeydown);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    cancelAnimationFrame(frame);
    recording?.cancel();
    recording = null;
    clearPreview();
});
</script>

<template>
    <div
        class="flex flex-col items-center gap-4 text-center"
        data-test="audio-recorder"
    >
        <p v-if="unsupported" class="text-sm text-destructive" role="alert">
            {{ unsupported }}
        </p>

        <template v-else>
            <div
                v-if="state === 'recording'"
                class="flex w-full max-w-xs flex-col items-center gap-2"
            >
                <span
                    class="font-mono text-2xl tabular-nums"
                    data-test="recording-clock"
                    >{{ clock(seconds) }} / {{ clock(MAX_SECONDS) }}</span
                >
                <div
                    class="h-2 w-full overflow-hidden rounded-full bg-muted"
                    role="meter"
                    aria-label="音量"
                    :aria-valuenow="Math.round(level * 100)"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full rounded-full bg-green-500 transition-[width] duration-75"
                        :style="{ width: `${Math.round(level * 100)}%` }"
                    />
                </div>
            </div>

            <audio
                v-if="previewUrl && state !== 'uploading'"
                :src="previewUrl"
                controls
                class="w-full max-w-xs"
                data-test="recording-preview"
            />

            <div class="flex flex-wrap justify-center gap-2">
                <Button
                    v-if="state === 'recording'"
                    type="button"
                    variant="destructive"
                    size="lg"
                    @click="stop"
                    ><Square class="size-4" /> 停止</Button
                >
                <Button
                    v-else-if="state === 'idle' || state === 'starting'"
                    type="button"
                    size="lg"
                    :disabled="state === 'starting'"
                    @click="start"
                    ><Mic class="size-4" /> 開始錄音</Button
                >
                <template v-else>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="state === 'uploading'"
                        @click="start"
                        ><RotateCcw class="size-4" /> 重錄</Button
                    >
                    <Button
                        type="button"
                        :disabled="state === 'uploading'"
                        @click="accept"
                    >
                        <Loader2
                            v-if="state === 'uploading'"
                            class="size-4 animate-spin"
                        />
                        <Check v-else class="size-4" />
                        {{ state === 'uploading' ? '處理中' : '採用' }}
                    </Button>
                </template>
            </div>

            <p class="text-sm text-muted-foreground">
                <template v-if="state === 'recording'"
                    >最長 {{ MAX_SECONDS }} 秒，時間到會自動停止。</template
                >
                <template v-else-if="state === 'recorded'"
                    >先聽聽看，滿意就採用。</template
                >
                <template v-else-if="state !== 'uploading'"
                    >按下後對著麥克風說，說完按「停止」。</template
                >
            </p>
            <p v-if="shortcuts" class="text-sm text-muted-foreground">
                鍵盤：空白鍵開始／停止<template v-if="state === 'recorded'"
                    >，Enter 採用</template
                >。
            </p>
        </template>

        <p v-if="error" class="text-sm text-destructive" role="alert">
            {{ error }}
        </p>
    </div>
</template>
