import { buildRounds, check, createRng, judge } from '@kancil-quiz/deck';
import type { Round } from '@kancil-quiz/games-sdk';
import type { KancilSet } from '@kancil-quiz/schema';
import { describe, expect, it } from 'vite-plus/test';
import { meta } from '../src/meta';
import { MatchSession, pageSizes, pairRounds } from '../src/session';

const fixtures = import.meta.glob<KancilSet>(
    '../../../schema/fixtures/sets/*/set.json',
    { eager: true, import: 'default' },
);

function pair(entryId: string): Extract<Round, { shape: 'pair' }> {
    return {
        shape: 'pair',
        entryId,
        left: { text: `左 ${entryId}` },
        right: { text: `右 ${entryId}` },
    };
}

const rng = () => createRng(7);

describe('pageSizes()', () => {
    it.each([
        [6, 6, [6]],
        [7, 6, [4, 3]],
        [8, 6, [4, 4]],
        [13, 6, [5, 4, 4]],
        [23, 6, [6, 6, 6, 5]],
        [2, 3, [2]],
        [0, 6, []],
    ])('%i 題、每頁最多 %i 組', (count, perPage, expected) => {
        expect(pageSizes(count, perPage)).toEqual(expected);
    });
});

describe('MatchSession', () => {
    const rounds = ['a', 'b', 'c', 'd', 'e'].map(pair);

    it('分頁時每頁的卡片就是同一頁的題目，順序另外打亂', () => {
        const session = new MatchSession(rounds, 3, rng());
        expect(session.pages.map((page) => page.slots.length)).toEqual([3, 2]);
        for (const page of session.pages) {
            expect(page.cards.map((c) => c.entryId).sort()).toEqual(
                page.slots.map((s) => s.entryId).sort(),
            );
        }
    });

    it('放錯可以再放，每題以第一次放的卡片計分', () => {
        const session = new MatchSession(rounds.slice(0, 3), 6, rng());

        expect(session.place('b', 'a')).toEqual({
            entryId: 'a',
            selected: 'b',
            correct: false,
            firstTry: true,
            presented: session.page?.cards.map((c) => c.entryId),
            pageComplete: false,
        });
        expect(session.place('a', 'a')).toMatchObject({
            correct: true,
            firstTry: false,
        });
        expect(session.place('b', 'b')).toMatchObject({
            correct: true,
            firstTry: true,
        });
        expect(session.place('c', 'c')).toMatchObject({
            correct: true,
            pageComplete: true,
        });
        expect(session.score).toBe(2);

        session.nextPage();
        expect(session.finished).toBe(true);
    });

    it('配好的題目與卡片不能再放；不在這一頁的不算作答', () => {
        const session = new MatchSession(rounds, 3, rng());
        const [first, second] = session.page?.slots ?? [];
        const later = session.pages[1].slots[0].entryId;

        session.place(first.entryId, first.entryId);
        expect(session.place(second.entryId, first.entryId)).toBeNull();
        expect(session.place(first.entryId, second.entryId)).toBeNull();
        expect(session.place(later, second.entryId)).toBeNull();
        expect(session.place(second.entryId, later)).toBeNull();
        expect(session.place('zzz', second.entryId)).toBeNull();
    });

    it('這一頁還沒配完不能換頁', () => {
        const session = new MatchSession(rounds, 3, rng());
        session.nextPage();
        expect(session.pageIndex).toBe(0);
    });
});

describe('與 @kancil-quiz/deck 的判定一致（docs/SPEC.md 7.6）', () => {
    const compatible = Object.entries(fixtures).filter(
        ([, set]) => check(set, meta.requires).ok,
    );

    it('至少有詞彙組與問答組的 fixture 可以玩配對', () => {
        const kinds = new Set(compatible.map(([, set]) => set.kind));
        expect(kinds).toEqual(new Set(['vocab', 'quiz']));
    });

    it.each(compatible)(
        '%s：每次放卡片的判定都與 judge() 相同',
        (_path, set) => {
            const rounds = pairRounds(
                buildRounds(set, meta.requires, { rng: createRng(3) }),
            );
            const session = new MatchSession(rounds, 3, createRng(5));
            let answered = 0;

            while (!session.finished) {
                const page = session.page;
                if (!page) {
                    break;
                }
                // 每一題先放錯一次（放下一題的卡片），再放對
                for (const [i, slot] of page.slots.entries()) {
                    const tries = [
                        page.slots[(i + 1) % page.slots.length].entryId,
                        slot.entryId,
                    ];
                    for (const cardId of tries) {
                        const result = session.place(cardId, slot.entryId);
                        if (!result) {
                            continue;
                        }
                        answered++;
                        expect(
                            judge(set, 'pair', {
                                entryId: result.entryId,
                                presented: result.presented,
                                selected: [result.selected],
                            }),
                        ).toBe(result.correct);
                    }
                }
                session.nextPage();
            }

            expect(session.finished).toBe(true);
            expect(answered).toBeGreaterThan(set.entries.length);
        },
    );
});
