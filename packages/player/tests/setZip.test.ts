/// <reference types="node" />
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vite-plus/test';
import { openSetZip } from '../src/setZip';
import { ZipError, readZip } from '../src/zip';

// fixtures/vi-vocab-fruits.zip 由 PHP 的 ZipArchive 產生（與伺服器匯出用同一個函式庫）：
// vi-vocab-fruits 加上一張不壓縮的圖片與一個 deflate 壓縮的音檔，兩題共用同一張圖片。
function fixture(): ArrayBuffer {
    const file = readFileSync(
        new URL('./fixtures/vi-vocab-fruits.zip', import.meta.url),
    );
    return new Uint8Array(file).buffer;
}

const IMAGE = 'media/01M3ZYR27GF5QQW62SVZ5NA266.webp';
const AUDIO = 'media/01M3ZYR188M6EWJQJWHW2KP6YE.m4a';

describe('readZip()', () => {
    it('讀出不壓縮與 deflate 壓縮的檔案', async () => {
        const entries = readZip(fixture());
        expect([...entries.keys()].sort()).toEqual(
            [AUDIO, IMAGE, 'LICENSE.txt', 'set.json'].sort(),
        );

        const text = async (name: string) =>
            new TextDecoder().decode(await entries.get(name)?.bytes());
        expect(await text(IMAGE)).toBe('RIFF-webp-bytes');
        expect(await text(AUDIO)).toBe('m4a-bytes '.repeat(50));
        expect(JSON.parse(await text('set.json')).title).toBe('水果（越南語）');
    });

    it('不是 zip 檔時說明原因', () => {
        expect(() =>
            readZip(new TextEncoder().encode('hello').buffer as ArrayBuffer),
        ).toThrow(ZipError);
    });
});

describe('openSetZip()', () => {
    it('媒體路徑換成可以載入的網址，同一個檔案只建立一次', async () => {
        const created: { type: string; size: number }[] = [];
        const zip = await openSetZip(fixture(), (bytes, type) => {
            created.push({ type, size: bytes.length });
            return `blob:test/${created.length}`;
        });

        expect(created).toEqual([
            { type: 'image/webp', size: 15 },
            { type: 'audio/mp4', size: 500 },
        ]);
        expect(zip.urls).toEqual(['blob:test/1', 'blob:test/2']);
        if (zip.set.kind !== 'vocab') {
            throw new Error('應該是詞彙組');
        }
        const [first, second] = zip.set.entries;
        expect(first.item.image?.src).toBe('blob:test/1');
        // 署名資料不變
        expect(first.item.image?.source).toBe('自行拍攝');
        expect(first.item.audio?.[0].src).toBe('blob:test/2');
        expect(second.item.image?.src).toBe('blob:test/1');
        expect(zip.license).toContain('授權：CC BY 4.0');
    });

    it('不是題組的 zip 不能開啟', async () => {
        await expect(
            openSetZip(new ArrayBuffer(0) as ArrayBuffer),
        ).rejects.toThrow(ZipError);
    });
});
