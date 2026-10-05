<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Download, Gamepad2 } from '@lucide/vue';
import { computed, provide, ref, watch } from 'vue';
import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
import SetController from '@/actions/App/Http/Controllers/SetController';
import SetExportController from '@/actions/App/Http/Controllers/SetExportController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import CurriculumPicker from '@/components/kancil/CurriculumPicker.vue';
import QuizEditor from '@/components/kancil/QuizEditor.vue';
import SharingPanel from '@/components/kancil/SharingPanel.vue';
import TagInput from '@/components/kancil/TagInput.vue';
import UploadQuotaMeter from '@/components/kancil/UploadQuotaMeter.vue';
import VocabEditor from '@/components/kancil/VocabEditor.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MEDIA_CONTEXT } from '@/lib/media';
import type { UploadQuota } from '@/lib/uploadQuota';
import { KIND_NAMES } from '@/types/kancil';
import type {
    CurriculumRef,
    FaceField,
    Language,
    MediaRef,
    QuizEntryInput,
    SetSharing,
    SetKind,
    VocabEntryInput,
} from '@/types/kancil';

const props = defineProps<{
    set: {
        id: string;
        kind: SetKind;
        title: string;
        description: string | null;
        language_code: string;
        license: string;
        faces: { prompt: FaceField[]; answer: FaceField[] } | null;
        owner: string;
        tags: string[];
        curriculum_ref_ids: number[];
        revision: number | null;
        textbook_url: string | null;
    };
    entries: (VocabEntryInput | QuizEntryInput)[];
    can: { manage: boolean; export: boolean };
    sharing: SetSharing | null;
    activities: { id: string; game: string }[];
    languages: Language[];
    licenses: string[];
    curriculumRefs: CurriculumRef[];
    uploadQuota: UploadQuota;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: '我的題組', href: SetController.index() }],
    },
});

// 詞彙組「哪一面當題目、哪一面當答案」的常用組合（docs/SPEC.md 3.2）
const FACE_PRESETS: {
    label: string;
    prompt: FaceField[];
    answer: FaceField[];
}[] = [
    { label: '看中文，選目標語', prompt: ['translation_zh'], answer: ['text'] },
    { label: '看目標語，選中文', prompt: ['text'], answer: ['translation_zh'] },
    { label: '聽發音，選目標語', prompt: ['audio'], answer: ['text'] },
    { label: '聽發音，選中文', prompt: ['audio'], answer: ['translation_zh'] },
    { label: '看圖片，選目標語', prompt: ['image'], answer: ['text'] },
    { label: '看中文，選圖片', prompt: ['translation_zh'], answer: ['image'] },
    { label: '聽發音，選圖片', prompt: ['audio'], answer: ['image'] },
];

function initial() {
    return {
        title: props.set.title,
        description: props.set.description ?? '',
        language_code: props.set.language_code,
        license: props.set.license,
        tags: [...props.set.tags],
        curriculum_ref_ids: [...props.set.curriculum_ref_ids],
        faces: props.set.faces ?? {
            prompt: ['translation_zh'],
            answer: ['text'],
        },
        // props 是 reactive Proxy，structuredClone 無法複製；內容本來就是 JSON
        entries: JSON.parse(JSON.stringify(props.entries)) as (
            | VocabEntryInput
            | QuizEntryInput
        )[],
    };
}

const form = useForm(initial());
const rightsConfirmed = ref(false);
// 新上傳的媒體以題組的授權為預設，署名的選單列出老師能選的授權（docs/SPEC.md 第 9 節）
provide(
    MEDIA_CONTEXT,
    computed(() => ({ license: form.license, licenses: props.licenses })),
);
const showRomanization = ref(
    props.set.kind === 'vocab' &&
        (props.entries as VocabEntryInput[]).some((e) => e.item.romanization),
);
const uploadError = ref('');

const vocabEntries = computed({
    get: () => form.entries as VocabEntryInput[],
    set: (value) => (form.entries = value),
});
const quizEntries = computed({
    get: () => form.entries as QuizEntryInput[],
    set: (value) => (form.entries = value),
});

const facePreset = computed({
    get: () =>
        FACE_PRESETS.findIndex(
            (p) =>
                p.prompt.join() === form.faces.prompt.join() &&
                p.answer.join() === form.faces.answer.join(),
        ),
    set: (index: number) => {
        const preset = FACE_PRESETS[index];
        if (preset) {
            form.faces = {
                prompt: [...preset.prompt],
                answer: [...preset.answer],
            };
        }
    },
});

// 換語言時，拿掉其他語言的冊課
watch(
    () => form.language_code,
    (language) => {
        form.curriculum_ref_ids = form.curriculum_ref_ids.filter(
            (id) =>
                props.curriculumRefs.find((r) => r.id === id)?.language_code ===
                language,
        );
    },
);

