import type { GameRequirements, Round } from '@kancil-quiz/games-sdk';
import type { KancilSet, QuizSet, VocabSet } from '@kancil-quiz/schema';
import { describe, expect, it } from 'vite-plus/test';
import grading from '../../schema/fixtures/grading/cases.json';
import {
    buildRounds,
    check,
    countCorrect,
    createRng,
    duplicateFaces,
    groupGames,
    IncompatibleSetError,
    judge,
} from '../src';
import type { Response } from '../src';

const fixtures = import.meta.glob<KancilSet>(
    '../../schema/fixtures/sets/*/set.json',
    { eager: true, import: 'default' },
);

function fixture(path: string): KancilSet {
    const set = fixtures[`../../schema/fixtures/${path}`];
    if (!set) {
        throw new Error(`找不到 fixture：${path}`);
    }
    return structuredClone(set);
}

const vocab = () => fixture('sets/vi-vocab-fruits/set.json') as VocabSet;
const quiz = () => fixture('sets/vi-quiz-greetings/set.json') as QuizSet;

// 與迷宮問答相同的需求
const mcq: GameRequirements = {
    shape: 'mcq',
    minRounds: 1,
    optionCount: { min: 2, max: 6 },
    renders: { prompt: ['text', 'image', 'audio'], option: ['text', 'image'] },
    scored: true,
};
const pair: GameRequirements = {
    shape: 'pair',
    minRounds: 2,
    renders: { left: ['text', 'image'], right: ['text', 'image'] },
    scored: true,
};
const card: GameRequirements = {
    shape: 'card',
    minRounds: 1,
    renders: { front: ['text', 'image', 'audio'], back: ['text', 'image'] },
    scored: false,
};

function codes(set: KancilSet, requires: GameRequirements): string[] {
    return check(set, requires).issues.map((issue) => issue.code);
}

describe('judge() 與 countCorrect()：與伺服器共用的判定案例', () => {
    it.each(grading.judge.map((c) => [c.name, c] as const))(
        '%s',
        (_name, c) => {
            expect(
                judge(
                    fixture(c.set),
                    c.shape as Round['shape'],
                    c.response as Response,
                ),
            ).toBe(c.expected);
        },
    );

    it.each(grading.countCorrect.map((c) => [c.name, c] as const))(
        '%s',
        (_name, c) => {
            expect(
                countCorrect(
                    fixture(c.set),
                    c.shape as Round['shape'],
                    c.responses as Response[],
                ),
            ).toBe(c.expected);
        },
    );
});

describe('check()', () => {
    it.each(Object.keys(fixtures))('%s 可以套用三種形狀', (path) => {
        const set = fixtures[path];
        for (const requires of [mcq, pair, card]) {
            expect(check(set, requires).issues).toEqual([]);
        }
    });

    it('題數不足', () => {
        expect(codes(quiz(), { ...mcq, minRounds: 10 })).toEqual([
            'too-few-rounds',
        ]);
    });

    it('同一面不能同時用目標語文字與中文意思', () => {
        const set = vocab();
        set.faces.prompt = ['text', 'translation_zh'];
        expect(codes(set, mcq)).toContain('faces-conflict');
    });

    it('題目面需要圖片，但詞條沒有圖片', () => {
        const set = vocab();
        set.faces.prompt = ['image'];
        const report = check(set, mcq);
        expect(report.ok).toBe(false);
        expect(report.issues[0]).toMatchObject({
            code: 'empty-face',
            entryId: set.entries[0].id,
            message: '第 1 題的題目是空的',
        });
    });

    it('答案只有音檔，迷宮的選項無法呈現', () => {
        const set = vocab();
        set.entries[0].item.audio = [{ src: 'media/a.m4a' }];
        set.faces.answer = ['audio'];
        expect(codes(set, mcq)).toContain('unrenderable');
    });

    it('詞彙組互不相同的答案太少，湊不滿選項', () => {
        const set = vocab();
        set.entries = set.entries.slice(0, 1);
        expect(codes(set, mcq)).toEqual(['too-few-answers']);
    });

    it('寫法不同但其實相同的答案不算兩個', () => {
        const set = vocab();
        set.entries = set.entries.slice(0, 2);
        set.entries[1].item.text = `  ${set.entries[0].item.text.toUpperCase()}`;
        expect(codes(set, mcq)).toEqual(['too-few-answers']);
    });

    it('問答組選項太少是錯誤，太多只是提醒', () => {
        expect(
            codes(quiz(), { ...mcq, optionCount: { min: 4, max: 6 } }),
        ).toEqual([
            'too-few-options',
            'too-few-options',
            'too-few-options',
            'too-few-options',
        ]);

        const report = check(quiz(), {
            ...mcq,
            optionCount: { min: 2, max: 3 },
        });
        expect(report.ok).toBe(true);
        expect(report.issues.map((issue) => issue.code)).toEqual([
            'too-many-options',
        ]);
    });

    it('配對的右側重複就沒有唯一解', () => {
        const set = vocab();
        set.entries[1].item.text = set.entries[0].item.text;
        expect(codes(set, pair)).toEqual(['duplicate-answer']);
    });

    it('題目相同的兩題只是提醒，選擇題與配對都列出', () => {
        const set = vocab();
        set.entries[2].item.translation_zh = ' 香蕉';
        for (const requires of [mcq, pair]) {
            const report = check(set, requires);
            expect(report.ok).toBe(true);
            expect(report.issues).toEqual([
                {
                    severity: 'warning',
                    code: 'duplicate-prompt',
                    entryId: set.entries[2].id,
                    message:
                        '第 3 題的題目與第 1 題相同（香蕉），學生分不出哪一個是正解',
                },
            ]);
        }
        expect(codes(set, card)).toEqual([]);
    });

    it('扣掉題目相同的詞條之後，干擾選項還是要湊得滿', () => {
        const set = vocab();
        set.entries = set.entries.slice(0, 3);
        set.entries[1].item.translation_zh = set.entries[0].item.translation_zh;
        // 第 1、2 題只剩第 3 題能當干擾選項，最少 3 個選項就不夠
        const report = check(set, { ...mcq, optionCount: { min: 3, max: 6 } });
        expect(report.ok).toBe(false);
        expect(
            report.issues
                .filter((issue) => issue.code === 'too-few-answers')
                .map((issue) => issue.entryId),
        ).toEqual([set.entries[0].id, set.entries[1].id]);
        expect(check(set, mcq).ok).toBe(true);
    });

    it('問答組同一題的選項相同只是提醒', () => {
        const set = quiz();
        set.entries[0].question.options[2].text =
            set.entries[0].question.options[1].text?.toUpperCase();
        const report = check(set, mcq);
        expect(report.ok).toBe(true);
        expect(report.issues.map((issue) => issue.code)).toEqual([
            'duplicate-option',
        ]);
        expect(codes(set, pair)).toEqual([]);
    });
});

