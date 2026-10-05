import type {
    Face,
    FaceField,
    FaceSlot,
    GameRequirements,
    Round,
} from '@kancil-quiz/games-sdk';
import type { KancilSet } from '@kancil-quiz/schema';
import {
    SLOTS,
    faceKey,
    isEmptyFace,
    isRenderable,
    optionFace,
    stemFace,
    vocabFace,
} from './faces';
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
        | 'duplicate-answer';
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

interface Option {
    id: string;
    face: Face;
    correct: boolean;
}

// 不論題組種類，每一題都整理成「題目一面、答案一面」；問答組另外保留全部選項。
interface Card {
    entryId: string;
    position: number; // 第幾題，從 1 開始，用在給老師看的說明
    question: Face;
    answer: Face;
    options?: Option[];
}

function cardsOf(set: KancilSet): Card[] {
    if (set.kind === 'vocab') {
        return set.entries.map((entry, i) => ({
            entryId: entry.id,
            position: i + 1,
            question: vocabFace(entry.item, set.faces.prompt, set.language),
            answer: vocabFace(entry.item, set.faces.answer, set.language),
        }));
    }

    return set.entries.map((entry, i) => {
        const options = entry.question.options.map((option) => ({
            id: option.id,
            face: optionFace(option),
            correct: option.correct,
        }));
        return {
            entryId: entry.id,
            position: i + 1,
            question: stemFace(entry.question.stem),
            answer: options.find((option) => option.correct)?.face ?? {},
            options,
        };
    });
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

// 詞彙組的選項：正解加上從同題組其他詞條抽出的干擾選項，答案看起來一樣的不會同時出現。
function vocabOptions(
    card: Card,
    cards: Card[],
    language: string,
    { count, rng }: { count: number; rng: () => number },
): Option[] {
    const used = new Set([faceKey(card.answer, language)]);
    const distractors: Option[] = [];

    for (const other of shuffle(cards, rng)) {
        if (distractors.length >= count - 1) {
            break;
        }
        const key = faceKey(other.answer, language);
        if (!used.has(key)) {
            used.add(key);
            distractors.push({
                id: other.entryId,
                face: other.answer,
                correct: false,
            });
        }
    }

    return [
        { id: card.entryId, face: card.answer, correct: true },
        ...distractors,
    ];
}
