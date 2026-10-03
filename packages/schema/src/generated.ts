/* 由 packages/schema/scripts/generate-types.mjs 從 JSON Schema 自動產生，請勿手動修改。 */

/**
 * Kancil Set Format v1：題組的交換格式，規格見 docs/SPEC.md 第 6 節。以下規則 JSON Schema 無法表達，由程式另外檢查：所有文字為 NFC；同一題組內 entry ID 不重複；同一題內選項 ID 不重複。
 */
export type KancilSet = VocabSet | QuizSet;
export type VocabSet = SetBase & {
  kind: "vocab";
  faces: Faces;
  entries: VocabEntry[];
};
/**
 * @minItems 1
 */
export type Authors = Author[];
/**
 * @minItems 1
 */
export type FaceFields = ("text" | "romanization" | "translation_zh" | "audio" | "image")[];
export type QuizSet = SetBase & {
  kind: "quiz";
  entries: QuizEntry[];
};

/**
 * 活動播放格式 v1：GET /api/v1/activities/{activity} 的回應，規格見 docs/SPEC.md 6.6。set 為題組最新版本的交換格式，媒體路徑為絕對網址。
 */
export interface KancilActivity {
  format: "kancil-activity";
  version: 1;
  id: string;
  game: GameRef;
  mode: "practice" | "assignment";
  /**
   * ISO 8601 時間；作業模式的開放時間
   */
  opens_at?: string | null;
  /**
   * ISO 8601 時間；作業模式的截止時間
   */
  closes_at?: string | null;
  /**
   * set 對應的題組版本，開始作答時要送回伺服器
   */
  set_revision_id: string;
  set: KancilSet;
}
export interface GameRef {
  /**
   * 遊戲 ID，例：maze-chase
   */
  id: string;
  /**
   * 遊戲版本（semver）
   */
  version: string;
  /**
   * 遊戲設定，內容由各遊戲的 optionsSchema 定義
   */
  options: {
    [k: string]: unknown;
  };
}
export interface SetBase {
  format: "kancil-set";
  version: 1;
  id: string;
  kind: "vocab" | "quiz";
  /**
   * 語言代碼，見 docs/SPEC.md 附錄 A
   */
  language: "id" | "vi" | "ms" | "fil" | "th" | "km" | "my";
  title: string;
  description?: string | null;
  /**
   * SPDX 授權識別碼，建議使用 CC-BY-4.0、CC-BY-SA-4.0 或 CC0-1.0
   */
  license: string;
  authors: Authors;
  curriculum?: CurriculumRef[];
  tags?: string[];
}
export interface Author {
  name: string;
  url?: string;
}
export interface CurriculumRef {
  volume: number;
  lesson: number;
}
/**
 * 詞彙組哪一面當題目、哪一面當答案
 */
export interface Faces {
  prompt: FaceFields;
  answer: FaceFields;
}
export interface VocabEntry {
  id: string;
  item: VocabItem;
}
export interface VocabItem {
  text: string;
  romanization?: string | null;
  translation_zh: string;
  /**
   * 可以有多個，例如不同發音者
   */
  audio?: Media[];
  image?: Media | null;
  tags?: string[];
  authors?: Authors;
  /**
   * SPDX 授權識別碼，建議使用 CC-BY-4.0、CC-BY-SA-4.0 或 CC0-1.0
   */
  license?: string;
  source?: string;
}
/**
 * 音檔或圖片。authors、license、source 省略時沿用外層（媒體沿用詞條，詞條沿用題組）
 */
export interface Media {
  /**
   * zip 內的相對路徑（例：media/<媒體 ID>.m4a）；API 輸出時為絕對網址
   */
  src: string;
  authors?: Authors;
  /**
   * SPDX 授權識別碼，建議使用 CC-BY-4.0、CC-BY-SA-4.0 或 CC0-1.0
   */
  license?: string;
  source?: string;
}
export interface QuizEntry {
  id: string;
  question: QuizQuestion;
}
export interface QuizQuestion {
  stem: QuizStem;
  /**
   * 2 到 6 個選項，v1 恰有一個正解（多個正解見 SPEC D-11）
   *
   * @minItems 2
   * @maxItems 6
   */
  options: QuizOption[];
}
/**
 * 題幹，文字、音檔、圖片至少要有一項
 */
export interface QuizStem {
  text?: string | null;
  audio?: Media | null;
  image?: Media | null;
}
/**
 * 選項，文字與圖片至少要有一項
 */
export interface QuizOption {
  id: string;
  text?: string | null;
  image?: Media | null;
  correct: boolean;
}
