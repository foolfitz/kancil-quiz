import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import GuestLayout from '@/layouts/GuestLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import type { Auth } from '@/types';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name, page) => {
        switch (true) {
            // 首頁、隱私權政策與使用條款不論登入與否都用簡單的頁首
            case name === 'Welcome' || name.startsWith('legal/'):
                return GuestLayout;
            // 教材不需登入（docs/SPEC.md S-06）：訪客用簡單的頁首，老師照舊用側邊欄
            case name.startsWith('curriculum/') &&
                !(page.props.auth as Auth | null)?.user:
                return GuestLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
