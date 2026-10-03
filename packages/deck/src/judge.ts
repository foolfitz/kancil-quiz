import type { Round } from '@kancil-quiz/games-sdk';
import type { KancilSet } from '@kancil-quiz/schema';

// 成績判定，規則見 docs/SPEC.md 7.4。伺服器端的 app/Grading/Judge.php 是同一套規則，
// 兩邊共用 packages/schema/fixtures/grading/ 的案例測試。

export interface Response {
    entryId: string;
    presented: string[]; // 這一題出現了哪些選項（mcq 為選項 id；pair 為右側卡片的 entryId）
    selected: string[];
}

// 回傳 null 表示這種形狀不計分（例如字卡）。
export function judge(
    set: KancilSet,
    shape: Round['shape'],
    { entryId, presented, selected }: Response,
): boolean | null {
    if (shape === 'card') {
        return null;
    }

    const [choice] = selected;
    if (selected.length !== 1 || !presented.includes(choice)) {
        return false;
    }

    if (set.kind === 'quiz' && shape === 'mcq') {
        const entry = set.entries.find((e) => e.id === entryId);
        return (
            entry?.question.options.some(
                (option) => option.id === choice && option.correct,
            ) ?? false
        );
    }

    // 詞彙組的選項 id 就是詞條的 entry id；配對時右側卡片也以 entry id 識別。
    return set.entries.some((e) => e.id === entryId) && choice === entryId;
}

// 每題以第一筆作答計算（例如迷宮答錯後重試，仍算答錯）。
export function countCorrect(
    set: KancilSet,
    shape: Round['shape'],
    responses: Response[],
): number | null {
    if (shape === 'card') {
        return null;
    }

    const first = new Map<string, Response>();
    for (const response of responses) {
        if (!first.has(response.entryId)) {
            first.set(response.entryId, response);
        }
    }

    return [...first.values()].filter((response) => judge(set, shape, response))
        .length;
}
