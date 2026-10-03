<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { BookOpen, ClipboardCheck, LayoutGrid, Library } from '@lucide/vue';
import { computed } from 'vue';
import SetController from '@/actions/App/Http/Controllers/SetController';
import SetReviewController from '@/actions/App/Http/Controllers/SetReviewController';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard, library } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();
const mainNavItems = computed<NavItem[]>(() => [
    {
        title: '首頁',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: '我的題組',
        href: SetController.index(),
        icon: Library,
    },
    {
        title: '共備庫',
        href: library(),
        icon: BookOpen,
    },
    ...(page.props.auth.canReview
        ? [
              {
                  title: '待審題組',
                  href: SetReviewController.index(),
                  icon: ClipboardCheck,
              },
          ]
        : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
