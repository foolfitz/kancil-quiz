<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: '驗證 Email',
        description: '請點選我們剛寄出的信件中的連結，完成 Email 驗證。',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="驗證 Email" />

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        新的驗證連結已寄到你註冊時填寫的 Email。
    </div>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button :disabled="processing" variant="secondary">
            <Spinner v-if="processing" />
            重新寄送驗證信
        </Button>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            登出
        </TextLink>
    </Form>
</template>
