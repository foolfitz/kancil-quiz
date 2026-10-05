<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus, Trash2, X } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import MediaCredits from '@/components/kancil/MediaCredits.vue';
import MediaSlot from '@/components/kancil/MediaSlot.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { MediaRef, QuizEntryInput } from '@/types/kancil';

// 問答組編輯：題幹加 2 到 6 個選項，標示一個正解（docs/SPEC.md T-07）。
defineProps<{
    errors: Partial<Record<string, string>>;
    rightsConfirmed: boolean;
}>();

const entries = defineModel<QuizEntryInput[]>({ required: true });
const emit = defineEmits<{ error: [message: string] }>();

const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];
const OPTION_IDS = ['a', 'b', 'c', 'd', 'e', 'f'];

function option(id: string, correct = false) {
    return { id, text: '', image: null, correct };
}

function add(): void {
    entries.value.push({
        id: null,
        question: {
            stem: { text: '', audio: null, image: null },
            options: [option('a', true), option('b'), option('c')],
        },
    });
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

// 選項代號保持不變（作答紀錄以它識別），新選項使用第一個沒用過的代號。
function addOption(entry: QuizEntryInput): void {
    const used = new Set(entry.question.options.map((o) => o.id));
    const id = OPTION_IDS.find((candidate) => !used.has(candidate));
    if (id) {
        entry.question.options.push(option(id));
    }
}

function removeOption(entry: QuizEntryInput, index: number): void {
    const [removed] = entry.question.options.splice(index, 1);
    if (removed?.correct && entry.question.options[0]) {
        entry.question.options[0].correct = true;
    }
}

function markCorrect(entry: QuizEntryInput, index: number): void {
    entry.question.options.forEach((o, i) => {
        o.correct = i === index;
    });
}

// 這一題的媒體與署名（docs/SPEC.md 第 9 節）
function mediaOf(entry: QuizEntryInput): { label: string; ref: MediaRef }[] {
    const { stem, options } = entry.question;
    return [
        stem.audio ? { label: '題幹音檔', ref: stem.audio } : null,
        stem.image ? { label: '題幹圖片', ref: stem.image } : null,
        ...options.map((o, j) =>
            o.image
                ? { label: `選項 ${LETTERS[j]} 的圖片`, ref: o.image }
                : null,
        ),
    ].filter((item) => item !== null);
}
</script>

<template>
    <div class="space-y-4">
        <ol class="space-y-4">
            <li
                v-for="(entry, i) in entries"
                :key="entry.id ?? `new-${i}`"
                class="space-y-3 rounded-lg border p-4"
            >
                <div class="flex items-start gap-2">
                    <span class="mt-2 w-12 shrink-0 text-sm font-medium"
                        >第 {{ i + 1 }} 題</span
                    >
                    <div class="flex-1 space-y-2">
                        <textarea
                            v-model="entry.question.stem.text"
                            rows="2"
                            placeholder="題幹，例：「謝謝」的越南語是？"
                            aria-label="題幹"
                            class="w-full rounded-md border bg-transparent px-3 py-2 text-base"
                        />
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-muted-foreground"
                                >題幹附件：</span
                            >
                            <MediaSlot
                                v-model="entry.question.stem.audio"
                                kind="audio"
                                label="題幹音檔"
                                :context="entry.question.stem.text"
                                :rights-confirmed="rightsConfirmed"
                                @error="emit('error', $event)"
                            />
                            <MediaSlot
                                v-model="entry.question.stem.image"
                                kind="image"
                                label="題幹圖片"
                                :rights-confirmed="rightsConfirmed"
                                @error="emit('error', $event)"
                            />
                        </div>
                        <InputError
                            :message="errors[`entries.${i}.question.stem`]"
                        />
                    </div>
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
                            title="刪除這一題"
                            @click="remove(i)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </div>

                <fieldset class="space-y-2 pl-14">
                    <legend class="sr-only">選項</legend>
                    <div
                        v-for="(opt, j) in entry.question.options"
                        :key="opt.id"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <label
                            class="inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-md border px-2 text-sm"
                            :class="
                                opt.correct
                                    ? 'border-green-600 bg-green-50 text-green-800 dark:bg-green-950 dark:text-green-200'
                                    : ''
                            "
                        >
                            <input
                                type="radio"
                                :name="`correct-${i}`"
                                :checked="opt.correct"
                                @change="markCorrect(entry, j)"
                            />
                            {{ LETTERS[j] }}
                            <span v-if="opt.correct">正解</span>
                        </label>
                        <Input
                            v-model="opt.text"
                            class="min-w-40 flex-1"
                            :placeholder="`選項 ${LETTERS[j]}`"
                            :aria-label="`選項 ${LETTERS[j]}`"
                        />
                        <MediaSlot
                            v-model="opt.image"
                            kind="image"
                            :label="`選項 ${LETTERS[j]} 的圖片`"
                            :rights-confirmed="rightsConfirmed"
                            @error="emit('error', $event)"
                        />
                        <button
                            type="button"
                            class="inline-flex size-9 items-center justify-center rounded-md text-muted-foreground hover:bg-accent disabled:opacity-30"
                            title="刪除選項"
                            :disabled="entry.question.options.length <= 2"
                            @click="removeOption(entry, j)"
                        >
                            <X class="size-4" />
                        </button>
                    </div>
                    <Button
                        v-if="entry.question.options.length < 6"
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="addOption(entry)"
                    >
                        <Plus class="size-4" /> 新增選項
                    </Button>
                    <InputError
                        :message="errors[`entries.${i}.question.options`]"
                    />
                    <InputError
                        v-for="(_, j) in entry.question.options"
                        :key="j"
                        :message="errors[`entries.${i}.question.options.${j}`]"
                    />
                </fieldset>
                <MediaCredits
                    v-if="mediaOf(entry).length > 0"
                    class="pl-14"
                    :media="mediaOf(entry)"
                    @update="(media, patch) => Object.assign(media, patch)"
                />
            </li>
        </ol>

        <Button type="button" variant="outline" @click="add">
            <Plus class="size-4" /> 新增題目
        </Button>
    </div>
</template>
