import type { Round } from '@kancil-quiz/games-sdk';

type PairRound = Extract<Round, { shape: 'pair' }>;

export interface MatchPage {
    // 左側：題目依宿主給的順序排列
    slots: PairRound[];
    // 右側：同一頁的答案卡片，另外打亂
    cards: PairRound[];
}

export interface PlaceResult {
    entryId: string; // 放到哪一題（左側）
    selected: string; // 放上去的卡片（右側，以 entryId 識別）
    correct: boolean;
    firstTry: boolean; // 這一題的第一次作答，成績以它為準（docs/SPEC.md 7.4）
    presented: string[]; // 這一頁出現的所有卡片
    pageComplete: boolean;
}

// 畫面放不下老師設定的組數時，每頁最少還是這麼多組（老師能設定的下限也是 3）
export const MIN_PAIRS_PER_PAGE = 3;

// 老師設定的是每頁「最多」幾組（docs/SPEC.md 7.5）。從上限往下找第一個放得下的組數：
// fits 由畫面實際量測；都放不下就用下限，剩下的交給捲動。最後一次呼叫 fits 的參數就是回傳值。
export function fitPairsPerPage(
    max: number,
    fits: (perPage: number) => boolean,
): number {
    for (let perPage = max; ; perPage--) {
        const ok = fits(perPage);
        if (ok || perPage <= MIN_PAIRS_PER_PAGE) {
            return perPage;
        }
    }
}

// 把 count 題分成每頁不超過 perPage 題，各頁題數盡量平均（例如 7 題、每頁 6 題 → 4、3）。
export function pageSizes(count: number, perPage: number): number[] {
    if (count <= 0) {
        return [];
    }
    const pages = Math.ceil(count / Math.max(1, perPage));
    const base = Math.floor(count / pages);
    const extra = count % pages;
    return Array.from({ length: pages }, (_, i) => base + (i < extra ? 1 : 0));
}

function shuffled<T>(items: readonly T[], rng: () => number): T[] {
    const result = [...items];
    for (let i = result.length - 1; i > 0; i--) {
        const j = Math.floor(rng() * (i + 1));
        [result[i], result[j]] = [result[j], result[i]];
    }
    return result;
}

// 配對的進行狀態，不碰 DOM，方便測試。放錯的卡片退回，可以再放；每題以第一次放上去的卡片計分。
export class MatchSession {
    readonly pages: MatchPage[];
    pageIndex = 0;
    score = 0;
    private readonly matched = new Set<string>();
    private readonly tried = new Set<string>();

    constructor(rounds: PairRound[], perPage: number, rng: () => number) {
        let start = 0;
        this.pages = pageSizes(rounds.length, perPage).map((size) => {
            const slots = rounds.slice(start, start + size);
            start += size;
            return { slots, cards: shuffled(slots, rng) };
        });
    }

    get page(): MatchPage | undefined {
        return this.pages[this.pageIndex];
    }

    get finished(): boolean {
        return this.pageIndex >= this.pages.length;
    }

    get pageComplete(): boolean {
        return (
            this.page?.slots.every((slot) => this.matched.has(slot.entryId)) ??
            false
        );
    }

    isMatched(entryId: string): boolean {
        return this.matched.has(entryId);
    }

    // 把卡片放到某一題。卡片或題目不在這一頁、或已經配好時不算作答，回傳 null。
    place(cardId: string, slotId: string): PlaceResult | null {
        const page = this.page;
        if (
            !page ||
            !page.cards.some((card) => card.entryId === cardId) ||
            !page.slots.some((slot) => slot.entryId === slotId) ||
            this.matched.has(cardId) ||
            this.matched.has(slotId)
        ) {
            return null;
        }

        // 配對的正解就是同一個詞條的右側（docs/SPEC.md 7.4）
        const correct = cardId === slotId;
        const firstTry = !this.tried.has(slotId);
        this.tried.add(slotId);
        if (correct) {
            this.matched.add(slotId);
            if (firstTry) {
                this.score++;
            }
        }

        return {
            entryId: slotId,
            selected: cardId,
            correct,
            firstTry,
            presented: page.cards.map((card) => card.entryId),
            pageComplete: this.pageComplete,
        };
    }

    nextPage(): void {
        if (this.pageComplete) {
            this.pageIndex++;
        }
    }
}

export function pairRounds(rounds: Round[]): PairRound[] {
    return rounds.filter((round): round is PairRound => round.shape === 'pair');
}
