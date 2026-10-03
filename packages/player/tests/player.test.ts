import { buildRounds, createRng } from '@kancil-quiz/deck';
import type { GameRequirements } from '@kancil-quiz/games-sdk';
import type { KancilSet } from '@kancil-quiz/schema';
import { describe, expect, it } from 'vite-plus/test';
import vocab from '../../schema/fixtures/sets/vi-vocab-fruits/set.json';
import { AttemptSession } from '../src/api';
import type { ResponseRecord } from '../src/api';
import {
    computeResults,
    presentedOptions,
    pronunciation,
} from '../src/results';

const set = vocab as KancilSet;
const mcq: GameRequirements = {
    shape: 'mcq',
    minRounds: 1,
    optionCount: { min: 2, max: 6 },
    renders: { prompt: ['text'], option: ['text'] },
    scored: true,
};
const rounds = buildRounds(set, mcq, { rng: createRng(7) });

function answer(index: number, correct: boolean): ResponseRecord {
    const round = rounds[index];
    if (round.shape !== 'mcq') {
        throw new Error('應該是選擇題');
    }
    const option = round.options.find((o) => o.correct === correct);
    return {
        entry_id: round.entryId,
        presented: presentedOptions(round, rounds),
        selected: [option?.id ?? ''],
        client_correct: correct,
        duration_ms: 1000,
    };
}

describe('computeResults()', () => {
    it('在本機判定時，每題以第一筆作答計算，沒作答的算錯', () => {
        const results = computeResults(set, rounds, [
            answer(0, false),
            answer(0, true),
            answer(1, true),
        ]);

        expect(results.correctCount).toBe(1);
        expect(results.rounds.map((r) => [r.correct, r.answered])).toEqual([
            [false, true],
            [true, true],
            ...rounds.slice(2).map(() => [false, false]),
        ]);
    });

    it('有伺服器判定時以伺服器為準', () => {
        const results = computeResults(
            set,
            rounds,
            [answer(0, false)],
            [{ entry_id: rounds[0].entryId, correct: true }],
        );
        expect(results.rounds[0].correct).toBe(true);
    });

    it('字卡不計分', () => {
        const cards = buildRounds(
            set,
            { ...mcq, shape: 'card', renders: {} },
            { rng: createRng(1) },
        );
        const results = computeResults(set, cards, [
            {
                entry_id: cards[0].entryId,
                presented: [],
                selected: null,
                client_correct: null,
                duration_ms: null,
            },
        ]);
        expect(results.correctCount).toBeNull();
        expect(results.rounds.filter((r) => r.answered)).toHaveLength(1);
    });

    it('詞彙組的發音取詞條的第一個音檔', () => {
        const withAudio = structuredClone(set);
        if (withAudio.kind !== 'vocab') {
            throw new Error('應該是詞彙組');
        }
        withAudio.entries[0].item.audio = [
            { src: 'https://example.test/a.m4a' },
        ];
        expect(pronunciation(withAudio, withAudio.entries[0].id)).toBe(
            'https://example.test/a.m4a',
        );
        expect(
            pronunciation(withAudio, withAudio.entries[1].id),
        ).toBeUndefined();
    });
});

describe('AttemptSession', () => {
    function fakeFetch(failTimes = 0) {
        const calls: { url: string; body: unknown }[] = [];
        let failures = failTimes;
        // PlayerApi 一律以字串網址與 JSON 字串呼叫 fetch
        const fetcher = async (url: string, init?: { body?: string }) => {
            calls.push({ url, body: JSON.parse(init?.body ?? '{}') });
            if (failures > 0) {
                failures--;
                return new Response(JSON.stringify({ message: 'down' }), {
                    status: 503,
                });
            }
            return new Response(
                JSON.stringify({
                    correct_count: 1,
                    round_count: 2,
                    results: [],
                }),
                { status: 200 },
            );
        };
        return { calls, fetcher: fetcher as unknown as typeof fetch };
    }

    it('累積到一批才送出', async () => {
        const { calls, fetcher } = fakeFetch();
        const session = new AttemptSession('/api/v1', 'A1', 'T', fetcher, 2);

        session.record(answer(0, true));
        expect(calls).toHaveLength(0);
        session.record(answer(1, true));
        await session.flush();

        expect(calls).toHaveLength(1);
        expect(calls[0]).toMatchObject({
            url: '/api/v1/attempts/A1/responses',
            body: { token: 'T', responses: [{}, {}] },
        });
    });

    it('送不出去時保留在佇列，下次再送', async () => {
        const { calls, fetcher } = fakeFetch(1);
        const session = new AttemptSession('/api/v1', 'A1', 'T', fetcher, 10);

        session.record(answer(0, true));
        await expect(session.flush()).rejects.toThrow();
        expect(session.pending).toBe(1);

        await session.flush();
        expect(session.pending).toBe(0);
        expect(calls).toHaveLength(2);
    });

    it('結束時先送出剩下的作答再結算', async () => {
        const { calls, fetcher } = fakeFetch();
        const session = new AttemptSession('/api/v1', 'A1', 'T', fetcher, 10);

        session.record(answer(0, true));
        const result = await session.complete({ duration_ms: 5000 });

        expect(calls.map((c) => c.url)).toEqual([
            '/api/v1/attempts/A1/responses',
            '/api/v1/attempts/A1/complete',
        ]);
        expect(calls[1].body).toEqual({ token: 'T', duration_ms: 5000 });
        expect(result.correct_count).toBe(1);
    });
});
