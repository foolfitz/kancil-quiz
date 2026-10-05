import type { KancilActivity } from '@kancil-quiz/schema';

// 學生端 API（docs/SPEC.md 10.3）。學生不登入，以開始作答時拿到的 token 識別。

export interface ResponseRecord {
    entry_id: string;
    presented: string[];
    selected: string[] | null;
    client_correct: boolean | null;
    duration_ms: number | null;
}

export interface CompleteResult {
    correct_count: number | null;
    round_count: number;
    results: { entry_id: string; correct: boolean | null }[];
}

export class ApiError extends Error {
    constructor(
        readonly status: number,
        message: string,
    ) {
        super(message);
    }
}

type Fetch = typeof fetch;

async function request<T>(
    fetcher: Fetch,
    url: string,
    init?: RequestInit,
): Promise<T> {
    const response = await fetcher(url, {
        ...init,
        headers: {
            Accept: 'application/json',
            ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
        },
    });
    const body = (await response.json().catch(() => ({}))) as {
        message?: string;
    };
    if (!response.ok) {
        throw new ApiError(
            response.status,
            body.message ?? response.statusText,
        );
    }
    return body as T;
}

export class PlayerApi {
    constructor(
        private readonly base: string,
        private readonly fetcher: Fetch = (...args) => fetch(...args),
    ) {}

    activity(id: string): Promise<KancilActivity> {
        return request(this.fetcher, `${this.base}/activities/${id}`);
    }

    start(
        activityId: string,
        body: {
            set_revision_id: string;
            seed: number;
            round_count: number;
            player_label?: string;
        },
    ): Promise<{ attempt_id: string; token: string }> {
        return request(
            this.fetcher,
            `${this.base}/activities/${activityId}/attempts`,
            {
                method: 'POST',
                body: JSON.stringify(body),
            },
        );
    }

    // 檢舉活動（docs/SPEC.md S-07），回傳給玩的人看的訊息
    report(activityId: string, reason: string): Promise<{ message: string }> {
        return request(
            this.fetcher,
            `${this.base}/activities/${activityId}/reports`,
            {
                method: 'POST',
                body: JSON.stringify({ reason }),
            },
        );
    }

    attempt(attemptId: string, token: string): AttemptSession {
        return new AttemptSession(this.base, attemptId, token, this.fetcher);
    }
}

// 一次作答：作答紀錄先放在佇列中，累積幾筆或結束時再批次送出；送不出去就留著下次再送。
export class AttemptSession {
    private queue: ResponseRecord[] = [];
    private sending: Promise<void> | null = null;

    constructor(
        private readonly base: string,
        readonly attemptId: string,
        private readonly token: string,
        private readonly fetcher: Fetch,
        private readonly batchSize = 5,
    ) {}

    get pending(): number {
        return this.queue.length;
    }

    record(response: ResponseRecord): void {
        this.queue.push(response);
        if (this.queue.length >= this.batchSize) {
            void this.flush().catch(() => undefined);
        }
    }

    async flush(): Promise<void> {
        await this.sending;
        if (this.queue.length === 0) {
            return;
        }
        const batch = this.queue.splice(0);
        this.sending = request<unknown>(
            this.fetcher,
            `${this.base}/attempts/${this.attemptId}/responses`,
            {
                method: 'POST',
                body: JSON.stringify({ token: this.token, responses: batch }),
            },
        )
            .then(() => undefined)
            .catch((error: unknown) => {
                this.queue.unshift(...batch);
                throw error;
            })
            .finally(() => {
                this.sending = null;
            });
        return this.sending;
    }

    async complete(body: {
        game_score?: number;
        duration_ms: number;
    }): Promise<CompleteResult> {
        await this.flush();
        return request(
            this.fetcher,
            `${this.base}/attempts/${this.attemptId}/complete`,
            {
                method: 'POST',
                body: JSON.stringify({ token: this.token, ...body }),
            },
        );
    }

    // 頁面關閉時盡量把剩下的作答送出（不等待回應）。
    beacon(): void {
        if (this.queue.length > 0 && 'sendBeacon' in navigator) {
            const body = JSON.stringify({
                token: this.token,
                responses: this.queue.splice(0),
            });
            navigator.sendBeacon(
                `${this.base}/attempts/${this.attemptId}/responses`,
                // sendBeacon 只能用 CORS 安全的 Content-Type，伺服器端會另外解析 text/plain 的 JSON
                new Blob([body], { type: 'text/plain;charset=UTF-8' }),
            );
        }
    }
}
