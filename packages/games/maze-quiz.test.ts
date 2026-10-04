import { buildRounds, check, createRng, judge } from '@kancil-quiz/deck';
import type { GameEvent, Round } from '@kancil-quiz/games-sdk';
import type { KancilSet } from '@kancil-quiz/schema';
import { describe, expect, it } from 'vite-plus/test';
import sdk from '../games-sdk/src/index.ts?raw';
import text from '../text/src/index.ts?raw';
import { DEFAULT_GAME_CONFIG, levelMaze } from './maze-quiz/src/core/game';
import { opposite } from './maze-quiz/src/core/grid';
import { meta } from './maze-quiz/src/meta';
import { roundsToLevels } from './maze-quiz/src/rounds';
import { createSession, type Session } from './maze-quiz/src/session';
import sdkCopy from './maze-quiz/vendor/games-sdk.ts?raw';
import textCopy from './maze-quiz/vendor/text.ts?raw';

// 迷宮問答是獨立的 repo（git submodule），這裡放需要平台其他套件的測試。

describe('maze-quiz 的 vendor/ 副本', () => {
    // 迷宮單獨執行時用這兩份副本（見 maze-quiz/vendor/README.md）
    const hint =
        '改了平台的套件，要把新版複製到 maze-quiz 的 vendor/，在 maze-quiz repo 中 commit，再更新 submodule';

    it('@kancil-quiz/games-sdk 與正本相同', () => {
        expect(sdkCopy, hint).toBe(sdk);
    });

    it('@kancil-quiz/text 與正本相同', () => {
        expect(textCopy, hint).toBe(text);
    });
});

describe('迷宮問答與 @kancil-quiz/deck 的判定一致（docs/SPEC.md 7.6）', () => {
    const fixtures = import.meta.glob<KancilSet>(
        '../schema/fixtures/sets/*/set.json',
        { eager: true, import: 'default' },
    );
    const compatible = Object.entries(fixtures).filter(
        ([, set]) => check(set, meta.requires).ok,
    );
    const STEP_MS = 1000 / 60;
    // 照劇本走的時候不放敵人，免得途中被撞到
    const config = {
        ...DEFAULT_GAME_CONFIG,
        difficulties: {
            1: { ...DEFAULT_GAME_CONFIG.difficulties[1], enemyCount: 0 },
            2: { ...DEFAULT_GAME_CONFIG.difficulties[2], enemyCount: 0 },
            3: { ...DEFAULT_GAME_CONFIG.difficulties[3], enemyCount: 0 },
        },
    };

    /** 前進到可以操作（playing）或遊戲結束 */
    function untilPlaying(session: Session): void {
        for (let i = 0; i < 1000; i++) {
            if (session.completed || session.state.phase.kind === 'playing')
                return;
            session.step(STEP_MS);
        }
        throw new Error(`停在 ${session.state.phase.kind}`);
    }

    /** 把玩家放到選項的園區門外，往門走進去 */
    function walkInto(session: Session, optionId: string): void {
        const { level } = session.state;
        if (level === null) throw new Error('目前沒有關卡');
        const zone = levelMaze(level).zones.find(
            (z) => level.question.choices[z.choiceIndex]?.id === optionId,
        );
        if (zone === undefined) throw new Error(`找不到選項 ${optionId}`);
        Object.assign(level.player, {
            x: zone.outside.x,
            y: zone.outside.y,
            dir: null,
        });
        session.steer(opposite(zone.doorSide));
        for (let i = 0; i < 120 && session.state.phase.kind === 'playing'; i++)
            session.step(STEP_MS);
    }

    it('至少有詞彙組與問答組的 fixture 可以玩迷宮', () => {
        const kinds = new Set(compatible.map(([, set]) => set.kind));
        expect(kinds).toEqual(new Set(['vocab', 'quiz']));
    });

    it.each(compatible)(
        '%s：每次走進答案區的判定都與 judge() 相同',
        (_path, set) => {
            const rounds = buildRounds(set, meta.requires, {
                rng: createRng(3),
            });
            const levels = roundsToLevels(rounds);
            expect(levels).toHaveLength(rounds.length);

            const events: GameEvent[] = [];
            const session = createSession(
                levels,
                meta.defaultOptions,
                1234,
                (event) => events.push(event),
                config,
            );
            session.start();
            // 每一題先走進所有答錯的園區，最後走進正確的
            for (const level of levels) {
                const wrong = level.options.filter((option) => !option.correct);
                const right = level.options.filter((option) => option.correct);
                for (const option of [...wrong, ...right.slice(0, 1)]) {
                    untilPlaying(session);
                    walkInto(session, option.id);
                }
            }
            untilPlaying(session);
            expect(session.completed).toBe(true);

            const byEntry = new Map<string, Round>(
                rounds.map((round) => [round.entryId, round]),
            );
            const answered = events.filter(
                (event) => event.type === 'answered',
            );
            expect(answered).toHaveLength(
                levels.reduce(
                    (sum, level) =>
                        sum +
                        level.options.filter((option) => !option.correct)
                            .length +
                        1,
                    0,
                ),
            );
            expect(answered.filter((event) => event.correct)).toHaveLength(
                levels.length,
            );
            for (const event of answered) {
                const round = byEntry.get(event.entryId);
                if (round?.shape !== 'mcq')
                    throw new Error(`沒有這一題：${event.entryId}`);
                expect(
                    judge(set, 'mcq', {
                        entryId: event.entryId,
                        presented: round.options.map((option) => option.id),
                        selected: event.selected,
                    }),
                ).toBe(event.correct);
            }
        },
    );
});
