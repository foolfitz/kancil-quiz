// 瀏覽器錄音（docs/SPEC.md T-06、第 9 節）。
// 各瀏覽器錄出的格式不同（Chrome 是 webm／Opus，Safari 是 mp4／AAC），
// 一律原樣上傳，由伺服器轉成 AAC 並做音量標準化。

// 詞與短句用不到更長；時間到自動停止
export const MAX_SECONDS = 30;

// 依序挑第一個瀏覽器支援的格式
const TYPES = [
    'audio/webm;codecs=opus',
    'audio/mp4',
    'audio/ogg;codecs=opus',
    'audio/webm',
];

export type RecordingSupport = 'ok' | 'insecure' | 'unsupported';

export function recordingSupport(): RecordingSupport {
    if (!window.isSecureContext) {
        return 'insecure';
    }
    if (
        !navigator.mediaDevices?.getUserMedia ||
        typeof MediaRecorder === 'undefined'
    ) {
        return 'unsupported';
    }
    return 'ok';
}

export const SUPPORT_MESSAGES: Record<
    Exclude<RecordingSupport, 'ok'>,
    string
> = {
    insecure:
        '錄音只能在 https 開頭的網址使用。請改用上傳音檔，或請管理員為網站設定 HTTPS。',
    unsupported: '這個瀏覽器不支援錄音，請改用上傳音檔。',
};

// getUserMedia 失敗時給老師看的說明
export function recordingErrorMessage(error: unknown): string {
    const name = error instanceof DOMException ? error.name : '';
    switch (name) {
        case 'NotAllowedError':
        case 'SecurityError':
            return '瀏覽器沒有允許這個網站使用麥克風。請在網址列旁的網站設定中允許麥克風，再試一次。';
        case 'NotFoundError':
        case 'OverconstrainedError':
            return '找不到麥克風，請確認麥克風已經接上。';
        case 'NotReadableError':
            return '麥克風無法使用，可能正被其他程式占用。';
        default:
            return '無法開始錄音，請改用上傳音檔。';
    }
}

function extension(type: string): string {
    if (type.includes('mp4')) {
        return 'm4a';
    }
    return type.includes('ogg') ? 'ogg' : 'webm';
}

// 一段錄音：開始時取得麥克風，結束或取消時一定釋放，平板上的錄音指示才會熄滅。
export class Recording {
    private chunks: Blob[] = [];
    private readonly startedAt = performance.now();
    private readonly timer: ReturnType<typeof setTimeout>;
    private readonly samples: Float32Array<ArrayBuffer> | null;
    private stopped: Promise<File> | null = null;

    private constructor(
        private readonly stream: MediaStream,
        private readonly recorder: MediaRecorder,
        private readonly context: AudioContext | null,
        private readonly analyser: AnalyserNode | null,
        onTimeout: () => void,
    ) {
        recorder.addEventListener('dataavailable', (event) => {
            if (event.data.size > 0) {
                this.chunks.push(event.data);
            }
        });
        this.samples = analyser ? new Float32Array(analyser.fftSize) : null;
        this.timer = setTimeout(onTimeout, MAX_SECONDS * 1000);
    }

    // 要在使用者手勢（按下按鈕）中呼叫：iPad 的 AudioContext 需要它
    static async start(onTimeout: () => void): Promise<Recording> {
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: true,
        });
        try {
            const type = TYPES.find((t) => MediaRecorder.isTypeSupported(t));
            const recorder = new MediaRecorder(
                stream,
                type ? { mimeType: type } : undefined,
            );

            // 音量條只是輔助，建立失敗也照樣錄音
            let context: AudioContext | null = null;
            let analyser: AnalyserNode | null = null;
            try {
                context = new AudioContext();
                analyser = context.createAnalyser();
                analyser.fftSize = 1024;
                context.createMediaStreamSource(stream).connect(analyser);
            } catch {
                context = null;
                analyser = null;
            }

            const recording = new Recording(
                stream,
                recorder,
                context,
                analyser,
                onTimeout,
            );
            recorder.start();
            return recording;
        } catch (error) {
            stream.getTracks().forEach((track) => track.stop());
            throw error;
        }
    }

    get elapsedMs(): number {
        return performance.now() - this.startedAt;
    }

    // 目前的音量，0 到 1
    level(): number {
        if (!this.analyser || !this.samples) {
            return 0;
        }
        this.analyser.getFloatTimeDomainData(this.samples);
        let sum = 0;
        for (const sample of this.samples) {
            sum += sample * sample;
        }
        // 說話的 RMS 大約 0.05 到 0.3，放大一點比較好看出有沒有收到聲音
        return Math.min(1, Math.sqrt(sum / this.samples.length) * 4);
    }

    // 停止並取得錄好的檔案。可以重複呼叫，拿到同一個結果。
    stop(): Promise<File> {
        this.stopped ??= new Promise<File>((resolve, reject) => {
            this.recorder.addEventListener(
                'stop',
                () => {
                    this.release();
                    const type = this.recorder.mimeType || 'audio/webm';
                    if (this.chunks.length === 0) {
                        reject(new Error('沒有錄到聲音，請再試一次。'));
                        return;
                    }
                    resolve(
                        new File(this.chunks, `recording.${extension(type)}`, {
                            type,
                        }),
                    );
                },
                { once: true },
            );
            if (this.recorder.state === 'inactive') {
                this.recorder.dispatchEvent(new Event('stop'));
            } else {
                this.recorder.stop();
            }
        });
        return this.stopped;
    }

    // 不要這段錄音（例如關閉對話框）
    cancel(): void {
        if (this.recorder.state !== 'inactive') {
            this.recorder.stop();
        }
        this.release();
    }

    private release(): void {
        clearTimeout(this.timer);
        this.stream.getTracks().forEach((track) => track.stop());
        void this.context?.close().catch(() => undefined);
    }
}
