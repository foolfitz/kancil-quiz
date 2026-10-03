import {
    IncompatibleSetError,
    buildRounds,
    createRng,
    randomSeed,
} from '@kancil-quiz/deck';
import type {
    Face,
    GameEvent,
    GameInstance,
    GameModule,
    Round,
} from '@kancil-quiz/games-sdk';
import type { KancilActivity } from '@kancil-quiz/schema';
import { ApiError, PlayerApi } from './api';
import type { AttemptSession, ResponseRecord } from './api';
import { AudioHost } from './audio';
import {
    answerFace,
    computeResults,
    presentedOptions,
    pronunciation,
    questionFace,
} from './results';
import type { Results } from './results';
import './style.css';

export { PlayerApi } from './api';
export { computeResults } from './results';

// 遊戲宿主（docs/SPEC.md 7.2）：載入活動、轉換題目、解鎖音訊、掛載遊戲、收集作答並顯示結果。
// 學生端要盡量輕量，所以不用 Vue；所有樣式限定在 .kq-player 之下。

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyGame = GameModule<any>;

export interface PlayerConfig {
    root: HTMLElement;
    activityId: string;
    apiBase: string;
    games: Record<string, () => Promise<AnyGame>>;
    // 預覽模式：不建立作答紀錄
    preview?: boolean;
}

function el<K extends keyof HTMLElementTagNameMap>(
    tag: K,
    className: string,
    text?: string,
): HTMLElementTagNameMap[K] {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== undefined) {
        node.textContent = text;
    }
    return node;
}

function button(className: string, text: string, onClick: () => void) {
    const node = el('button', `kq-player__button ${className}`, text);
    node.type = 'button';
    node.addEventListener('click', onClick);
    return node;
}

function faceText(face: Face): string {
    return [face.text, face.romanization].filter(Boolean).join(' ');
}

