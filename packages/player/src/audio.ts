// 遊戲用的音訊。iOS 只允許在使用者手勢中開始播放，所以「開始」按鈕按下時先用同一個
// <audio> 元素播一段無聲音訊解鎖，之後一律重複使用這個元素（docs/SPEC.md 7.2、第 9 節）。

const SILENCE =
    'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=';

export class AudioHost {
    private readonly element = new Audio();
    private readonly preloaded = new Map<string, HTMLAudioElement>();
    muted = false;

    unlock(): void {
        this.element.src = SILENCE;
        void this.element.play().catch(() => undefined);
    }

    play(url: string): Promise<void> {
        if (this.muted) {
            return Promise.resolve();
        }
        this.element.pause();
        this.element.src = url;
        return this.element.play();
    }

    stopAll(): void {
        this.element.pause();
    }

    // 預先載入音檔，換題時不必等待下載。
    preload(urls: Iterable<string | undefined>): void {
        for (const url of urls) {
            if (url && !this.preloaded.has(url)) {
                const audio = new Audio();
                audio.preload = 'auto';
                audio.src = url;
                this.preloaded.set(url, audio);
            }
        }
    }
}
