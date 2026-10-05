// 教材試玩的人氣統計（docs/SPEC.md S-06、A-04）：按下「開始」與玩完時各送一次，只帶遊戲與事件，
// 伺服器只累計每課、每個遊戲、每天的次數。
// 同一個分頁、同一課的同一個遊戲只算第一次（記在 sessionStorage）：重新整理、結果頁或遊戲中的
// 「再玩一次」都不重複算，玩完的次數也就不會多於開始的次數。

export type PlayEvent = 'start' | 'finish';

type Fetch = typeof fetch;

// 無痕模式或關掉網站資料時，讀取 sessionStorage 本身就可能丟出例外
function sessionStore(): Storage | null {
    try {
        return window.sessionStorage;
    } catch {
        return null;
    }
}

export class PlayCounter {
    constructor(
        private readonly url: string,
        private readonly game: string,
        private readonly storage: Storage | null = sessionStore(),
        private readonly fetcher: Fetch = (...args) => fetch(...args),
    ) {}

    count(event: PlayEvent): void {
        const key = `kq-play:${this.url}:${this.game}:${event}`;
        try {
            if (this.storage?.getItem(key)) {
                return;
            }
            this.storage?.setItem(key, '1');
        } catch {
            // 記不住就照算，最多是重新整理時多算一次
        }
        // 不等回應，也不理會失敗：統計不能擋住遊戲
        void this.fetcher(this.url, {
            method: 'POST',
            keepalive: true,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ game: this.game, event }),
        }).catch(() => undefined);
    }
}