export async function startPlayer(config: PlayerConfig): Promise<void> {
    const api = new PlayerApi(config.apiBase);
    const audio = new AudioHost();
    const root = el('div', 'kq-player');
    config.root.replaceChildren(root);

    const show = (...children: HTMLElement[]) =>
        root.replaceChildren(...children);
    const message = (title: string, detail?: string) => {
        const box = el('div', 'kq-player__screen');
        box.append(el('h1', 'kq-player__title', title));
        if (detail) {
            box.append(el('p', 'kq-player__detail', detail));
        }
        show(box);
    };

    message('載入中…');

    let activity: KancilActivity;
    let game: AnyGame;
    try {
        activity = await api.activity(config.activityId);
        const load = config.games[activity.game.id];
        if (!load) {
            throw new Error(`找不到遊戲：${activity.game.id}`);
        }
        game = await load();
    } catch (error) {
        message(
            '無法開啟這個活動',
            error instanceof ApiError && error.status === 404
                ? '連結可能有誤，或活動已被刪除。'
                : '請檢查網路連線後重新整理。',
        );
        return;
    }

    root.lang = activity.set.language;
    document.title = `${activity.set.title}｜${game.title['zh-TW']}`;

    const opensAt = activity.opens_at ? new Date(activity.opens_at) : null;
    const closesAt = activity.closes_at ? new Date(activity.closes_at) : null;
    if (
        (opensAt && opensAt > new Date()) ||
        (closesAt && closesAt < new Date())
    ) {
        message(
            activity.set.title,
            opensAt && opensAt > new Date()
                ? `這個活動在 ${opensAt.toLocaleString('zh-TW')} 開放。`
                : '這個活動已經截止。',
        );
        return;
    }

    const startScreen = () => {
        const box = el('div', 'kq-player__screen');
        box.append(
            el('p', 'kq-player__game', game.title['zh-TW']),
            el('h1', 'kq-player__title', activity.set.title),
            el(
                'p',
                'kq-player__detail',
                `共 ${activity.set.entries.length} 題`,
            ),
            button('kq-player__start', '開始', () => void play()),
        );
        if (config.preview) {
            box.append(
                el('p', 'kq-player__note', '預覽模式：不會留下作答紀錄'),
            );
        }
        show(box);
    };

    async function play(): Promise<void> {
        // 「開始」是使用者手勢，在這裡解鎖 iOS 的音訊播放；開始之前不播放任何聲音。
        audio.unlock();

        const seed = randomSeed();
        let rounds: Round[];
        try {
            rounds = buildRounds(activity.set, game.requires, {
                rng: createRng(seed),
            });
        } catch (error) {
            message(
                '此活動暫時無法遊玩',
                error instanceof IncompatibleSetError
                    ? '題組與遊戲不相容，請通知老師。'
                    : '請通知老師。',
            );
            return;
        }

        let attempt: AttemptSession | null = null;
        if (!config.preview) {
            try {
                const started = await api.start(activity.id, {
                    set_revision_id: activity.set_revision_id,
                    seed,
                    round_count: rounds.length,
                });
                attempt = api.attempt(started.attempt_id, started.token);
            } catch {
                // 無法建立作答紀錄時仍然讓學生玩，結果在本機判定。
                attempt = null;
            }
        }

        const byEntry = new Map(rounds.map((round) => [round.entryId, round]));
        const responses: ResponseRecord[] = [];
        audio.preload(rounds.map((round) => questionFace(round).audio));

        const stage = el('div', 'kq-player__stage');
        const toolbar = el('div', 'kq-player__toolbar');
        const muteButton = button('kq-player__mute', '🔊 聲音：開', () => {
            audio.muted = !audio.muted;
            if (audio.muted) {
                audio.stopAll();
            }
            muteButton.textContent = audio.muted
                ? '🔇 聲音：關'
                : '🔊 聲音：開';
        });
        toolbar.append(muteButton);
        const gameArea = el('div', 'kq-player__game-area');
        stage.append(toolbar, gameArea);
        show(stage);

        let instance: GameInstance | null = null;
        let finished = false;
        const onHide = () => {
            if (document.hidden) {
                instance?.pause?.();
                attempt?.beacon();
            }
        };
        document.addEventListener('visibilitychange', onHide);

        const emit = (event: GameEvent) => {
            if (event.type === 'answered' || event.type === 'viewed') {
                const round = byEntry.get(event.entryId);
                if (!round) {
                    return;
                }
                const record: ResponseRecord =
                    event.type === 'answered'
                        ? {
                              entry_id: event.entryId,
                              presented: presentedOptions(round, rounds),
                              selected: event.selected,
                              client_correct: event.correct,
                              duration_ms: event.durationMs,
                          }
                        : {
                              entry_id: event.entryId,
                              presented: [],
                              selected: null,
                              client_correct: null,
                              duration_ms: null,
                          };
                responses.push(record);
                attempt?.record(record);
            } else if (event.type === 'completed' && !finished) {
                finished = true;
                // 遊戲在自己的事件處理中呼叫 emit，等它返回後再卸載。
                setTimeout(() => {
                    instance?.destroy();
                    document.removeEventListener('visibilitychange', onHide);
                    void finish(event.gameScore, event.durationMs);
                });
            }
        };

        instance = game.mount(gameArea, {
            rounds,
            options: { ...game.defaultOptions, ...activity.game.options },
            language: activity.set.language,
            uiLocale: 'zh-TW',
            rng: createRng(seed ^ 0x9e3779b9),
            audio: {
                play: (url) => audio.play(url),
                stopAll: () => audio.stopAll(),
            },
            emit,
        });

        async function finish(
            gameScore: number | undefined,
            durationMs: number,
        ): Promise<void> {
            message('計算成績中…');
            let serverResults;
            let uploaded = attempt === null && config.preview === true;
            if (attempt) {
                try {
                    serverResults = (
                        await attempt.complete({
                            game_score: gameScore,
                            duration_ms: durationMs,
                        })
                    ).results;
                    uploaded = true;
                } catch {
                    uploaded = false;
                }
            }
            showResults(
                computeResults(activity.set, rounds, responses, serverResults),
                uploaded,
            );
        }
    }

    function showResults(results: Results, uploaded: boolean): void {
        const box = el('div', 'kq-player__screen kq-player__results');
        const total = results.rounds.length;

        if (results.correctCount === null) {
            const seen = results.rounds.filter((r) => r.answered).length;
            box.append(
                el('h1', 'kq-player__title', `看過 ${seen} / ${total} 張`),
            );
        } else {
            box.append(
                el(
                    'h1',
                    'kq-player__title',
                    `答對 ${results.correctCount} / ${total} 題`,
                ),
            );
        }

        if (!uploaded) {
            box.append(
                el(
                    'p',
                    'kq-player__note',
                    '成績沒有上傳成功（可能是網路中斷），以上是這台裝置的計算結果。',
                ),
            );
        }

        const wrong = results.rounds.filter((r) => r.correct === false);
        if (wrong.length > 0) {
            box.append(el('h2', 'kq-player__subtitle', '再複習一下'));
            const list = el('ul', 'kq-player__review');
            for (const result of wrong) {
                const item = el('li', 'kq-player__review-item');
                const question = questionFace(result.round);
                const answer = answerFace(result.round);
                if (question.image) {
                    const img = el('img', 'kq-player__review-image');
                    img.src = question.image;
                    img.alt = question.text ?? '';
                    item.append(img);
                }
                const text = el('div', 'kq-player__review-text');
                text.append(
                    el(
                        'span',
                        'kq-player__review-question',
                        faceText(question) || '（圖片題）',
                    ),
                    el(
                        'span',
                        'kq-player__review-answer',
                        `答案：${faceText(answer) || '（圖片）'}`,
                    ),
                );
                if (!result.answered) {
                    text.append(
                        el('span', 'kq-player__review-skip', '沒有作答'),
                    );
                }
                item.append(text);
                const sound = pronunciation(activity.set, result.round.entryId);
                if (sound) {
                    item.append(
                        button(
                            'kq-player__play',
                            '🔊',
                            () => void audio.play(sound).catch(() => undefined),
                        ),
                    );
                }
                list.append(item);
            }
            box.append(list);
        }

        box.append(button('kq-player__start', '再玩一次', () => void play()));
        show(box);
    }

    startScreen();
}