describe('duplicateFaces()', () => {
    it('fixture 沒有重複', () => {
        for (const set of Object.values(fixtures)) {
            expect(duplicateFaces(set)).toEqual([]);
        }
    });

    it('詞彙組依 faces 比對題目與答案，忽略大小寫、空白與 NFC', () => {
        const set = vocab();
        set.entries[3].item.translation_zh = '香蕉';
        set.entries[4].item.text =
            ` ${set.entries[1].item.text.toUpperCase()}`.normalize('NFD');
        expect(duplicateFaces(set)).toEqual([
            {
                slot: 'prompt',
                entryId: set.entries[3].id,
                position: 4,
                duplicateOf: { entryId: set.entries[0].id, position: 1 },
                message:
                    '第 4 題的題目與第 1 題相同（香蕉），學生分不出哪一個是正解',
            },
            {
                slot: 'answer',
                entryId: set.entries[4].id,
                position: 5,
                duplicateOf: { entryId: set.entries[1].id, position: 2 },
                message: `第 5 題的答案與第 2 題相同（${set.entries[4].item.text.trim()}），配對會沒有唯一解`,
            },
        ]);

        // 換成看圖片出題：圖片不同就不算相同；還沒填的那一面不算
        set.faces.prompt = ['image'];
        set.entries[0].item.image = { src: 'media/a.webp' };
        set.entries[3].item.image = { src: 'media/b.webp' };
        expect(duplicateFaces(set).map((d) => d.slot)).toEqual(['answer']);
        set.entries[3].item.image = { src: 'media/a.webp' };
        expect(duplicateFaces(set)[0]).toMatchObject({
            slot: 'prompt',
            message:
                '第 4 題的題目與第 1 題相同（圖片），學生分不出哪一個是正解',
        });
    });

    it('問答組列出同一題相同的選項，題幹相同也提醒', () => {
        const set = quiz();
        set.entries[0].question.options[2].text =
            set.entries[0].question.options[0].text;
        set.entries[1].question.stem.text = set.entries[0].question.stem.text;
        expect(duplicateFaces(set)).toMatchObject([
            {
                slot: 'prompt',
                entryId: set.entries[1].id,
                duplicateOf: { position: 1 },
            },
            {
                slot: 'option',
                entryId: set.entries[0].id,
                optionIds: ['a', 'c'],
                message: `第 1 題的選項 A、C 相同（${set.entries[0].question.options[0].text}）`,
            },
        ]);
    });
});

