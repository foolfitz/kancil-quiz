<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import MediaSlot from '@/components/kancil/MediaSlot.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { MediaRef, VocabEntryInput } from '@/types/kancil';

// 詞彙組編輯：逐列輸入，或一次貼上多行「目標語<Tab>中文」（docs/SPEC.md T-04）。
defineProps<{
    errors: Partial<Record<string, string>>;
    rightsConfirmed: boolean;
    showRomanization: boolean;
}>();

const entries = defineModel<VocabEntryInput[]>({ required: true });
const emit = defineEmits<{ error: [message: string] }>();

const pasted = ref('');
const pasteMessage = ref('');

function blank(): VocabEntryInput {
    return {
        id: null,
        item: {
            text: '',
            romanization: null,
            translation_zh: '',
            audio: [],
            image: null,
        },
    };
}

function add(): void {
    entries.value.push(blank());
}

function remove(index: number): void {
    entries.value.splice(index, 1);
}

function move(index: number, offset: number): void {
    const target = index + offset;
    if (target < 0 || target >= entries.value.length) {
        return;
    }
    const [entry] = entries.value.splice(index, 1);
    entries.value.splice(target, 0, entry);
}

// 試算表複製出來的是 Tab 分隔；也接受兩個以上的空白。
function addPasted(): void {
    let added = 0;
    let skipped = 0;
    for (const line of pasted.value.split(/\r?\n/)) {
        if (line.trim() === '') {
            continue;
        }
        const [text, translation] = line.split(/\t| {2,}/).map((s) => s.trim());
        if (!text || !translation) {
            skipped++;
            continue;
        }
        // 移除最後一列空白列，再加入
        const last = entries.value.at(-1);
        if (
            last &&
            last.id === null &&
            !last.item.text &&
            !last.item.translation_zh
        ) {
            entries.value.pop();
        }
        entries.value.push({
            ...blank(),
            item: { ...blank().item, text, translation_zh: translation },
        });
        added++;
    }
    pasted.value = '';
    pasteMessage.value =
        skipped > 0
            ? `已加入 ${added} 個詞條；${skipped} 行格式不對（需要用 Tab 分隔目標語與中文），沒有加入。`
            : `已加入 ${added} 個詞條。`;
}

function audioOf(entry: VocabEntryInput): MediaRef | null {
    return entry.item.audio[0] ?? null;
}

function setAudio(entry: VocabEntryInput, media: MediaRef | null): void {
    entry.item.audio = media ? [media] : [];
}
</script>

<template>
    <div class="space-y-4">
        <details class="rounded-lg border p-3">
            <summary class="cursor-pointer text-sm font-medium">
                一次貼上多個詞條
            </summary>
            <div class="mt-3 space-y-2">
                <p class="text-sm text-muted-foreground">
                    每行一個詞條，目標語與中文之間用 Tab
                    分隔。從試算表複製兩欄貼上即可。
                </p>
                <textarea
                    v-model="pasted"
                    rows="6"
                    class="w-full rounded-md border bg-transparent px-3 py-2 font-mono text-base md:text-sm"
                    placeholder="quả chuối	香蕉&#10;quả táo	蘋果"
                />
                <div class="flex items-center gap-3">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="addPasted"
                    >
                        加入
                    </Button>
                    <span class="text-sm text-muted-foreground">{{
                        pasteMessage
                    }}</span>
                </div>
            </div>
        </details>

        <ol class="space-y-2">
            <li
                v-for="(entry, i) in entries"
                :key="entry.id ?? `new-${i}`"
                class="rounded-lg border p-3"
            >
                <div class="flex flex-wrap items-start gap-2">
                    <span
                        class="mt-2 w-6 shrink-0 text-right text-sm text-muted-foreground"
                        >{{ i + 1 }}</span
                    >
                    <div class="min-w-40 flex-1">
                        <Input
                            v-model="entry.item.text"
                            placeholder="目標語"
                            aria-label="目標語"
                            class="text-base"
                        />
                        <InputError
                            :message="errors[`entries.${i}.item.text`]"
                        />
                    </div>
                    <div v-if="showRomanization" class="min-w-32 flex-1">
                        <Input
                            :model-value="entry.item.romanization ?? ''"
                            placeholder="羅馬拼寫（選填）"
                            aria-label="羅馬拼寫"
                            @update:model-value="
                                entry.item.romanization = String($event) || null
                            "
                        />
                    </div>
                    <div class="min-w-32 flex-1">
                        <Input
                            v-model="entry.item.translation_zh"
                            placeholder="中文意思"
                            aria-label="中文意思"
                        />
                        <InputError
                            :message="
                                errors[`entries.${i}.item.translation_zh`]
                            "
                        />
                    </div>
                    <MediaSlot
                        kind="audio"
                        label="發音"
                        :rights-confirmed="rightsConfirmed"
                        :model-value="audioOf(entry)"
                        @update:model-value="setAudio(entry, $event)"
                        @error="emit('error', $event)"
                    />
                    <MediaSlot
                        v-model="entry.item.image"
                        kind="image"
                        label="圖片"
                        :rights-confirmed="rightsConfirmed"
                        @error="emit('error', $event)"
                    />
                    <div class="flex items-center">
                        <button
                            type="button"
                            class="inline-flex size-9 items-center justify-center rounded-md text-muted-foreground hover:bg-accent disabled:opacity-30"
                            title="上移"
                            :disabled="i === 0"
                            @click="move(i, -1)"
                        >
                            <ArrowUp class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="inline-flex size-9 items-center justify-center rounded-md text-muted-foreground hover:bg-accent disabled:opacity-30"
                            title="下移"
                            :disabled="i === entries.length - 1"
                            @click="move(i, 1)"
                        >
                            <ArrowDown class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="inline-flex size-9 items-center justify-center rounded-md text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                            title="刪除"
                            @click="remove(i)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </div>
            </li>
        </ol>

        <Button type="button" variant="outline" @click="add">
            <Plus class="size-4" /> 新增詞條
        </Button>
    </div>
</template>
