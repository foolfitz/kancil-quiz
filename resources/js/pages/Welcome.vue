<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';
</script>

<template>
    <Head title="歡迎使用" />

    <div
        class="flex min-h-screen flex-col items-center justify-center bg-background p-6 text-foreground"
    >
        <main
            class="flex w-full max-w-md flex-col items-center gap-8 text-center"
        >
            <div class="space-y-4">
                <h1 class="text-4xl font-semibold tracking-tight">
                    Kancil Quiz
                </h1>
                <p class="text-base leading-relaxed text-muted-foreground">
                    給新住民語文老師的互動練習平台：同一份題組，可以切換成不同的遊戲。
                </p>
            </div>

            <div class="flex flex-col items-center gap-3">
                <Button v-if="$page.props.auth.user" as-child size="lg">
                    <Link :href="dashboard()">前往首頁</Link>
                </Button>
                <template v-else>
                    <Button as-child size="lg">
                        <Link :href="login()">登入</Link>
                    </Button>
                    <p class="text-sm text-muted-foreground">
                        需要邀請連結才能註冊。
                    </p>
                </template>
            </div>
        </main>
    </div>
</template>
