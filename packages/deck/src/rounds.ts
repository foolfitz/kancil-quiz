import type {
    Face,
    FaceField,
    FaceSlot,
    GameRequirements,
    Round,
} from '@kancil-quiz/games-sdk';
import type { KancilSet } from '@kancil-quiz/schema';
import { cardsOf } from './cards';
import type { Card, Option } from './cards';
import { duplicateFaces } from './duplicates';
import { SLOTS, faceKey, isEmptyFace, isRenderable } from './faces';
import { shuffle } from './rng';

// 題組轉成遊戲需要的形狀，規則見 docs/SPEC.md 7.3。

// 遊戲允許的範圍內，詞彙組轉選擇題時每題的選項數。
const PREFERRED_OPTION_COUNT = 4;

export interface CompatibilityIssue {
    severity: 'error' | 'warning';
    code:
        | 'faces-conflict'
        | 'too-few-rounds'
        | 'empty-face'
        | 'unrenderable'
        | 'too-few-answers'
        | 'too-few-options'
        | 'too-many-options'
        | 'duplicate-answer'
        | 'duplicate-prompt'
        | 'duplicate-option';
    entryId?: string;
    message: string; // 給老師看的說明
}

export interface CompatibilityReport {
    ok: boolean; // 沒有 error 等級的問題
    issues: CompatibilityIssue[];
}

export class IncompatibleSetError extends Error {
    constructor(readonly report: CompatibilityReport) {
        super(report.issues.map((issue) => issue.message).join('\n'));
        this.name = 'IncompatibleSetError';
    }
}

const SLOT_NAMES: Record<FaceSlot, string> = {
    prompt: '題目',
    option: '選項',
    left: '左側',
    right: '右側',
    front: '正面',
    back: '背面',
};

const FIELD_NAMES: Record<FaceField, string> = {
    text: '文字',
    romanization: '羅馬拼寫',
    audio: '音檔',
    image: '圖片',
};

function optionCountRange(requires: GameRequirements): {
    min: number;
    max: number;
} {
    return requires.optionCount ?? { min: 2, max: 6 };
}

export function check(
    set: KancilSet,
    requires: GameRequirements,
): CompatibilityReport {
    const issues: CompatibilityIssue[] = [];
    const add = (
        severity: CompatibilityIssue['severity'],
        code: CompatibilityIssue['code'],
        message: string,
        entryId?: string,
    ) => issues.push({ severity, code, message, entryId });

    if (set.kind === 'vocab') {
        for (const [side, name] of [
            ['prompt', '題目'],
            ['answer', '答案'],
        ] as const) {
            const fields = set.faces[side];
            if (fields.includes('text') && fields.includes('translation_zh')) {
                add(
                    'error',
                    'faces-conflict',
                    `${name}面不能同時使用目標語文字與中文意思`,
                );
            }
        }
    }

    const cards = cardsOf(set);
    if (cards.length < requires.minRounds) {
        add(
            'error',
            'too-few-rounds',
            `這個遊戲至少需要 ${requires.minRounds} 題，題組目前有 ${cards.length} 題`,
        );
    }

    const [questionSlot, answerSlot] = SLOTS[requires.shape];
    const checkFace = (card: Card, face: Face, slot: FaceSlot) => {
        if (isEmptyFace(face)) {
            add(
                'error',
                'empty-face',
                `第 ${card.position} 題的${SLOT_NAMES[slot]}是空的`,
                card.entryId,
            );
        } else if (!isRenderable(face, requires.renders[slot])) {
            const fields = (requires.renders[slot] ?? [])
                .map((field) => FIELD_NAMES[field])
                .join('、');
            add(
                'error',
                'unrenderable',
                `第 ${card.position} 題的${SLOT_NAMES[slot]}沒有這個遊戲能呈現的內容（可呈現：${fields}）`,
                card.entryId,
            );
        }
    };

    for (const card of cards) {
        checkFace(card, card.question, questionSlot);
        if (requires.shape === 'mcq' && card.options) {
            for (const option of card.options) {
                checkFace(card, option.face, answerSlot);
            }
        } else {
            checkFace(card, card.answer, answerSlot);
        }
    }

    if (requires.shape === 'mcq') {
        const { min, max } = optionCountRange(requires);

        if (set.kind === 'vocab') {
            const distinct = new Set(
                cards.map((card) => faceKey(card.answer, set.language)),
            ).size;
            if (distinct < min) {
                add(
                    'error',
                    'too-few-answers',
                    `答案互不相同的詞條只有 ${distinct} 個，這個遊戲每題至少需要 ${min} 個選項`,
                );
            } else {
                // 題目相同的詞條不互為干擾選項（答案對這個題目也算對），扣掉之後也要湊得滿
                for (const card of cards) {
                    const available = distractorsFor(card, cards, set.language);
                    if (available.length < min - 1) {
                        add(
                            'error',
                            'too-few-answers',
                            `第 ${card.position} 題的題目與其他詞條相同，扣掉它們之後只剩 ${available.length} 個可以當選項的答案，這個遊戲每題至少需要 ${min} 個選項`,
                            card.entryId,
                        );
                    }
                }
            }
        }

        for (const card of cards) {
            const count = card.options?.length ?? 0;
            if (card.options && count < min) {
                add(
                    'error',
                    'too-few-options',
                    `第 ${card.position} 題只有 ${count} 個選項，這個遊戲每題至少需要 ${min} 個`,
                    card.entryId,
                );
            } else if (card.options && count > max) {
                add(
                    'warning',
                    'too-many-options',
                    `第 ${card.position} 題有 ${count} 個選項，這個遊戲最多顯示 ${max} 個；會保留正解，其餘隨機抽出`,
                    card.entryId,
                );
            }
        }
    }

    // 題目看起來一樣的兩題，學生分不出哪一個是正解；問答組同一題的選項相同也一樣（不擋下，只提醒）
    if (requires.shape === 'mcq' || requires.shape === 'pair') {
        for (const duplicate of duplicateFaces(set)) {
            if (duplicate.slot === 'prompt') {
                add(
                    'warning',
                    'duplicate-prompt',
                    duplicate.message,
                    duplicate.entryId,
                );
            } else if (
                duplicate.slot === 'option' &&
                requires.shape === 'mcq'
            ) {
                add(
                    'warning',
                    'duplicate-option',
                    duplicate.message,
                    duplicate.entryId,
                );
            }
        }
    }

    if (requires.shape === 'pair') {
        const seen = new Map<string, Card>();
        for (const card of cards) {
            const key = faceKey(card.answer, set.language);
            const first = seen.get(key);
            if (first) {
                add(
                    'error',
                    'duplicate-answer',
                    `第 ${card.position} 題的答案與第 ${first.position} 題相同，配對會沒有唯一解`,
                    card.entryId,
                );
            } else {
                seen.set(key, card);
            }
        }
    }

    return {
        ok: issues.every((issue) => issue.severity !== 'error'),
        issues,
    };
}

