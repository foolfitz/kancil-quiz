// E2E 上傳用的檔案：不用真的麥克風也能測音檔。

// 一段單聲道 16 位元的 WAV 正弦波，伺服器會轉成 m4a（docs/SPEC.md 第 9 節）。
export function wavFile(
    name = 'word.wav',
    seconds = 0.4,
    rate = 8000,
): { name: string; mimeType: string; buffer: Buffer } {
    const samples = Math.floor(seconds * rate);
    const data = Buffer.alloc(samples * 2);
    for (let i = 0; i < samples; i++) {
        data.writeInt16LE(
            Math.round(Math.sin((2 * Math.PI * 440 * i) / rate) * 12000),
            i * 2,
        );
    }
    const header = Buffer.alloc(44);
    header.write('RIFF', 0);
    header.writeUInt32LE(36 + data.length, 4);
    header.write('WAVE', 8);
    header.write('fmt ', 12);
    header.writeUInt32LE(16, 16);
    header.writeUInt16LE(1, 20); // PCM
    header.writeUInt16LE(1, 22); // 單聲道
    header.writeUInt32LE(rate, 24);
    header.writeUInt32LE(rate * 2, 28);
    header.writeUInt16LE(2, 32);
    header.writeUInt16LE(16, 34);
    header.write('data', 36);
    header.writeUInt32LE(data.length, 40);
    return {
        name,
        mimeType: 'audio/wav',
        buffer: Buffer.concat([header, data]),
    };
}
