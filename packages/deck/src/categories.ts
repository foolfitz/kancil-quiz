import type { GameRequirements } from '@kancil-quiz/games-sdk';

// 遊戲的分類（docs/SPEC.md 7.5）：計分的「遊戲」與不計分的「互動教材」。
// 依 requires.scored 決定，老師端選遊戲與獨立播放器都依此分組。
export const GAME_CATEGORIES = [
    {
        id: 'game',
        title: '遊戲',
        description: '學生作答，記錄答對題數。',
    },
    {
        id: 'material',
        title: '互動教材',
        description:
            '不計分。適合上課介紹新詞、帶全班練習，也可以讓學生自己翻閱。',
    },
] as const;

export type GameCategory = (typeof GAME_CATEGORIES)[number]['id'];

export function gameCategory(
    requires: Pick<GameRequirements, 'scored'>,
): GameCategory {
    return requires.scored ? 'game' : 'material';
}

// 依分類分組，保持原本的順序；沒有遊戲的分類不列出。
export function groupGames<
    T extends { requires: Pick<GameRequirements, 'scored'> },
>(games: T[]): { category: (typeof GAME_CATEGORIES)[number]; games: T[] }[] {
    return GAME_CATEGORIES.map((category) => ({
        category,
        games: games.filter(
            (game) => gameCategory(game.requires) === category.id,
        ),
    })).filter((group) => group.games.length > 0);
}
