// 題組轉 Round、干擾選項、相容檢查、成績判定，規格見 docs/SPEC.md 7.3、7.4。
export { buildRounds, check, IncompatibleSetError } from './rounds';
export type {
    BuildOptions,
    CompatibilityIssue,
    CompatibilityReport,
} from './rounds';
export { GAME_CATEGORIES, gameCategory, groupGames } from './categories';
export type { GameCategory } from './categories';
export { countCorrect, judge } from './judge';
export type { Response } from './judge';
export { createRng, randomSeed, shuffle } from './rng';
