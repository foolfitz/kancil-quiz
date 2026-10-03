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

// 成績頁（app/Grading/ActivityResults.php）
export interface ResultFace {
    text: string | null;
    note: string | null;
    image: string | null;
    audio: string | null;
}

export interface QuestionResult {
    entry_id: string;
    question: ResultFace;
    answer: ResultFace | null;
    responses: number;
    answered: number;
    wrong: number;
    revisions: number[];
    changed: boolean;
    common_mistake: { face: ResultFace; count: number } | null;
}

export interface AttemptRow {
    id: string;
    player_label: string | null;
    started_at: string;
    completed_at: string | null;
    correct_count: number | null;
    round_count: number;
    game_score: number | null;
    duration_ms: number | null;
    revision_number: number;
}

export interface AttemptDetail {
    id: string;
    revision_number: number;
    rounds: {
        entry_id: string;
        question: ResultFace;
        answer: ResultFace | null;
        selected: ResultFace | null;
        correct: boolean | null;
        answered: boolean;
        tries: number;
    }[];
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
}

// 教材冊課對照（docs/SPEC.md 3.6）
export interface CurriculumRef {
    id: number;
    language_code: string;
    volume: number;
    lesson: number;
    title_zh: string | null;
}

export function curriculumLabel(ref: {
    volume: number;
    lesson: number;
    title_zh?: string | null;
}): string {
    const label = `第 ${ref.volume} 冊第 ${ref.lesson} 課`;
    return ref.title_zh ? `${label}：${ref.title_zh}` : label;
}