describe('buildRounds()', () => {
    const rng = () => createRng(42);

    it('同樣的種子得到同樣的結果', () => {
        expect(buildRounds(vocab(), mcq, { rng: rng() })).toEqual(
            buildRounds(vocab(), mcq, { rng: rng() }),
        );
    });

    it.each(Object.keys(fixtures))(
        '%s 轉成選擇題：每題恰有一個正解，選項不重複',
        (path) => {
            const set = fixtures[path];
            const rounds = buildRounds(set, mcq, { rng: rng() });

            expect(rounds.map((round) => round.entryId).sort()).toEqual(
                set.entries.map((entry) => entry.id).sort(),
            );
            for (const round of rounds) {
                if (round.shape !== 'mcq') {
                    throw new Error('應該是選擇題');
                }
                expect(round.options.filter((o) => o.correct)).toHaveLength(1);
                const faces = round.options.map((o) => JSON.stringify(o.face));
                expect(new Set(faces).size).toBe(faces.length);
                if (set.kind === 'vocab') {
                    expect(round.options).toHaveLength(4);
                    expect(round.options.find((o) => o.correct)?.id).toBe(
                        round.entryId,
                    );
                }
            }
        },
    );

    it('題目相同的詞條不互為干擾選項，判定規則也不受影響', () => {
        const set = vocab();
        // 第 1、2 題的中文意思相同：兩個詞對同一個題目都算對
        set.entries[1].item.translation_zh = set.entries[0].item.translation_zh;
        const twins = new Set([set.entries[0].id, set.entries[1].id]);
        for (let seed = 1; seed <= 20; seed++) {
            for (const round of buildRounds(set, mcq, {
                rng: createRng(seed),
            })) {
                if (round.shape !== 'mcq') {
                    throw new Error('應該是選擇題');
                }
                expect(round.options).toHaveLength(4);
                const ids = round.options.map((o) => o.id);
                if (twins.has(round.entryId)) {
                    expect(ids.filter((id) => twins.has(id))).toEqual([
                        round.entryId,
                    ]);
                }
                for (const option of round.options) {
                    expect(
                        judge(set, 'mcq', {
                            entryId: round.entryId,
                            presented: ids,
                            selected: [option.id],
                        }),
                    ).toBe(option.correct);
                }
            }
        }
    });

    it('判定規則能正確判定 buildRounds() 產生的正解', () => {
        for (const set of [vocab(), quiz()]) {
            for (const round of buildRounds(set, mcq, { rng: rng() })) {
                if (round.shape !== 'mcq') {
                    continue;
                }
                const presented = round.options.map((o) => o.id);
                for (const option of round.options) {
                    expect(
                        judge(set, 'mcq', {
                            entryId: round.entryId,
                            presented,
                            selected: [option.id],
                        }),
                    ).toBe(option.correct);
                }
            }
        }
    });

    it('問答組選項多於上限時保留正解', () => {
        const rounds = buildRounds(
            quiz(),
            { ...mcq, optionCount: { min: 2, max: 2 } },
            { rng: rng() },
        );
        for (const round of rounds) {
            if (round.shape === 'mcq') {
                expect(round.options).toHaveLength(2);
                expect(round.options.some((o) => o.correct)).toBe(true);
            }
        }
    });

    it('可以不打亂順序', () => {
        const rounds = buildRounds(quiz(), mcq, {
            rng: rng(),
            shuffleRounds: false,
            shuffleOptions: false,
        });
        expect(rounds.map((round) => round.entryId)).toEqual(
            quiz().entries.map((entry) => entry.id),
        );
        expect(rounds[0]).toMatchObject({
            prompt: { text: '「謝謝」的越南語是？' },
            options: [
                { id: 'a', face: { text: 'Cảm ơn' }, correct: true },
                { id: 'b', face: { text: 'Xin chào' }, correct: false },
                { id: 'c', face: { text: 'Tạm biệt' }, correct: false },
            ],
        });
    });

    it('問答組轉配對與字卡時，答案是正解選項', () => {
        const [first] = buildRounds(quiz(), card, {
            rng: rng(),
            shuffleRounds: false,
        });
        expect(first).toEqual({
            shape: 'card',
            entryId: quiz().entries[0].id,
            front: { text: '「謝謝」的越南語是？' },
            back: { text: 'Cảm ơn' },
        });
    });

    it('不相容時丟出 IncompatibleSetError', () => {
        expect(() =>
            buildRounds(quiz(), { ...mcq, minRounds: 99 }, { rng: rng() }),
        ).toThrow(IncompatibleSetError);
    });

    it.each(Object.keys(fixtures))('%s 的轉換結果（快照）', (path) => {
        const set = fixtures[path];
        expect({
            mcq: buildRounds(set, mcq, { rng: rng() }),
            pair: buildRounds(set, pair, { rng: rng() }),
        }).toMatchSnapshot();
    });
});

describe('groupGames', () => {
    it('分成計分的遊戲與不計分的互動教材，保持原本的順序，沒有遊戲的分類不列出', () => {
        const games = [
            { id: 'flash-cards', requires: { scored: false } },
            { id: 'quiz', requires: { scored: true } },
            { id: 'spin-wheel', requires: { scored: false } },
        ];
        expect(
            groupGames(games).map(({ category, games }) => [
                category.title,
                games.map((game) => game.id),
            ]),
        ).toEqual([
            ['遊戲', ['quiz']],
            ['互動教材', ['flash-cards', 'spin-wheel']],
        ]);
        expect(
            groupGames([games[1]]).map(({ category }) => category.id),
        ).toEqual(['game']);
    });
});
