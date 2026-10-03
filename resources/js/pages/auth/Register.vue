<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

// 邀請制註冊（docs/SPEC.md T-02）：沒有有效的邀請連結就不顯示表單。
defineProps<{
    passwordRules: string;
    invitation: string | null;
    invitationEmail: string | null;
}>();

defineOptions({
    layout: {
        title: '建立帳號',
        description: '使用管理員給你的邀請連結建立帳號',
    },
});
</script>

<template>
    <Head title="註冊" />

    <div v-if="!invitation" class="flex flex-col gap-4 text-center">
        <p>
            註冊需要邀請連結。請向管理員或各語言的審核老師索取，或確認連結是否完整、是否已過期。
        </p>
        <div class="text-sm text-muted-foreground">
            已經有帳號了？
            <TextLink :href="login()" class="underline underline-offset-4"
                >登入</TextLink
            >
        </div>
    </div>

    <Form
        v-else
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <input type="hidden" name="invitation" :value="invitation" />
        <InputError :message="errors.invitation" />

        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="name">姓名</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="name"
                    name="name"
                    placeholder="例：王小明"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    :tabindex="2"
                    autocomplete="email"
                    name="email"
                    :default-value="invitationEmail ?? undefined"
                    :readonly="invitationEmail !== null"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">密碼</Label>
                <PasswordInput
                    id="password"
                    required
                    :tabindex="3"
                    autocomplete="new-password"
                    name="password"
                    placeholder="密碼"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">再輸入一次密碼</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    :tabindex="4"
                    autocomplete="new-password"
                    name="password_confirmation"
                    placeholder="再輸入一次密碼"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                tabindex="5"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                建立帳號
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            已經有帳號了？
            <TextLink
                :href="login()"
                class="underline underline-offset-4"
                :tabindex="6"
                >登入</TextLink
            >
        </div>
    </Form>
</template>
