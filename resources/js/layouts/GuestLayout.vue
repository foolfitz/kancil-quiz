<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import CurriculumController from '@/actions/App/Http/Controllers/CurriculumController';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, home, login, privacy, terms } from '@/routes';
import type { BreadcrumbItem, User } from '@/types';

// 不需登入的頁面（docs/SPEC.md S-06）：首頁與教材。已登入的老師看教材時改用側邊欄的 AppLayout（resources/js/app.ts）。
defineProps<{ breadcrumbs?: BreadcrumbItem[] }>();

const page = usePage();
// 首頁不論登入與否都用這個版面
const user = computed(() => page.props.auth.user as User | null);
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header class="border-b">
            <div
                class="mx-auto flex h-14 w-full max-w-6xl items-center gap-4 px-4"
            >
                <Link
                    :href="home()"
                    class="flex items-center gap-2 font-semibold"
                >
                    <span
                        class="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground"
                    >
                        <AppLogoIcon class="size-5 fill-current" />
                    </span>
                    {{ page.props.name }}
                </Link>
                <nav class="text-sm">
                    <Link
                        :href="CurriculumController.index()"
                        class="text-muted-foreground hover:text-foreground"
                        >教材</Link
                    >
                </nav>
                <div class="ml-auto">
                    <Button v-if="user" as-child size="sm">
                        <Link :href="dashboard()">我的首頁</Link>
                    </Button>
                    <Button v-else as-child size="sm" variant="outline">
                        <Link :href="login()">老師登入</Link>
                    </Button>
                </div>
            </div>
        </header>
        <main class="mx-auto w-full max-w-6xl flex-1">
            <slot />
        </main>
        <footer class="border-t">
            <div
                class="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-6 text-sm text-muted-foreground"
            >
                <p>
                    Kancil
                    Quiz：給新住民語文老師的互動練習平台，同一份題組可以切換成多種遊戲。程式碼以
                    AGPL-3.0 開放原始碼。
                </p>
                <nav class="flex gap-4">
                    <Link :href="privacy()" class="hover:text-foreground"
                        >隱私權政策</Link
                    >
                    <Link :href="terms()" class="hover:text-foreground"
                        >使用條款</Link
                    >
                </nav>
            </div>
        </footer>
    </div>
</template>
