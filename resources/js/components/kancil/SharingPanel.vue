<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Copy, Globe, Link2, Link2Off } from '@lucide/vue';
import { ref } from 'vue';
import SetPublicationController from '@/actions/App/Http/Controllers/SetPublicationController';
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

// 申請公開到共備庫（T-12），審核者通過後才會公開（C-01）
const requesting = ref(false);
const note = ref('');
function requestPublication(): void {
    router.post(
        SetPublicationController.store(props.setId),
        { note: note.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                requesting.value = false;
                note.value = '';
            },
        },
    );
}

function withdraw(): void {
    const message =
        props.sharing.visibility === 'public'
            ? '下架後，其他老師就不能在共備庫找到這個題組，已經複製出去的不受影響。確定要下架？'
            : '確定要撤回公開申請？';
    if (window.confirm(message)) {
        router.delete(SetPublicationController.destroy(props.setId), {
            preserveScroll: true,
        });
    }
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

        <h2 class="border-t pt-3 text-sm font-medium">公開到共備庫</h2>
        <div
            v-if="sharing.visibility === 'public'"
            class="flex flex-wrap items-center gap-3"
            data-test="publication-status"
        >
            <span class="text-sm"
                >已公開。修改後會直接更新，每次修改都會留下版本紀錄。</span
            >
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="text-destructive"
                @click="withdraw"
                >下架</Button
            >
        </div>
        <div
            v-else-if="sharing.review_status === 'pending'"
            class="flex flex-wrap items-center gap-3"
            data-test="publication-status"
        >
            <span class="text-sm">已送出申請，等待審核者審核。</span>
            <Button type="button" variant="ghost" size="sm" @click="withdraw"
                >撤回申請</Button
            >
        </div>
        <div v-else class="space-y-2">
            <p
                v-if="
                    sharing.last_review &&
                    ['rejected', 'unpublished'].includes(
                        sharing.last_review.action,
                    )
                "
                class="rounded-md bg-amber-50 p-2 text-sm whitespace-pre-line dark:bg-amber-950/40"
                data-test="review-note"
            >
                審核者{{
                    sharing.last_review.action === 'rejected' ? '退回' : '下架'
                }}了這個題組：{{ sharing.last_review.note }}
            </p>
            <p class="text-sm text-muted-foreground">
                公開後，所有老師都能在共備庫找到並複製這個題組。需要先經過審核者確認。
            </p>
            <div v-if="requesting" class="space-y-2">
                <textarea
                    v-model="note"
                    rows="2"
                    maxlength="1000"
                    class="w-full rounded-md border bg-transparent p-2 text-base md:text-sm"
                    placeholder="給審核者的話（選填），例如這個題組適合的年級或用法"
                />
                <div class="flex gap-2">
                    <Button type="button" size="sm" @click="requestPublication"
                        >送出申請</Button
                    >
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="requesting = false"
                        >取消</Button
                    >
                </div>
            </div>
            <Button
                v-else
                type="button"
                variant="outline"
                size="sm"
                @click="requesting = true"
            >
                <Globe class="size-4" /> 申請公開
            </Button>
        </div>
    </section>
</template>