const errorCount = computed(() => Object.keys(form.errors).length);
const firstError = (prefix: string) =>
    Object.entries(form.errors).find(([key]) => key.startsWith(prefix))?.[1];

function toServer(entry: VocabEntryInput | QuizEntryInput) {
    if ('item' in entry) {
        return {
            id: entry.id,
            item: {
                text: entry.item.text,
                romanization: entry.item.romanization,
                translation_zh: entry.item.translation_zh,
                audio_ids: entry.item.audio.map((m) => m.id),
                image_id: entry.item.image?.id ?? null,
            },
        };
    }
    return {
        id: entry.id,
        question: {
            stem: {
                text: entry.question.stem.text,
                audio_id: entry.question.stem.audio?.id ?? null,
                image_id: entry.question.stem.image?.id ?? null,
            },
            options: entry.question.options.map((o) => ({
                id: o.id,
                text: o.text,
                image_id: o.image?.id ?? null,
                correct: o.correct,
            })),
        },
    };
}

// 自己上傳的媒體的署名，和內容一起儲存（App\Corpus\MediaCredits）；同一個媒體用在好幾題只送一次
function mediaCredits(entries: (VocabEntryInput | QuizEntryInput)[]) {
    const all = entries.flatMap((entry): (MediaRef | null)[] =>
        'item' in entry
            ? [...entry.item.audio, entry.item.image]
            : [
                  entry.question.stem.audio,
                  entry.question.stem.image,
                  ...entry.question.options.map((o) => o.image),
              ],
    );
    const seen = new Set<string>();
    return all
        .filter((media): media is MediaRef => media !== null && media.editable)
        .filter((media) => !seen.has(media.id) && seen.add(media.id))
        .map(({ id, author, source, license }) => ({
            id,
            author,
            source,
            license,
        }));
}

function save(): void {
    form.transform((data) => ({
        ...data,
        faces: props.set.kind === 'vocab' ? data.faces : undefined,
        entries: data.entries.map(toServer),
        media_credits: mediaCredits(data.entries),
    })).submit(SetController.update(props.set.id), {
        preserveScroll: true,
        // 儲存後以伺服器回傳的內容（新詞條有了 ID）重設表單
        onSuccess: () => {
            form.defaults(initial());
            form.reset();
        },
    });
}

function destroy(): void {
    if (
        window.confirm(
            `確定要刪除「${props.set.title}」？這個題組的活動也會一併刪除。`,
        )
    ) {
        router.delete(SetController.destroy(props.set.id));
    }
}
</script>

