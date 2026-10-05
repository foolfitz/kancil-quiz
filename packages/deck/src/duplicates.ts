import type { Face } from '@kancil-quiz/games-sdk';
import type { KancilSet } from '@kancil-quiz/schema';
import { cardsOf } from './cards';
import { faceKey } from './faces';

// 看起來一樣的題目、答案與選項（docs/SPEC.md 7.3）。老師編輯題組時提醒，不擋下儲存：
// 題目相同的兩個詞（例如中文意思都是「爸爸」），選擇題不會讓它們互為干擾選項，配對則沒有唯一解。
// 文字以 @kancil-quiz/text 的正規形式比對（大小寫、空白、NFC 都不算差異）。

export interface DuplicateFace {
    slot: 'prompt' | 'answer' | 'option';
    entryId: string;
    position: number; // 第幾題，從 1 開始
    // 與哪一題相同（prompt、answer）；同一題的哪幾個選項相同（option）
    duplicateOf?: { entryId: string; position: number };
    optionIds?: string[];
    message: string; // 給老師看的說明
}

function label(face: Face): string {
    return (
        face.text?.trim() ||
        face.romanization?.trim() ||
        (face.image ? '圖片' : face.audio ? '音檔' : '')
    );
}

// 還沒填的那一面不算重複，否則新增的空白列會互相提醒
function isBlank(face: Face): boolean {
    return label(face) === '';
}

const LETTERS: Record<string, string> = {
    a: 'A',
    b: 'B',
    c: 'C',
    d: 'D',
    e: 'E',
    f: 'F',
};

export function duplicateFaces(set: KancilSet): DuplicateFace[] {
    const cards = cardsOf(set);
    const result: DuplicateFace[] = [];

    for (const slot of ['prompt', 'answer'] as const) {
        const seen = new Map<string, (typeof cards)[number]>();
        for (const card of cards) {
            const face = slot === 'prompt' ? card.question : card.answer;
            if (isBlank(face)) {
                continue;
            }
            const key = faceKey(face, set.language);
            const first = seen.get(key);
            if (!first) {
                seen.set(key, card);
                continue;
            }
            const name = slot === 'prompt' ? '題目' : '答案';
            const why =
                slot === 'prompt'
                    ? '學生分不出哪一個是正解'
                    : '配對會沒有唯一解';
            result.push({
                slot,
                entryId: card.entryId,
                position: card.position,
                duplicateOf: {
                    entryId: first.entryId,
                    position: first.position,
                },
                message: `第 ${card.position} 題的${name}與第 ${first.position} 題相同（${label(face)}），${why}`,
            });
        }
    }

    for (const card of cards) {
        const groups = new Map<string, string[]>();
        for (const option of card.options ?? []) {
            if (isBlank(option.face)) {
                continue;
            }
            const key = faceKey(option.face, set.language);
            groups.set(key, [...(groups.get(key) ?? []), option.id]);
        }
        for (const ids of groups.values()) {
            if (ids.length < 2) {
                continue;
            }
            const face =
                card.options?.find((option) => option.id === ids[0])?.face ??
                {};
            result.push({
                slot: 'option',
                entryId: card.entryId,
                position: card.position,
                optionIds: ids,
                message: `第 ${card.position} 題的選項 ${ids.map((id) => LETTERS[id] ?? id).join('、')} 相同（${label(face)}）`,
            });
        }
    }

    return result;
}
