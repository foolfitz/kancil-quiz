<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: '個人資料設定',
                href: edit(),
            },
        ],
    },
});

defineProps<{
    // 已經連結 Google 帳號
    google: boolean;
    // 管理員不能刪除自己的帳號
    canDelete: boolean;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="個人資料設定" />

    <h1 class="sr-only">個人資料設定</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="個人資料"
            description="姓名會顯示在你公開的題組與上傳的檔案上"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">姓名</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="姓名"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    :default-value="user.email"
                    readonly
                    disabled
                />
                <p class="text-sm text-muted-foreground">
                    {{
                        google
                            ? '用 Google 帳號登入，email 來自你的 Google 帳號，不能在這裡修改。'
                            : 'email 不能在這裡修改。'
                    }}
                </p>
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >儲存</Button
                >
            </div>
        </Form>
    </div>

    <DeleteUser v-if="canDelete" :email="user.email" />
</template>
