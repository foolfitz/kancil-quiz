<script setup lang="ts">
import { X } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import type { ActivitySettings } from '@/types/kancil';

// 活動的兩個設定（docs/SPEC.md 3.4）：學生要不要輸入名字或座號，以及開放與截止時間。
// 建立活動頁與活動頁的「修改設定」共用。
defineProps<{
    errors: Partial<Record<keyof ActivitySettings, string>>;
}>();
const model = defineModel<ActivitySettings>({ required: true });

const times = [
    {
        key: 'opens_at',
        label: '開放時間',
        empty: '不設：立刻開放。',
    },
    {
        key: 'closes_at',
        label: '截止時間',
        empty: '不設截止：連結一直有效，可以隨時在活動頁按「立即截止」。',
    },
] as const;

function setTime(key: 'opens_at' | 'closes_at', value: string): void {
    model.value = { ...model.value, [key]: value === '' ? null : value };
}
</script>

<template>
    <div class="grid gap-4">
        <div class="grid gap-1">
            <label class="flex items-center gap-2 text-sm font-medium">
                <input
                    type="checkbox"
                    :checked="model.require_label"
                    @change="
                        model = {
                            ...model,
                            require_label: ($event.target as HTMLInputElement)
                                .checked,
                        }
                    "
                />
                學生要先輸入名字或座號
            </label>
            <p class="pl-6 text-sm text-muted-foreground">
                作答結果會依名字列出每位學生的成績。不勾選時學生直接開始，不記名。
            </p>
            <InputError :message="errors.require_label" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div v-for="time in times" :key="time.key" class="grid gap-1">
                <label
                    :for="`activity-${time.key}`"
                    class="text-sm font-medium"
                    >{{ time.label }}</label
                >
                <div class="flex items-center gap-1">
                    <input
                        :id="`activity-${time.key}`"
                        type="datetime-local"
                        class="h-9 min-w-0 flex-1 rounded-md border bg-transparent px-3 text-base md:text-sm"
                        :value="model[time.key] ?? ''"
                        @change="
                            setTime(
                                time.key,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <button
                        v-if="model[time.key]"
                        type="button"
                        class="rounded-md p-2 text-muted-foreground hover:bg-muted"
                        :title="`清除${time.label}`"
                        :aria-label="`清除${time.label}`"
                        @click="setTime(time.key, '')"
                    >
                        <X class="size-4" />
                    </button>
                </div>
                <p
                    v-if="!model[time.key]"
                    class="text-sm text-muted-foreground"
                >
                    {{ time.empty }}
                </p>
                <InputError :message="errors[time.key]" />
            </div>
        </div>
    </div>
</template>
