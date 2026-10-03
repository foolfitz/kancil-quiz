import type { GameModule } from '@kancil-quiz/games-sdk';

export interface QuizOptions {
    revealAnswer: boolean;
    autoAdvance: boolean;
}

// 遊戲的設定資訊，不含執行程式；老師端與伺服器端的遊戲清單（manifest.json）都由此產生。
export const meta = {
    id: 'quiz',
    version: '0.1.0',
    title: { 'zh-TW': '選擇題' },
    requires: {
        shape: 'mcq',
        minRounds: 1,
        optionCount: { min: 2, max: 6 },
        renders: {
            prompt: ['text', 'image', 'audio'],
            option: ['text', 'image'],
        },
        scored: true,
    },
    optionsSchema: {
        type: 'object',
        properties: {
            revealAnswer: { type: 'boolean', title: '答錯時顯示正確答案' },
            autoAdvance: { type: 'boolean', title: '作答後自動進入下一題' },
        },
        additionalProperties: false,
    },
    defaultOptions: { revealAnswer: true, autoAdvance: true },
} satisfies Omit<GameModule<QuizOptions>, 'mount'>;
