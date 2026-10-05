import type { InjectionKey, Ref } from 'vue';
import { setUploadQuota } from '@/lib/uploadQuota';
import type { UploadQuota } from '@/lib/uploadQuota';
import type { MediaRef } from '@/types/kancil';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export class UploadError extends Error {}

// 題組編輯頁提供給上傳與署名欄位的資料（docs/SPEC.md 第 9 節）：新上傳的媒體以題組的授權為預設，
// 署名的授權選單列出老師能選的授權。
export interface MediaContext {
    license: string;
    licenses: string[];
}

export const MEDIA_CONTEXT: InjectionKey<Ref<MediaContext>> =
    Symbol('mediaContext');

// 上傳音檔或圖片（docs/SPEC.md T-05），伺服器轉檔後回傳媒體資料。作者預設是上傳的老師，
// 授權帶入題組的授權，之後都能在編輯頁的「署名」修改。
export async function uploadMedia(
    file: File,
    kind: 'audio' | 'image',
    context?: MediaContext | null,
): Promise<MediaRef> {
    const body = new FormData();
    body.append('file', file);
    body.append('kind', kind);
    body.append('rights', '1');
    if (context?.license) {
        body.append('license', context.license);
    }

    const response = await fetch('/media', {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
    });
    const data = (await response.json().catch(() => ({}))) as {
        message?: string;
        errors?: Record<string, string[]>;
        // 上傳後的用量，編輯頁的「已上傳多少／上限」用它更新
        quota?: UploadQuota;
    } & Partial<MediaRef>;

    if (!response.ok) {
        const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new UploadError(first ?? data.message ?? '上傳失敗');
    }

    const { quota, ...media } = data;
    if (quota) {
        setUploadQuota(quota);
    }

    return media as MediaRef;
}
