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
    title_native: string | null;
}

// 教材的一課與教材題組中的詞（app/Curriculum/TextbookData.php，T-18）
export interface TextbookLesson {
    volume: number;
    lesson: number;
    title_zh: string | null;
    title_native: string | null;
    url: string;
    words: {
        id: string;
        text: string;
        translation_zh: string;
        thumbnail_url: string | null;
    }[];
}

// 冊課選單中的一課，例：第 3 課 Keluarga Saya 我的家人
export function lessonLabel(ref: {
    lesson: number;
    title_zh?: string | null;
    title_native?: string | null;
}): string {
    return [`第 ${ref.lesson} 課`, ref.title_native, ref.title_zh]
        .filter(Boolean)
        .join(' ');
}

export function curriculumLabel(ref: {
    volume: number;
    lesson: number;
    title_zh?: string | null;
}): string {
    const label = `第 ${ref.volume} 冊第 ${ref.lesson} 課`;
    return ref.title_zh ? `${label}：${ref.title_zh}` : label;
}

// 題組檢視頁（app/Corpus/SetViewData.php）
export interface SetView {
    id: string;
    kind: SetKind;
    title: string;
    description: string | null;
    language: { code: string; name_zh: string };
    license: string;
    tags: string[];
    curriculum: { volume: number; lesson: number; title_zh: string | null }[];
    authors: string[];
    owner: string;
    visibility: 'private' | 'unlisted' | 'public';
    review_status: 'none' | 'pending' | 'approved' | 'rejected';
    revision: number | null;
    updated_at: string | null;
    forked_from: {
        id: string;
        title: string;
        owner: string;
        viewable: boolean;
    } | null;
    textbook_url: string | null;
}

export interface SetViewEntry {
    id: string;
    question: ResultFace;
    answer: ResultFace | null;
    options: { face: ResultFace; correct: boolean }[];
}

export interface SetReviewEntry {
    action: 'requested' | 'withdrawn' | 'approved' | 'rejected' | 'unpublished';
    note: string | null;
    user: string;
    created_at: string;
}

// 題組編輯頁的分享與公開狀態（T-17、T-12）
export interface SetSharing {
    visibility: 'private' | 'unlisted' | 'public';
    review_status: 'none' | 'pending' | 'approved' | 'rejected';
    share_url: string | null;
    last_review: {
        action: SetReviewEntry['action'];
        note: string | null;
        created_at: string;
    } | null;
}

// 修訂紀錄（app/Corpus/RevisionDiff.php，C-03）
export interface RevisionEntry {
    number: number;
    created_by: string | null;
    created_at: string | null;
    changes: {
        kind:
            | 'created'
            | 'field'
            | 'added'
            | 'removed'
            | 'changed'
            | 'reordered'
            | 'unknown';
        label: string;
        before: string | null;
        after: string | null;
    }[];
}
