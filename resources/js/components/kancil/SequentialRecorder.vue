<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AudioRecorder from '@/components/kancil/AudioRecorder.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { MediaRef, VocabEntryInput } from '@/types/kancil';

// 逐詞錄音（docs/SPEC.md T-06）：依序顯示還沒有發音的詞，錄完採用後自動跳到下一個。
// 錄 20 個詞只要開一次對話框；錄音存進題組仍要按編輯頁的「儲存」。
const props = defineProps<{ entries: VocabEntryInput[] }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{
    recorded: [entry: VocabEntryInput, media: MediaRef];
}>();

// 打開時決定要錄哪些詞，錄的過程中不再變動
const queue = ref<VocabEntryInput[]>([]);
const position = ref(0);
const recordedCount = ref(0);

watch(
    open,
    (value) => {
        if (value) {
            queue.value = props.entries.filter(
                (entry) =>
                    entry.item.text.trim() !== '' &&
                    entry.item.audio.length === 0,
            );
            position.value = 0;
            recordedCount.value = 0;
        }
    },
    { immediate: true },
);

const current = computed(() => queue.value[position.value] ?? null);

function recorded(media: MediaRef): void {
    if (current.value) {
        emit('recorded', current.value, media);
        recordedCount.value++;
    }
    position.value++;
}

function skip(): void {
    position.value++;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>逐詞錄音</DialogTitle>
                <DialogDescription>
                    依序幫還沒有發音的詞錄音。錄完記得按編輯頁的「儲存」，錄音才會存進題組。
                </DialogDescription>
            </DialogHeader>

            <p v-if="queue.length === 0" class="text-center">
                每個詞都已經有發音了。
            </p>

            <div
                v-else-if="current"
                class="flex flex-col gap-4"
                data-test="sequential-recorder"
            >
                <p class="text-sm text-muted-foreground">
                    第 {{ position + 1 }} / {{ queue.length }} 個
                </p>
                <div class="flex flex-col items-center gap-1 text-center">
                    <img
                        v-if="current.item.image"
                        :src="
                            current.item.image.thumbnail_url ??
                            current.item.image.url
                        "
                        alt=""
                        class="mb-2 size-24 rounded-md border object-cover"
                    />
                    <span
                        class="text-3xl font-semibold"
                        data-test="sequential-word"
                        >{{ current.item.text }}</span
                    >
                    <span class="text-muted-foreground">{{
                        current.item.translation_zh
                    }}</span>
                </div>
                <AudioRecorder :key="position" shortcuts @uploaded="recorded" />
                <div class="flex justify-end">
                    <Button type="button" variant="ghost" @click="skip"
                        >略過這個詞</Button
                    >
                </div>
            </div>

            <div v-else class="flex flex-col items-center gap-3 text-center">
                <p class="text-lg font-semibold">都錄完了</p>
                <p>
                    錄了 {{ recordedCount }} 個詞<template
                        v-if="recordedCount < queue.length"
                        >，略過 {{ queue.length - recordedCount }} 個</template
                    >。記得按「儲存」。
                </p>
                <DialogClose as-child>
                    <Button type="button">關閉</Button>
                </DialogClose>
            </div>
        </DialogContent>
    </Dialog>
</template>