<template>
    <Head :title="set.title" />

    <form class="flex flex-col gap-6 p-4 pb-28" @submit.prevent="save">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <Badge variant="secondary">{{
                        KIND_NAMES[set.kind]
                    }}</Badge>
                    <Link
                        v-if="set.revision"
                        :href="`${SetController.show.url(set.id)}#revisions`"
                        class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        第 {{ set.revision }} 版・修訂紀錄
                    </Link>
                </div>
                <Heading :title="set.title" class="mt-2" />
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="can.export && !form.isDirty"
                    as-child
                    variant="outline"
                >
                    <a
                        :href="SetExportController.show.url(set.id)"
                        data-test="export-set"
                        ><Download class="size-4" /> 匯出 zip</a
                    >
                </Button>
                <Button
                    v-if="can.manage && !form.isDirty"
                    as-child
                    variant="default"
                >
                    <Link :href="ActivityController.create(set.id)">
                        <Gamepad2 class="size-4" /> 選遊戲、建立活動
                    </Link>
                </Button>
                <span
                    v-else-if="can.manage"
                    class="text-sm text-muted-foreground"
                >
                    有尚未儲存的修改，儲存後才能建立活動或匯出。
                </span>
            </div>
        </div>

        <p
            v-if="!can.manage"
            class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/40"
            data-test="curator-notice"
        >
            <template v-if="set.textbook_url">
                你正在修正<Link
                    :href="set.textbook_url"
                    class="underline underline-offset-4"
                    >教材題組</Link
                >。儲存後立即生效，用這一課建立的活動也會跟著改，修改會記在修訂紀錄中。請一併通知管理員把修正寫回教材資料檔，否則之後重新匯入時會略過這一課。
            </template>
            <template v-else>
                你正在以審核者身分修正 {{ set.owner }}
                的公開題組。儲存後立即生效，修改會記在修訂紀錄中，擁有者看得到。
            </template>
        </p>

        <SharingPanel v-if="sharing" :set-id="set.id" :sharing="sharing" />

        <section v-if="activities.length > 0" class="rounded-lg border p-3">
            <h2 class="text-sm font-medium">這個題組的活動</h2>
            <ul class="mt-2 flex flex-wrap gap-2">
                <li v-for="activity in activities" :key="activity.id">
                    <Link
                        :href="ActivityController.show(activity.id)"
                        class="inline-flex items-center rounded-full border px-3 py-1 text-sm hover:border-primary"
                    >
                        {{ activity.game }}
                    </Link>
                </li>
            </ul>
            <p class="mt-2 text-xs text-muted-foreground">
                活動一律使用題組的最新版本，修改並儲存後，已經發出去的連結會立即更新。
            </p>
        </section>

        <section class="grid gap-4 md:grid-cols-2">
            <div class="grid gap-2">
                <Label for="title">標題</Label>
                <Input id="title" v-model="form.title" />
                <InputError :message="form.errors.title" />
            </div>
            <div class="grid gap-2">
                <Label for="language">語言</Label>
                <select
                    id="language"
                    v-model="form.language_code"
                    class="h-9 rounded-md border bg-transparent px-3 text-base md:text-sm"
                    :disabled="!can.manage"
                >
                    <option
                        v-for="language in languages"
                        :key="language.code"
                        :value="language.code"
                    >
                        {{ language.name_zh }}（{{ language.name_native }}）
                    </option>
                </select>
            </div>
            <div class="grid gap-2 md:col-span-2">
                <Label for="description">說明（選填）</Label>
                <Input id="description" v-model="form.description" />
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
            </div>
            <div class="grid gap-2">
                <Label for="curriculum">對應教材（選填）</Label>
                <CurriculumPicker
                    id="curriculum"
                    v-model="form.curriculum_ref_ids"
                    :refs="curriculumRefs"
                    :language="form.language_code"
                />
                <InputError :message="firstError('curriculum_ref_ids')" />
            </div>
            <div class="grid gap-2">
                <Label for="tags">標籤（選填）</Label>
                <TagInput id="tags" v-model="form.tags" />
                <InputError :message="firstError('tags')" />
            </div>
            <div v-if="set.kind === 'vocab'" class="grid gap-2">
                <Label for="faces">出題方式</Label>
                <select
                    id="faces"
                    v-model="facePreset"
                    class="h-9 rounded-md border bg-transparent px-3 text-base md:text-sm"
                >
                    <option :value="-1" disabled>自訂</option>
                    <option
                        v-for="(preset, i) in FACE_PRESETS"
                        :key="preset.label"
                        :value="i"
                    >
                        {{ preset.label }}
                    </option>
                </select>
                <InputError
                    :message="
                        form.errors['faces.prompt'] ??
                        form.errors['faces.answer']
                    "
                />
            </div>
        </section>

        <section class="space-y-3">
            <label
                class="flex items-start gap-2 rounded-lg bg-muted/50 p-3 text-sm"
            >
                <input v-model="rightsConfirmed" type="checkbox" class="mt-1" />
                <span>
                    我有權分享這次上傳的音檔與圖片，並同意以題組的授權（{{
                        form.license
                    }}）釋出。
                </span>
            </label>
            <label
                v-if="set.kind === 'vocab'"
                class="flex items-center gap-2 text-sm"
            >
                <input v-model="showRomanization" type="checkbox" />
                顯示「羅馬拼寫」欄位
            </label>
            <p v-if="uploadError" class="text-sm text-destructive" role="alert">
                {{ uploadError }}
            </p>
            <UploadQuotaMeter :quota="uploadQuota" />
        </section>

        <VocabEditor
            v-if="set.kind === 'vocab'"
            v-model="vocabEntries"
            :errors="form.errors"
            :rights-confirmed="rightsConfirmed"
            :show-romanization="showRomanization"
            @error="uploadError = $event"
        />
        <QuizEditor
            v-else
            v-model="quizEntries"
            :errors="form.errors"
            :rights-confirmed="rightsConfirmed"
            @error="uploadError = $event"
        />

        <div v-if="can.manage">
            <Button
                type="button"
                variant="ghost"
                class="text-destructive"
                @click="destroy"
            >
                刪除題組
            </Button>
        </div>

        <div
            class="fixed inset-x-0 bottom-0 z-10 flex items-center justify-end gap-4 border-t bg-background/95 px-6 py-3 backdrop-blur md:left-(--sidebar-width)"
        >
            <span v-if="errorCount > 0" class="text-sm text-destructive">
                有 {{ errorCount }} 個欄位需要修正
            </span>
            <span
                v-else-if="form.recentlySuccessful"
                class="text-sm text-muted-foreground"
            >
                已儲存
            </span>
            <Button type="submit" :disabled="form.processing || !form.isDirty">
                {{ form.processing ? '儲存中…' : '儲存' }}
            </Button>
        </div>
    </form>
</template>
