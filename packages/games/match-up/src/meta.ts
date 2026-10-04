import type { GameModule } from '@kancil-quiz/games-sdk';

export interface MatchUpOptions {
    pairsPerPage: number;
}

// 遊戲的設定資訊，不含執行程式；老師端與伺服器端的遊戲清單（manifest.json）都由此產生。
export const meta = {
    id: 'match-up',
    version: '0.1.0',
    title: { 'zh-TW': '配對' },
    requires: {
        shape: 'pair',
        minRounds: 2,
        renders: {
            left: ['text', 'romanization', 'image', 'audio'],
            right: ['text', 'romanization', 'image'],
        },
        scored: true,
    },
    optionsSchema: {
        $schema: 'https://json-schema.org/draft/2020-12/schema',
        title: '配對的設定',
        type: 'object',
        additionalProperties: false,
        required: ['pairsPerPage'],
        properties: {
            pairsPerPage: {
                title: '每頁最多幾組',
                description:
                    '題目比較多時分成幾頁，配完一頁才換下一頁，各頁的組數會盡量平均。平板直向建議不超過 6 組。',
                type: 'integer',
                minimum: 3,
                maximum: 8,
                default: 6,
            },
        },
    },
    defaultOptions: { pairsPerPage: 6 },
} satisfies Omit<GameModule<MatchUpOptions>, 'mount'>;
