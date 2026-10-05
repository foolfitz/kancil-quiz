import { describe, expect, it } from 'vite-plus/test';
import { PlayCounter } from '../src/plays';

// 只實作 PlayCounter 用到的部分
class MemoryStorage {
    readonly items = new Map<string, string>();

    getItem(key: string): string | null {
        return this.items.get(key) ?? null;
    }

    setItem(key: string, value: string): void {
        this.items.set(key, value);
    }
}

function fakeFetch() {
    const calls: { url: string; init: RequestInit; body: unknown }[] = [];
    // PlayCounter 一律以字串網址與 JSON 字串呼叫 fetch
    const fetcher = async (
        url: string,
        init: RequestInit & { body: string },
    ) => {
        calls.push({ url, init, body: JSON.parse(init.body) });
        return new Response(null, { status: 204 });
    };
    return { calls, fetcher: fetcher as unknown as typeof fetch };
}

const url = '/api/v1/curriculum/id/1/3/plays';

describe('PlayCounter', () => {
    it('同一個分頁，同一課的同一個遊戲只算第一次開始與第一次玩完', () => {
        const storage = new MemoryStorage() as unknown as Storage;
        const { calls, fetcher } = fakeFetch();
        const quiz = new PlayCounter(url, 'quiz', storage, fetcher);

        quiz.count('start');
        quiz.count('finish');
        // 再玩一次、重新整理
        quiz.count('start');
        quiz.count('finish');
        new PlayCounter(url, 'quiz', storage, fetcher).count('start');
        // 換一個遊戲照算
        new PlayCounter(url, 'flash-cards', storage, fetcher).count('start');

        expect(calls.map((call) => call.body)).toEqual([
            { game: 'quiz', event: 'start' },
            { game: 'quiz', event: 'finish' },
            { game: 'flash-cards', event: 'start' },
        ]);
        expect(calls[0]).toMatchObject({
            url,
            init: { method: 'POST', keepalive: true },
        });
    });

    it('記不住的時候照算，送不出去也不丟出錯誤', async () => {
        const broken = {
            getItem: () => {
                throw new Error('SecurityError');
            },
            setItem: () => undefined,
        } as unknown as Storage;
        let sent = 0;
        const failing = (async () => {
            sent++;
            throw new TypeError('Failed to fetch');
        }) as unknown as typeof fetch;

        const counter = new PlayCounter(url, 'quiz', broken, failing);
        counter.count('start');
        counter.count('start');
        new PlayCounter(url, 'quiz', null, failing).count('start');
        await Promise.resolve();

        expect(sent).toBe(3);
    });
});
