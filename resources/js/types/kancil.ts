import type { GameRequirements } from '@kancil-quiz/games-sdk';
import SetExportController from '@/actions/App/Http/Controllers/SetExportController';

// 老師端頁面與後端（app/Corpus/SetEditorData.php）之間的資料形狀。

export type SetKind = 'vocab' | 'quiz';
export type FaceField =
    | 'text'
    | 'romanization'
    | 'translation_zh'
    | 'audio'
    | 'image';

// 媒體與它的署名（docs/SPEC.md 第 9 節）。author 是作者名字以「、」連起來；license、source 為 null 表示
// 沿用詞條與題組（6.5）。editable：是自己上傳的，可以在編輯頁修改署名；別人的（例如教材的插圖）只能看。
export interface MediaRef {
    id: string;
    kind: 'audio' | 'image';
    url: string;
    thumbnail_url: string | null;
    duration_ms: number | null;
    author: string;
    source: string | null;
    license: string | null;
    editable: boolean;
}

// 授權的顯示名稱，與 app/Support/Licenses.php 相同；不認得的識別碼原樣顯示
const LICENSE_NAMES: Record<string, string> = {
    'CC-BY-4.0': 'CC BY 4.0',
    'CC-BY-SA-4.0': 'CC BY-SA 4.0',
    'CC-BY-NC-ND-4.0': 'CC BY-NC-ND 4.0',
    'CC0-1.0': 'CC0 1.0',
};

export function licenseName(license: string): string {
    return LICENSE_NAMES[license] ?? license;
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

// 結果頁依名字或座號彙整的成績（docs/SPEC.md 3.4）：以第一次玩完的作答（counted）計算
export interface StudentAttempt {
    id: string;
    started_at: string;
    completed_at: string | null;
    correct_count: number | null;
    round_count: number;
    counted: boolean;
}

export interface StudentRow {
    label: string;
    attempts: StudentAttempt[];
    completed: number;
    counted: StudentAttempt;
    best: { correct_count: number; round_count: number } | null;
    last_at: string;
}

// 活動的設定（docs/SPEC.md 3.4）。時間是伺服器設定時區（台灣）的當地時間，
// 格式與 <input type="datetime-local"> 相同（2026-10-10T23:59）。
export interface ActivitySettings {
    require_label: boolean;
    opens_at: string | null;
    closes_at: string | null;
}

export type ActivityStatus = 'scheduled' | 'open' | 'closed';

export interface ActivitySettingsView extends ActivitySettings {
    status: ActivityStatus;
}

export const STATUS_NAMES: Record<ActivityStatus, string> = {
    scheduled: '尚未開放',
    open: '進行中',
    closed: '已截止',
};

// 「2026-10-10T23:59」顯示成「2026/10/10 23:59」。已經是當地時間，不再換算時區。
export function localTime(value: string): string {
    return value.replace('T', ' ').replaceAll('-', '/');
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

// 公開頁面的標題、描述與連結預覽（app/Support/PageMeta.php）。伺服器也把同樣的內容寫在 <head>，
// 讓搜尋引擎與 LINE 等的連結預覽不必執行 JS 就讀得到。
export interface PageMeta {
    title: string;
    description: string;
    url: string;
    image: string | null;
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

// 用獨立播放器（docs/SPEC.md O-02）開啟公開題組：從不需登入的開放資料網址載入 zip
export function standaloneUrl(setId: string): string {
    const zip = SetExportController.openData.url(setId);
    return `/standalone.html?zip=${encodeURIComponent(zip)}`;
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
