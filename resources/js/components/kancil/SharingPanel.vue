<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Copy, Link2, Link2Off } from '@lucide/vue';
import { ref } from 'vue';
import SetShareController from '@/actions/App/Http/Controllers/SetShareController';
import { Button } from '@/components/ui/button';
import type { SetSharing } from '@/types/kancil';

// 題組的分享與公開（docs/SPEC.md T-17、T-12）。只有擁有者看得到。
const props = defineProps<{ setId: string; sharing: SetSharing }>();

const copied = ref(false);
async function copyLink(): Promise<void> {
    if (props.sharing.share_url) {
        await navigator.clipboard.writeText(props.sharing.share_url);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    }
}

function share(): void {
    router.post(
        SetShareController.store(props.setId),
        {},
        { preserveScroll: true },
    );
}

function unshare(): void {
    if (
        window.confirm(
            '收回後，舊的連結就不能再開啟。已經複製出去的題組不受影響。確定要收回？',
        )
    ) {
        router.delete(SetShareController.destroy(props.setId), {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <section class="space-y-3 rounded-lg border p-3" data-test="sharing-panel">
        <h2 class="text-sm font-medium">分享給同事</h2>

        <p
            v-if="sharing.visibility === 'public'"
            class="text-sm text-muted-foreground"
        >
            這個題組已經公開在共備庫，所有老師都看得到，也能複製。
        </p>
        <template v-else-if="sharing.share_url">
            <p class="text-sm text-muted-foreground">
                拿到這個連結的老師登入後，可以檢視並複製這個題組，不必經過審核。這不是給學生的連結。
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <code
                    class="rounded bg-muted px-2 py-1 text-sm break-all"
                    data-test="share-url"
                    >{{ sharing.share_url }}</code
                >
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="copyLink"
                >
                    <Copy class="size-4" />
                    {{ copied ? '已複製' : '複製連結' }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="text-destructive"
                    @click="unshare"
                >
                    <Link2Off class="size-4" /> 收回連結
                </Button>
            </div>
        </template>
        <div v-else class="flex flex-wrap items-center gap-3">
            <Button type="button" variant="outline" size="sm" @click="share">
                <Link2 class="size-4" /> 產生分享連結
            </Button>
            <span class="text-sm text-muted-foreground"
                >目前只有你看得到這個題組。</span
            >
        </div>
    </section>
</template>
