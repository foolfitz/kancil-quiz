import { readonly, ref } from 'vue';

// 老師上傳的媒體總量與上限（docs/SPEC.md 第 9 節）。編輯頁載入時由 prop 帶入，每次上傳或錄音後
// uploadMedia() 以伺服器回應更新，不必重新載入頁面。算法在 App\Media\UploadQuota，這裡只負責顯示。

export interface UploadQuota {
    used_bytes: number;
    // null 表示不限制（管理員）
    limit_bytes: number | null;
}

export const MB = 1024 * 1024;

// 用到上限的這個比例就提醒快到上限了；與 App\Media\UploadQuota::WARN_RATIO 相同
export const WARN_RATIO = 0.8;

const current = ref<UploadQuota | null>(null);

export const uploadQuota = readonly(current);

export function setUploadQuota(quota: UploadQuota | null): void {
    current.value = quota;
}

// 與 App\Media\UploadQuota::format() 相同：不到 1 MB 顯示 KB，不到 10 MB 顯示一位小數，其餘取整數
export function formatBytes(bytes: number): string {
    if (bytes < MB) {
        return `${Math.round(bytes / 1024)} KB`;
    }
    const mb = bytes / MB;
    return `${mb < 10 ? Math.round(mb * 10) / 10 : Math.round(mb)} MB`;
}
