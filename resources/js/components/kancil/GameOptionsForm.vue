<script setup lang="ts">
import type { GameInfo, OptionSchema, OptionValues } from '@/types/kancil';

// 依遊戲的 optionsSchema 自動產生設定表單（docs/SPEC.md 7.2）。
const props = defineProps<{ game: GameInfo }>();
const model = defineModel<OptionValues>({ required: true });

const fields = Object.entries(props.game.optionsSchema.properties ?? {});

function choices(
    schema: OptionSchema,
): { value: string | number; label: string }[] | null {
    if (schema.oneOf) {
        return schema.oneOf.map((c) => ({
            value: c.const,
            label: c.title ?? String(c.const),
        }));
    }
    if (schema.enum) {
        return schema.enum.map((value) => ({ value, label: String(value) }));
    }
    return null;
}

function setNumber(key: string, value: string): void {
    model.value = { ...model.value, [key]: Number(value) };
}
</script>

<template>
    <div class="grid gap-4">
        <p v-if="fields.length === 0" class="text-sm text-muted-foreground">
            這個遊戲沒有可以調整的設定。
        </p>
        <div v-for="[key, schema] in fields" :key="key" class="grid gap-1">
            <label
                v-if="schema.type === 'boolean'"
                class="flex items-center gap-2 text-sm"
            >
                <input
                    type="checkbox"
                    :checked="Boolean(model[key])"
                    @change="
                        model = {
                            ...model,
                            [key]: ($event.target as HTMLInputElement).checked,
                        }
                    "
                />
                {{ schema.title ?? key }}
            </label>
            <template v-else>
                <label :for="`option-${key}`" class="text-sm font-medium">{{
                    schema.title ?? key
                }}</label>
                <select
                    v-if="choices(schema)"
                    :id="`option-${key}`"
                    class="h-9 max-w-xs rounded-md border bg-transparent px-3 text-base md:text-sm"
                    :value="model[key]"
                    @change="
                        model = {
                            ...model,
                            [key]:
                                typeof schema.enum?.[0] === 'number' ||
                                typeof schema.oneOf?.[0]?.const === 'number'
                                    ? Number(
                                          ($event.target as HTMLSelectElement)
                                              .value,
                                      )
                                    : ($event.target as HTMLSelectElement)
                                          .value,
                        }
                    "
                >
                    <option
                        v-for="choice in choices(schema)"
                        :key="choice.value"
                        :value="choice.value"
                    >
                        {{ choice.label }}
                    </option>
                </select>
                <input
                    v-else-if="
                        schema.type === 'integer' || schema.type === 'number'
                    "
                    :id="`option-${key}`"
                    type="number"
                    class="h-9 max-w-32 rounded-md border bg-transparent px-3 text-base md:text-sm"
                    :min="schema.minimum"
                    :max="schema.maximum"
                    :value="model[key]"
                    @change="
                        setNumber(
                            key,
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
            </template>
            <p v-if="schema.description" class="text-xs text-muted-foreground">
                {{ schema.description }}
            </p>
        </div>
    </div>
</template>
