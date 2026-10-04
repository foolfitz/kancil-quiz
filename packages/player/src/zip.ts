// 讀取匯出的 zip（docs/SPEC.md 6.2），給獨立播放器用（O-02）。
// 只支援匯出檔會用到的部分：不壓縮（stored）與 deflate；不支援 zip64、加密與分割壓縮檔。
// 解壓縮用瀏覽器內建的 DecompressionStream，不另外引入套件。

const EOCD = 0x06054b50;
const CENTRAL = 0x02014b50;
const LOCAL = 0x04034b50;

export class ZipError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'ZipError';
    }
}

export interface ZipEntry {
    name: string;
    size: number;
    bytes(): Promise<Uint8Array>;
}

async function inflate(data: Uint8Array): Promise<Uint8Array> {
    const stream = new Blob([data as BlobPart])
        .stream()
        .pipeThrough(new DecompressionStream('deflate-raw'));
    return new Uint8Array(await new Response(stream).arrayBuffer());
}

function findEndOfCentralDirectory(view: DataView): number {
    // 結尾記錄至少 22 bytes，後面最多再接 65535 bytes 的註解
    const last = view.byteLength - 22;
    for (let offset = last; offset >= Math.max(0, last - 0xffff); offset--) {
        if (view.getUint32(offset, true) === EOCD) {
            return offset;
        }
    }
    throw new ZipError('不是 zip 檔');
}

export function readZip(buffer: ArrayBuffer): Map<string, ZipEntry> {
    const view = new DataView(buffer);
    const bytes = new Uint8Array(buffer);
    const decoder = new TextDecoder();
    const end = findEndOfCentralDirectory(view);
    const count = view.getUint16(end + 10, true);
    let offset = view.getUint32(end + 16, true);

    const entries = new Map<string, ZipEntry>();
    for (let i = 0; i < count; i++) {
        if (
            offset + 46 > view.byteLength ||
            view.getUint32(offset, true) !== CENTRAL
        ) {
            throw new ZipError('zip 檔的目錄損壞');
        }
        const flags = view.getUint16(offset + 8, true);
        const method = view.getUint16(offset + 10, true);
        const compressedSize = view.getUint32(offset + 20, true);
        const size = view.getUint32(offset + 24, true);
        const nameLength = view.getUint16(offset + 28, true);
        const extraLength = view.getUint16(offset + 30, true);
        const commentLength = view.getUint16(offset + 32, true);
        const localOffset = view.getUint32(offset + 42, true);
        const name = decoder.decode(
            bytes.subarray(offset + 46, offset + 46 + nameLength),
        );
        offset += 46 + nameLength + extraLength + commentLength;

        if (flags & 0x1) {
            throw new ZipError('不支援加密的 zip 檔');
        }
        if (method !== 0 && method !== 8) {
            throw new ZipError(`不支援的壓縮方式：${method}`);
        }
        if (name.endsWith('/')) {
            continue; // 資料夾
        }

        entries.set(name, {
            name,
            size,
            async bytes() {
                if (view.getUint32(localOffset, true) !== LOCAL) {
                    throw new ZipError(`zip 檔中的 ${name} 損壞`);
                }
                // 本地標頭的檔名與額外欄位長度可能與目錄中的不同，要以本地標頭為準
                const start =
                    localOffset +
                    30 +
                    view.getUint16(localOffset + 26, true) +
                    view.getUint16(localOffset + 28, true);
                const data = bytes.subarray(start, start + compressedSize);
                return method === 8 ? inflate(data) : data.slice();
            },
        });
    }

    return entries;
}
