<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import CurriculumController from '@/actions/App/Http/Controllers/CurriculumController';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { privacy, terms } from '@/routes';
import { redirect as googleRedirect } from '@/routes/google';
import { store } from '@/routes/login';

// 老師用 Google 帳號登入，第一次登入就建立帳號；密碼登入只給管理員（docs/SPEC.md T-01、T-03）
defineOptions({
    layout: {
        title: '老師登入',
        description:
            '用 Google 帳號登入，第一次登入就會建立帳號，不必另外註冊。',
    },
});

defineProps<{
    status?: string;
    googleLogin: boolean;
    // 本機開發時才提示要設定哪些環境變數
    setupHint: boolean;
}>();

const page = usePage();
const errors = computed(() => page.props.errors);
// 密碼登入失敗時，回到頁面要直接看到錯誤
const passwordOpen = computed(
    () => Boolean(errors.value.email) || Boolean(errors.value.password),
);
</script>

<template>
    <Head title="登入" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <div class="flex flex-col gap-4">
        <!-- Google 的 OAuth 要整頁導向，不走 Inertia -->
        <Button v-if="googleLogin" as-child size="lg" class="w-full">
            <a :href="googleRedirect().url" data-test="google-login">
                <svg viewBox="0 0 24 24" class="size-5" aria-hidden="true">
                    <path
                        fill="#4285F4"
                        d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1Z"
                    />
                    <path
                        fill="#34A853"
                        d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23Z"
                    />
                    <path
                        fill="#FBBC05"
                        d="M5.84 14.1A6.6 6.6 0 0 1 5.5 12c0-.73.13-1.44.34-2.1V7.06H2.18A11 11 0 0 0 1 12c0 1.78.43 3.45 1.18 4.94l3.66-2.84Z"
                    />
                    <path
                        fill="#EA4335"
                        d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15A10.96 10.96 0 0 0 12 1 11 11 0 0 0 2.18 7.06l3.66 2.84C6.71 7.3 9.14 5.38 12 5.38Z"
                    />
                </svg>
                用 Google 帳號登入
            </a>
        </Button>
        <p v-else class="text-center text-sm text-muted-foreground">
            Google 登入還沒有開放，現在可以先<Link
                :href="CurriculumController.index()"
                class="underline underline-offset-4"
                >瀏覽、試玩教材</Link
            >。
            <template v-if="setupHint">
                <br />
                本機開發：在 .env 設定 GOOGLE_CLIENT_ID、GOOGLE_CLIENT_SECRET。
            </template>
        </p>
        <InputError :message="errors.google" class="text-center" />

        <p class="text-center text-sm text-muted-foreground">
            平台只取得你在 Google 的名字與
            email，不會看到你的密碼。登入即表示你同意<Link
                :href="terms()"
                class="underline underline-offset-4"
                >使用條款</Link
            >與<Link :href="privacy()" class="underline underline-offset-4"
                >隱私權政策</Link
            >。
        </p>
    </div>

    <details
        class="mt-8 border-t pt-4 text-sm"
        :open="passwordOpen"
        data-test="password-login"
    >
        <summary
            class="cursor-pointer text-center text-muted-foreground select-none"
        >
            管理員：用 email 與密碼登入
        </summary>

        <div class="mt-6 flex flex-col gap-6">
            <PasskeyVerify />

            <Form
                v-bind="store.form()"
                :reset-on-success="['password']"
                v-slot="{ errors: formErrors, processing }"
                class="flex flex-col gap-6"
            >
                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autocomplete="email"
                        placeholder="email@example.com"
                    />
                    <InputError :message="formErrors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">密碼</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        required
                        autocomplete="current-password"
                        placeholder="密碼"
                    />
                    <InputError :message="formErrors.password" />
                </div>

                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" />
                    <span>記住我</span>
                </Label>

                <Button
                    type="submit"
                    variant="outline"
                    class="w-full"
                    :disabled="processing"
                    data-test="login-button"
                >
                    <Spinner v-if="processing" />
                    登入
                </Button>
            </Form>
        </div>
    </details>
</template>
