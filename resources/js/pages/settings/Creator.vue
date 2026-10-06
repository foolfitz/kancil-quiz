<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import CreatorProfileController from '@/actions/App/Http/Controllers/Settings/CreatorProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/creator';
import { licenseName } from '@/types/kancil';
import type { Language } from '@/types/kancil';

// 創作者資料（docs/SPEC.md T-20）：署名會自動填進之後寫下的作者（匯出的題組、修改複製來的詞條、
// 上傳的音檔與圖片）；已經寫下的署名不會回頭改。貢獻統計不在這裡填，由公開的內容算出來。
const props = defineProps<{
    profile: {
        attribution_name: string | null;
        attribution_url: string | null;
        default_license: string;
        school: string | null;
        teaching_languages: string[];
        bio: string | null;
    };
    licenses: string[];
    languages: Language[];
    bioMax: number;
    profileUrl: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: '創作者資料', href: edit() }],
    },
});

const page = usePage();
const accountName = computed(() => page.props.auth.user.name);

const form = useForm({
    attribution_name: props.profile.attribution_name ?? '',
    attribution_url: props.profile.attribution_url ?? '',
    default_license: props.profile.default_license,
    school: props.profile.school ?? '',
    teaching_languages: [...props.profile.teaching_languages],
    bio: props.profile.bio ?? '',
});

function toggleLanguage(code: string, checked: boolean): void {
    form.teaching_languages = checked
        ? [...new Set([...form.teaching_languages, code])]
        : form.teaching_languages.filter((item) => item !== code);
}

function submit(): void {
    form.patch(CreatorProfileController.update.url(), {
        preserveScroll: true,
    });
}

const bioLength = computed(() => [...form.bio].length);
</script>

<template>
    <Head title="創作者資料" />

    <h1 class="sr-only">創作者資料</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="創作者資料"
            description="署名會自動填進你公開的題組、改編的詞條與上傳的檔案；其他登入的老師在共備庫點你的名字，會看到這些資料與你公開的題組。"
        />

        <p v-if="profileUrl" class="text-sm">
            <Link
                :href="profileUrl"
                class="underline underline-offset-4"
                data-test="view-profile"
                >查看我的創作者頁面</Link
            >
        </p>

        <form class="space-y-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="attribution_name">署名名稱</Label>
                <Input
                    id="attribution_name"
                    v-model="form.attribution_name"
                    name="attribution_name"
                    maxlength="100"
                    :placeholder="accountName"
                    autocomplete="off"
                />
                <p class="text-sm text-muted-foreground">
                    作者欄會寫這個名字，可以和帳號的名字不同，例如「{{
                        accountName
                    }}（臺北市○○國小）」。留空就用帳號的名字。已經寫下的署名不會跟著改。
                </p>
                <InputError :message="form.errors.attribution_name" />
            </div>

            <div class="grid gap-2">
                <Label for="attribution_url">網址（選填）</Label>
                <Input
                    id="attribution_url"
                    v-model="form.attribution_url"
                    name="attribution_url"
                    type="url"
                    inputmode="url"
                    maxlength="300"
                    placeholder="https://"
                    autocomplete="url"
                />
                <p class="text-sm text-muted-foreground">
                    附在署名上的網址，例如你的教學網站或學校網頁，會一起寫進匯出的題組。
                </p>
                <InputError :message="form.errors.attribution_url" />
            </div>

            <div class="grid gap-2">
                <Label for="default_license">預設授權</Label>
                <select
                    id="default_license"
                    v-model="form.default_license"
                    name="default_license"
                    class="h-9 rounded-md border bg-transparent px-3 text-base md:text-sm"
                >
                    <option
                        v-for="license in licenses"
                        :key="license"
                        :value="license"
                    >
                        {{ licenseName(license) }}
                    </option>
                </select>
                <p class="text-sm text-muted-foreground">
                    新建題組時預先選好的授權，每個題組仍可以個別更改。
                </p>
                <InputError :message="form.errors.default_license" />
            </div>

            <div class="grid gap-2">
                <Label for="school">學校（選填）</Label>
                <Input
                    id="school"
                    v-model="form.school"
                    name="school"
                    maxlength="100"
                    autocomplete="organization"
                />
                <InputError :message="form.errors.school" />
            </div>

            <fieldset class="grid gap-2">
                <legend class="text-sm font-medium">教的語言（選填）</legend>
                <div class="flex flex-wrap gap-x-4 gap-y-2">
                    <label
                        v-for="language in languages"
                        :key="language.code"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            type="checkbox"
                            name="teaching_languages[]"
                            :value="language.code"
                            :checked="
                                form.teaching_languages.includes(language.code)
                            "
                            class="size-4"
                            @change="
                                toggleLanguage(
                                    language.code,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                        {{ language.name_zh }}（{{ language.name_native }}）
                    </label>
                </div>
                <InputError
                    :message="
                        form.errors.teaching_languages ??
                        form.errors['teaching_languages.0']
                    "
                />
            </fieldset>

            <div class="grid gap-2">
                <Label for="bio">簡介（選填）</Label>
                <textarea
                    id="bio"
                    v-model="form.bio"
                    name="bio"
                    rows="4"
                    :maxlength="bioMax"
                    class="w-full rounded-md border bg-transparent p-2 text-base shadow-xs md:text-sm"
                    placeholder="例如：教印尼語五年，喜歡用遊戲帶詞彙。"
                />
                <p class="text-sm text-muted-foreground">
                    純文字，最多 {{ bioMax }} 字（{{ bioLength }}／{{
                        bioMax
                    }}）。
                </p>
                <InputError :message="form.errors.bio" />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="update-creator-button"
                    >儲存</Button
                >
                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-show="form.recentlySuccessful"
                        class="text-sm text-neutral-600"
                    >
                        已儲存
                    </p>
                </Transition>
            </div>
        </form>
    </div>
</template>
