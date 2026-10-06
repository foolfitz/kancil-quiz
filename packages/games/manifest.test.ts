import type { GameModule } from '@kancil-quiz/games-sdk';
import { expect, it } from 'vite-plus/test';

// 由各遊戲的 meta.ts 產生 manifest.json，供伺服器端（app/Games/GameRegistry.php）與老師端使用。
// 新增遊戲或修改 meta 後執行 npm run games:manifest 更新。
// 計分的遊戲在 kancil-games、互動教材在 kancil-materials，兩個 submodule 中各自再分目錄
const metas = import.meta.glob<Omit<GameModule, 'mount'>>(
    ['./kancil-games/*/src/meta.ts', './kancil-materials/*/src/meta.ts'],
    {
        eager: true,
        import: 'meta',
    },
);

it('manifest.json 與各遊戲的 meta.ts 同步', async () => {
    const games = Object.values(metas)
        .sort((a, b) => a.id.localeCompare(b.id))
        .map(
            ({
                id,
                version,
                title,
                scoreLabel,
                requires,
                optionsSchema,
                defaultOptions,
            }) => ({
                id,
                version,
                title,
                // 只有定義了遊戲得分名稱的遊戲才有這個欄位
                ...(scoreLabel ? { scoreLabel } : {}),
                requires,
                optionsSchema,
                defaultOptions,
            }),
        );

    await expect(`${JSON.stringify({ games }, null, 4)}\n`).toMatchFileSnapshot(
        './manifest.json',
    );
});
