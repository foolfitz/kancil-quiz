<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import SetController from '@/actions/App/Http/Controllers/SetController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Language, SetKind } from '@/types/kancil';

const props = defineProps<{ languages: Language[]; licenses: string[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: '我的題組', href: SetController.index() },
            { title: '建立題組', href: SetController.create() },
        ],
    },
});

const form = useForm({
    kind: 'vocab' as SetKind,
    title: '',
    description: '',
    language_code: props.languages[0]?.code ?? '',
    license: 'CC-BY-4.0',
});

const kinds: { value: SetKind; title: string; description: string }[] = [
    {
        value: 'vocab',
        title: '詞彙組',
        description:
            '一組詞或短句，每個有目標語、中文，可附發音與圖片。能自動變成選擇題、配對、字卡等遊戲。',
    },
    {
        value: 'quiz',
        title: '問答組',
        description: '自己寫題幹與 2 到 6 個選項，標示一個正解。',
    },
];
</script>

<template>
    <Head title="建立題組" />

    <form
        class="flex max-w-2xl flex-col gap-6 p-4"
        @submit.prevent="form.submit(SetController.store())"
    >
        <Heading title="建立題組" />

        <fieldset class="grid gap-3 sm:grid-cols-2">
            <legend class="mb-2 text-sm font-medium">題組種類</legend>
            <label
                v-for="kind in kinds"
                :key="kind.value"
                class="cursor-pointer rounded-xl border p-4"
                :class="
                    form.kind === kind.value
                        ? 'border-primary ring-2 ring-primary/30'
                        : ''
                "
            >
                <input
                    v-model="form.kind"
                    type="radio"
                    name="kind"
                    :value="kind.value"
                    class="sr-only"
                />
                <span class="font-semibold">{{ kind.title }}</span>
                <span class="mt-1 block text-sm text-muted-foreground">{{
                    kind.description
                }}</span>
            </label>
        </fieldset>

        <div class="grid gap-2">
            <Label for="title">標題</Label>
            <Input
                id="title"
                v-model="form.title"
                required
                placeholder="例：第 3 冊第 2 課 水果"
            />
            <InputError :message="form.errors.title" />
        </div>

        <div class="grid gap-2">
            <Label for="language">語言</Label>
            <select
                id="language"
                v-model="form.language_code"
                class="h-9 rounded-md border bg-transparent px-3 text-base md:text-sm"
            >
                <option
                    v-for="language in languages"
                    :key="language.code"
                    :value="language.code"
                >
                    {{ language.name_zh }}（{{ language.name_native }}）
                </option>
            </select>
            <InputError :message="form.errors.language_code" />
        </div>

        <div class="grid gap-2">
            <Label for="license">授權</Label>
            <select
                id="license"
                v-model="form.license"
                class="h-9 rounded-md border bg-transparent px-3 text-base md:text-sm"
            >
                <option
                    v-for="license in licenses"
                    :key="license"
                    :value="license"
                >
                    {{ license }}
                </option>
            </select>
            <p class="text-sm text-muted-foreground">
                題組公開後，其他老師可以依這個授權複製與改編。
            </p>
        </div>

        <div>
            <Button type="submit" :disabled="form.processing"
                >建立並開始出題</Button
            >
        </div>
    </form>
</template>
