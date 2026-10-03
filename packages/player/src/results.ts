import { judge } from '@kancil-quiz/deck';
import type { Face, Round } from '@kancil-quiz/games-sdk';
import type { KancilSet } from '@kancil-quiz/schema';
import type { ResponseRecord } from './api';

export interface RoundResult {
    round: Round;
    // null：不計分的形狀；false 也包含沒有作答（例如迷宮的命用完了）
    correct: boolean | null;
    answered: boolean;
}

export interface Results {
    correctCount: number | null;
    rounds: RoundResult[];
}

// 每題以第一筆作答計算。serverResults 有值時以伺服器判定為準（docs/SPEC.md 7.4），
// 沒有時（獨立播放器、離線）用同一套規則在本機判定。
export function computeResults(
    set: KancilSet,
    rounds: Round[],
    responses: ResponseRecord[],
    serverResults?: { entry_id: string; correct: boolean | null }[],
): Results {
    const shape = rounds[0]?.shape ?? 'mcq';
    const first = new Map<string, ResponseRecord>();
    for (const response of responses) {
        if (!first.has(response.entry_id)) {
            first.set(response.entry_id, response);
        }
    }
    const server = new Map(
        (serverResults ?? []).map((result) => [
            result.entry_id,
            result.correct,
        ]),
    );

    const results = rounds.map((round): RoundResult => {
        const response = first.get(round.entryId);
        if (shape === 'card') {
            return { round, correct: null, answered: response !== undefined };
        }
        if (!response) {
            return { round, correct: false, answered: false };
        }
        const correct = server.has(round.entryId)
            ? (server.get(round.entryId) ?? false)
            : (judge(set, shape, {
                  entryId: round.entryId,
                  presented: response.presented,
                  selected: response.selected ?? [],
              }) ?? false);
        return { round, correct, answered: true };
    });

    return {
        correctCount:
            shape === 'card'
                ? null
                : results.filter((result) => result.correct).length,
        rounds: results,
    };
}

// 這一題「出現了哪些選項」：mcq 為選項 id；pair 為右側卡片（以 entry id 識別）。
export function presentedOptions(round: Round, rounds: Round[]): string[] {
    switch (round.shape) {
        case 'mcq':
            return round.options.map((option) => option.id);
        case 'pair':
            return rounds.map((r) => r.entryId);
        case 'card':
            return [];
    }
}

export function questionFace(round: Round): Face {
    switch (round.shape) {
        case 'mcq':
            return round.prompt;
        case 'pair':
            return round.left;
        case 'card':
            return round.front;
    }
}

export function answerFace(round: Round): Face {
    switch (round.shape) {
        case 'mcq':
            return round.options.find((option) => option.correct)?.face ?? {};
        case 'pair':
            return round.right;
        case 'card':
            return round.back;
    }
}

// 結算畫面「可播放發音」用的音檔：詞彙組是詞條的第一個音檔，問答組是題幹的音檔。
export function pronunciation(
    set: KancilSet,
    entryId: string,
): string | undefined {
    if (set.kind === 'vocab') {
        return set.entries.find((e) => e.id === entryId)?.item.audio?.[0]?.src;
    }
    return (
        set.entries.find((e) => e.id === entryId)?.question.stem.audio?.src ??
        undefined
    );
}
