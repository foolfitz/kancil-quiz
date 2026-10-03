import type { GameRequirements } from '@kancil-quiz/games-sdk';

// 老師端頁面與後端（app/Corpus/SetEditorData.php）之間的資料形狀。

export type SetKind = 'vocab' | 'quiz';
export type FaceField =
    | 'text'
    | 'romanization'
    | 'translation_zh'
    | 'audio'
    | 'image';

export interface MediaRef {
    id: string;
    kind: 'audio' | 'image';
    url: string;
    thumbnail_url: string | null;
    duration_ms: number | null;
}

export interface VocabEntryInput {
    id: string | null;
    item: {
        text: string;
        romanization: string | null;
        translation_zh: string;
        audio: MediaRef[];
        image: MediaRef | null;
    };
}

export interface QuizOptionInput {
    id: string;
    text: string;
    image: MediaRef | null;
    correct: boolean;
}

export interface QuizEntryInput {
    id: string | null;
    question: {
        stem: { text: string; audio: MediaRef | null; image: MediaRef | null };
        options: QuizOptionInput[];
    };
}

export interface Language {
    code: string;
    name_zh: string;
    name_native: string;
}

export type OptionValues = Record<string, string | number | boolean | null>;

// packages/games/manifest.json 中的一個遊戲
export interface GameInfo {
    id: string;
    version: string;
    title: { 'zh-TW': string };
    requires: GameRequirements;
    optionsSchema: {
        properties?: Record<string, OptionSchema>;
    };
    defaultOptions: OptionValues;
}

export interface OptionSchema {
    type?: string;
    title?: string;
    description?: string;
    enum?: (string | number)[];
    minimum?: number;
    maximum?: number;
    oneOf?: { const: string | number; title?: string }[];
}

export const KIND_NAMES: Record<SetKind, string> = {
    vocab: '詞彙組',
    quiz: '問答組',
};
