import type { MediaRef } from '@/types/kancil';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export class UploadError extends Error {}

// 上傳音檔或圖片（docs/SPEC.md T-05），伺服器轉檔後回傳媒體資料。
export async function uploadMedia(
    file: File,
    kind: 'audio' | 'image',
): Promise<MediaRef> {
    const body = new FormData();
    body.append('file', file);
    body.append('kind', kind);
    body.append('rights', '1');

    const response = await fetch('/media', {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
    });
    const data = (await response.json().catch(() => ({}))) as {
        message?: string;
        errors?: Record<string, string[]>;
    } & Partial<MediaRef>;

    if (!response.ok) {
        const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new UploadError(first ?? data.message ?? '上傳失敗');
    }

    return data as MediaRef;
}