export interface BuildOptions {
    rng: () => number;
    shuffleRounds?: boolean; // 預設 true
    shuffleOptions?: boolean; // 預設 true
}

export function buildRounds(
    set: KancilSet,
    requires: GameRequirements,
    { rng, shuffleRounds = true, shuffleOptions = true }: BuildOptions,
): Round[] {
    const report = check(set, requires);
    if (!report.ok) {
        throw new IncompatibleSetError(report);
    }

    const cards = cardsOf(set);
    const ordered = shuffleRounds ? shuffle(cards, rng) : cards;
    const arrange = (options: Option[]) =>
        shuffleOptions ? shuffle(options, rng) : options;

    switch (requires.shape) {
        case 'card':
            return ordered.map((card) => ({
                shape: 'card',
                entryId: card.entryId,
                front: card.question,
                back: card.answer,
            }));
        case 'pair':
            return ordered.map((card) => ({
                shape: 'pair',
                entryId: card.entryId,
                left: card.question,
                right: card.answer,
            }));
        case 'mcq': {
            const { min, max } = optionCountRange(requires);
            return ordered.map((card) => ({
                shape: 'mcq',
                entryId: card.entryId,
                prompt: card.question,
                options: arrange(
                    card.options
                        ? limitOptions(card.options, max, rng)
                        : vocabOptions(card, cards, set.language, {
                              count: Math.min(
                                  Math.max(PREFERRED_OPTION_COUNT, min),
                                  max,
                              ),
                              rng,
                          }),
                ),
            }));
        }
    }
}

// 問答組選項多於上限時，保留正解並隨機抽出其餘選項。
function limitOptions(
    options: Option[],
    max: number,
    rng: () => number,
): Option[] {
    if (options.length <= max) {
        return options;
    }
    const correct = options.filter((option) => option.correct);
    const others = shuffle(
        options.filter((option) => !option.correct),
        rng,
    );
    return [...correct, ...others.slice(0, max - correct.length)];
}

// 能當這一題干擾選項的其他詞條：答案看起來一樣的不能同時出現；題目看起來一樣的也不行，
// 因為那個詞條的答案對這個題目來說也算對（例如兩個詞的中文意思都是「爸爸」）。
function distractorsFor(card: Card, cards: Card[], language: string): Card[] {
    const prompt = faceKey(card.question, language);
    const used = new Set([faceKey(card.answer, language)]);
    const result: Card[] = [];
    for (const other of cards) {
        const key = faceKey(other.answer, language);
        if (
            other !== card &&
            !used.has(key) &&
            faceKey(other.question, language) !== prompt
        ) {
            used.add(key);
            result.push(other);
        }
    }
    return result;
}

// 詞彙組的選項：正解加上從同題組其他詞條隨機抽出的干擾選項。
function vocabOptions(
    card: Card,
    cards: Card[],
    language: string,
    { count, rng }: { count: number; rng: () => number },
): Option[] {
    const distractors = distractorsFor(card, shuffle(cards, rng), language)
        .slice(0, count - 1)
        .map((other) => ({
            id: other.entryId,
            face: other.answer,
            correct: false,
        }));

    return [
        { id: card.entryId, face: card.answer, correct: true },
        ...distractors,
    ];
}
