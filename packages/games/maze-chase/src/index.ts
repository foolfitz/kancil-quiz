// 迷宮追逐（docs/SPEC.md 7.5）：由獨立的 maze-quiz 改寫成遊戲模組。
// 中繼資料（id、requires、optionsSchema…）在 meta.ts，老師端只需要那些時可以 import '…/meta'。

import type { GameModule } from '@kancil-quiz/games-sdk';
import { meta, type MazeChaseOptions } from './meta';
import { mount } from './mount';

export type { MazeChaseOptions } from './meta';

export const mazeChase: GameModule<MazeChaseOptions> = { ...meta, mount };

export default mazeChase;
