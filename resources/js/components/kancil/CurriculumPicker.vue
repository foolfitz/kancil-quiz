<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { curriculumLabel } from '@/types/kancil';
import type { CurriculumRef } from '@/types/kancil';

// 題組對應的教材冊課（docs/SPEC.md 3.6）。只列出題組語言的對照表。
const model = defineModel<number[]>({ required: true });
const props = defineProps<{
    id?: string;
    refs: CurriculumRef[];
    language: string;
}>();

const available = computed(() =>
    props.refs.filter((item) => item.language_code === props.language),
);
const chosen = computed(() =>
    model.value
        .map((id) => props.refs.find((item) => item.id === id))
        .filter((item): item is CurriculumRef => item !== undefined),
);
const pick = ref('');

function add(): void {
    const id = Number(pick.value);
    if (id && !model.value.includes(id)) {
        model.value = [...model.value, id];
    }
    pick.value = '';
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div v-if="chosen.length > 0" class="flex flex-wrap gap-1">
            <span
                v-for="item in chosen"
                :key="item.id"
                class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-sm"
            >
                {{ curriculumLabel(item) }}
                <button
                    type="button"
                    class="rounded-full hover:bg-background"
                    :aria-label="`移除「${curriculumLabel(item)}」`"
                    @click="model = model.filter((id) => id !== item.id)"
                >
                    <X class="size-3" />
                </button>
            </span>
        </div>
        <select
            v-if="available.length > 0"
            :id="id"
            v-model="pick"
            class="h-9 rounded-md border bg-transparent px-3 text-base md:text-sm"
            @change="add"
        >
            <option value="">加入冊課…</option>
            <option
                v-for="item in available"
                :key="item.id"
                :value="item.id"
                :disabled="model.includes(item.id)"
            >
                {{ curriculumLabel(item) }}
            </option>
        </select>
        <p v-else class="text-sm text-muted-foreground">
            管理員還沒有建立這個語言的教材對照表。
        </p>
    </div>
</template>
