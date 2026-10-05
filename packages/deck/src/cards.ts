import type { Face } from '@kancil-quiz/games-sdk';
import type { KancilSet } from '@kancil-quiz/schema';
import { optionFace, stemFace, vocabFace } from './faces';

// 不論題組種類，每一題都整理成「題目一面、答案一面」；問答組另外保留全部選項。
// 相容檢查、轉成 Round 與重複檢查都從這裡出發（docs/SPEC.md 7.3）。

export interface Option {
    id: string;
    face: Face;
    correct: boolean;
}

export interface Card {
    entryId: string;
    position: number; // 第幾題，從 1 開始，用在給老師看的說明
    question: Face;
    answer: Face;
    options?: Option[];
}

export function cardsOf(set: KancilSet): Card[] {
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
