<script setup lang="ts">
import { X } from '@lucide/vue';
import { ref } from 'vue';

// 標籤輸入：按 Enter、逗號或頓號加入（docs/SPEC.md T-13 依標籤搜尋）。
const model = defineModel<string[]>({ required: true });
const props = withDefaults(defineProps<{ id?: string; max?: number }>(), {
    id: undefined,
    max: 10,
});

const draft = ref('');

function commit(): void {
    const tags = draft.value
        .split(/[,，、]/u)
        .map((tag) => tag.trim())
        .filter((tag) => tag !== '' && !model.value.includes(tag));
    if (tags.length > 0) {
        model.value = [...model.value, ...tags].slice(0, props.max);
    }
    draft.value = '';
}

function onKeydown(event: KeyboardEvent): void {
    if (event.isComposing) {
        return; // 注音或其他輸入法選字中
    }
    if (event.key === 'Enter' || event.key === ',' || event.key === '，') {
        event.preventDefault();
        commit();
    } else if (event.key === 'Backspace' && draft.value === '') {
        model.value = model.value.slice(0, -1);
    }
}

function remove(tag: string): void {
    model.value = model.value.filter((t) => t !== tag);
}
</script>

<template>
    <div
        class="flex min-h-9 flex-wrap items-center gap-1 rounded-md border px-2 py-1 focus-within:ring-2 focus-within:ring-ring/50"
    >
        <span
            v-for="tag in model"
            :key="tag"
            class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-sm"
        >
            {{ tag }}
            <button
                type="button"
                class="rounded-full hover:bg-background"
                :aria-label="`移除標籤「${tag}」`"
                @click="remove(tag)"
            >
                <X class="size-3" />
            </button>
        </span>
        <input
            :id="id"
            v-model="draft"
            type="text"
            class="min-w-24 flex-1 bg-transparent py-1 text-base outline-none md:text-sm"
            :placeholder="
                model.length === 0 ? '例：水果、問候（按 Enter 加入）' : ''
            "
            :disabled="model.length >= max"
            @keydown="onKeydown"
            @blur="commit"
        />
    </div>
</template>
