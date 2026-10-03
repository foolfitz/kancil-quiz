import type { Round } from '@kancil-quiz/games-sdk';

type McqRound = Extract<Round, { shape: 'mcq' }>;

export interface AnswerResult {
    entryId: string;
    selected: string;
    correct: boolean;
    correctOptionId: string;
}

// 選擇題的進行狀態，不碰 DOM，方便測試。每題只能作答一次。
export class QuizSession {
    index = 0;
    score = 0;
    private answered = false;

    constructor(readonly rounds: McqRound[]) {}

    get current(): McqRound | undefined {
        return this.rounds[this.index];
    }

    get finished(): boolean {
        return this.index >= this.rounds.length;
    }

    get canAnswer(): boolean {
        return !this.finished && !this.answered;
    }

    answer(optionId: string): AnswerResult | null {
        const round = this.current;
        const option = round?.options.find((o) => o.id === optionId);
        if (!round || !option || this.answered) {
            return null;
        }

        this.answered = true;
        if (option.correct) {
            this.score++;
        }

        return {
            entryId: round.entryId,
            selected: option.id,
            correct: option.correct,
            correctOptionId: round.options.find((o) => o.correct)?.id ?? '',
        };
    }

    next(): void {
        if (this.answered) {
            this.index++;
            this.answered = false;
        }
    }
}

export function mcqRounds(rounds: Round[]): McqRound[] {
    return rounds.filter((round): round is McqRound => round.shape === 'mcq');
}
