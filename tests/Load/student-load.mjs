// 學生端 API 的壓力測試（docs/SPEC.md M1 驗收 7）：模擬一整班同時作答。
//
//   node tests/Load/student-load.mjs --base http://127.0.0.1:8123 --activity <活動 ID> [--students 30] [--rounds 10]
//
// 每位學生：載入活動 → 開始作答 → 每題作答後立即送出 → 結算。作答之間只停 0–300 ms，
// 比真實課堂密集得多。任何一個請求失敗，或作答 API 的 p95 超過 1 秒，就以非零碼結束。
import { parseArgs } from 'node:util';

const { values: args } = parseArgs({
    options: {
        base: { type: 'string', default: 'http://127.0.0.1:8123' },
        activity: { type: 'string' },
        students: { type: 'string', default: '30' },
        rounds: { type: 'string', default: '10' },
    },
});
if (!args.activity) {
    console.error('請用 --activity 指定活動 ID');
    process.exit(2);
}

const students = Number(args.students);
const rounds = Number(args.rounds);
const timings = { activity: [], start: [], responses: [], complete: [] };
const failures = [];

async function call(kind, path, body) {
    const started = performance.now();
    const response = await fetch(`${args.base}/api/v1${path}`, {
        method: body ? 'POST' : 'GET',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    timings[kind].push(performance.now() - started);
    const text = await response.text();
    if (!response.ok) {
        failures.push(`${kind} ${response.status} ${text.slice(0, 200)}`);
        throw new Error(`${kind} ${response.status}`);
    }
    return JSON.parse(text);
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function student() {
    const activity = await call('activity', `/activities/${args.activity}`);
    const entries = activity.set.entries.map((entry) => entry.id);
    const { attempt_id: attempt, token } = await call(
        'start',
        `/activities/${args.activity}/attempts`,
        {
            set_revision_id: activity.set_revision_id,
            seed: Math.floor(Math.random() * 2 ** 32),
            round_count: rounds,
        },
    );

    for (let i = 0; i < rounds; i++) {
        await sleep(Math.random() * 300);
        const entry = entries[i % entries.length];
        const presented = [
            entry,
            ...entries.filter((e) => e !== entry).slice(0, 3),
        ];
        await call('responses', `/attempts/${attempt}/responses`, {
            token,
            responses: [
                {
                    entry_id: entry,
                    presented,
                    selected: [
                        presented[Math.floor(Math.random() * presented.length)],
                    ],
                    client_correct: null,
                    duration_ms: 1000,
                },
            ],
        });
    }

    await call('complete', `/attempts/${attempt}/complete`, {
        token,
        duration_ms: 60000,
    });
}

function percentile(values, p) {
    const sorted = [...values].sort((a, b) => a - b);
    return (
        sorted[
            Math.min(
                sorted.length - 1,
                Math.ceil((p / 100) * sorted.length) - 1,
            )
        ] ?? 0
    );
}

const started = performance.now();
const results = await Promise.allSettled(
    Array.from({ length: students }, student),
);
const seconds = (performance.now() - started) / 1000;

console.log(
    `${students} 位學生，每人 ${rounds} 題，共 ${seconds.toFixed(1)} 秒`,
);
console.log('請求        次數    p50(ms)   p95(ms)   最大(ms)');
for (const [kind, values] of Object.entries(timings)) {
    console.log(
        `${kind.padEnd(10)} ${String(values.length).padStart(5)} ${percentile(values, 50).toFixed(0).padStart(10)} ${percentile(values, 95).toFixed(0).padStart(9)} ${Math.max(
            0,
            ...values,
        )
            .toFixed(0)
            .padStart(10)}`,
    );
}

const rejected = results.filter((r) => r.status === 'rejected').length;
const p95 = percentile(timings.responses, 95);
console.log(`失敗的請求：${failures.length}，未完成的學生：${rejected}`);
for (const failure of failures.slice(0, 10)) {
    console.log(`  ${failure}`);
}

const ok = failures.length === 0 && rejected === 0 && p95 < 1000;
console.log(ok ? '通過：沒有寫入失敗，作答 API 的 p95 低於 1 秒' : '未通過');
process.exit(ok ? 0 : 1);
