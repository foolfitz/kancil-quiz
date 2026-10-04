import type { KancilSet } from '@kancil-quiz/schema';
import { ZipError, readZip } from './zip';

// 打開匯出的題組 zip（docs/SPEC.md 6.2），給獨立播放器用（O-02）：讀出 set.json，
// 把 zip 內的媒體路徑換成瀏覽器可以直接載入的網址。

const MEDIA_TYPES: Record<string, string> = {
    webp: 'image/webp',
    png: 'image/png',
    jpg: 'image/jpeg',
    jpeg: 'image/jpeg',
    gif: 'image/gif',
    m4a: 'audio/mp4',
    mp3: 'audio/mpeg',
    ogg: 'audio/ogg',
    wav: 'audio/wav',
};

export interface SetZip {
    set: KancilSet; // 媒體已改成可以直接載入的網址
    license: string | null; // LICENSE.txt 的內容
    urls: string[]; // 建立的物件網址，換題組時要釋放
}

export type UrlFactory = (bytes: Uint8Array, type: string) => string;

const objectUrl: UrlFactory = (bytes, type) =>
    URL.createObjectURL(new Blob([bytes as BlobPart], { type }));

function isKancilSet(value: unknown): value is KancilSet {
    if (typeof value !== 'object' || value === null) {
        return false;
    }
    const set = value as Record<string, unknown>;
    return (
        set.format === 'kancil-set' &&
        set.version === 1 &&
        (set.kind === 'vocab' || set.kind === 'quiz') &&
        typeof set.title === 'string' &&
        Array.isArray(set.entries)
    );
}

// 題組中所有的媒體物件（有 src 字串的物件，6.5）
function mediaObjects(value: unknown, found: { src: string }[] = []) {
    if (Array.isArray(value)) {
        for (const child of value) {
            mediaObjects(child, found);
        }
    } else if (typeof value === 'object' && value !== null) {
        const node = value as Record<string, unknown>;
        if (typeof node.src === 'string') {
            found.push(node as { src: string });
        }
        for (const child of Object.values(node)) {
            mediaObjects(child, found);
        }
    }
    return found;
}

export async function openSetZip(
    buffer: ArrayBuffer,
    toUrl: UrlFactory = objectUrl,
): Promise<SetZip> {
    const entries = readZip(buffer);
    const file = entries.get('set.json');
    if (!file) {
        throw new ZipError(
            'zip 檔中沒有 set.json，可能不是從 Kancil Quiz 匯出的題組',
        );
    }

    const decoder = new TextDecoder();
    let set: unknown;
    try {
        set = JSON.parse(decoder.decode(await file.bytes()));
    } catch {
        throw new ZipError('set.json 的格式不正確');
    }
    if (!isKancilSet(set)) {
        throw new ZipError('不是 Kancil Quiz 的題組格式（kancil-set 第 1 版）');
    }

    const urls = new Map<string, string>();
    for (const media of mediaObjects(set.entries)) {
        let url = urls.get(media.src);
        if (url === undefined) {
            const entry = entries.get(media.src);
            if (!entry) {
                throw new ZipError(`zip 檔中缺少媒體檔 ${media.src}`);
            }
            const extension = media.src.split('.').pop()?.toLowerCase() ?? '';
            url = toUrl(
                await entry.bytes(),
                MEDIA_TYPES[extension] ?? 'application/octet-stream',
            );
            urls.set(media.src, url);
        }
        media.src = url;
    }

    const license = entries.get('LICENSE.txt');
    return {
        set,
        license: license ? decoder.decode(await license.bytes()) : null,
        urls: [...urls.values()],
    };
}
