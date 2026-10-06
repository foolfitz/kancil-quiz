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
import { PlayCounter } from './plays';
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
export { openSetZip } from './setZip';
export type { SetZip } from './setZip';
export { ZipError } from './zip';

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
    // 獨立播放器（O-02）：沒有伺服器，不建立作答紀錄，成績在本機判定
    standalone?: boolean;
    // 教材試玩：不經過活動直接玩教材的一課，不建立作答紀錄，成績在本機判定
    trial?: boolean;
    // 教材試玩的計次網址（人氣統計）：開始與玩完時各送一次，同一個分頁只算第一次
    playsUrl?: string;
    // 已經取得的播放格式，有的話就不向 API 取得（老師在建立活動之前預覽）
    activity?: KancilActivity;
    // 教材試玩：同一課其他能玩的遊戲，開始與結果畫面列出連結，直接換遊戲
    otherGames?: OtherGame[];
}

export interface OtherGame {
    title: string;
    url: string;
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

// 例：10/10（週六） 23:59。學生的平板應該都在台灣時區，直接用裝置的時區顯示。
function formatTime(time: Date): string {
    return time.toLocaleString('zh-TW', {
        month: 'numeric',
        day: 'numeric',
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

function faceText(face: Face): string {
    return [face.text, face.romanization].filter(Boolean).join(' ');
}

export interface PlayerHandle {
    // 結束目前的遊戲並移除播放器（獨立播放器換遊戲時使用）
    destroy(): void;
}

export async function startPlayer(config: PlayerConfig): Promise<PlayerHandle> {
    const api = new PlayerApi(config.apiBase);
    const audio = new AudioHost();
    const root = el('div', 'kq-player');
    config.root.replaceChildren(root);
    const offline =
        config.preview === true ||
        config.standalone === true ||
        config.trial === true;
    // 正在進行的遊戲；結束或換遊戲時清理
    let stopGame: (() => void) | null = null;
    const handle: PlayerHandle = {
        destroy() {
            stopGame?.();
            audio.stopAll();
            root.remove();
        },
    };

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
        activity = config.activity ?? (await api.activity(config.activityId));
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
        return handle;
    }

    root.lang = activity.set.language;
    const plays =
        config.trial && config.playsUrl
            ? new PlayCounter(config.playsUrl, activity.game.id)
            : null;
    document.title = `${activity.set.title}｜${game.title['zh-TW']}`;

    // 學生先用平板的時鐘判斷；開始作答時以伺服器為準（見 play()）。
    // 預覽不受開放時間限制，老師在開放前也能先試玩。
    const opensAt = activity.opens_at ? new Date(activity.opens_at) : null;
    const closesAt = activity.closes_at ? new Date(activity.closes_at) : null;
    if (
        !config.preview &&
        ((opensAt && opensAt > new Date()) ||
            (closesAt && closesAt < new Date()))
    ) {
        message(
            activity.set.title,
            opensAt && opensAt > new Date()
                ? `這個活動在 ${formatTime(opensAt)} 開放。`
                : '這個活動已經截止。',
        );
        return handle;
    }

    // 要不要先輸入名字或座號（docs/SPEC.md 3.4、S-04）。
    // 名字只存在這一頁的記憶體中：教室的平板常是多位學生輪流用，記住上一位的名字容易交錯。
    const needsLabel = activity.mode === 'assignment';
    let playerLabel = '';

    const startScreen = (error?: string) => {
        const box = el('div', 'kq-player__screen');
        box.append(
            el('p', 'kq-player__game', game.title['zh-TW']),
            el('h1', 'kq-player__title', activity.set.title),
            el(
                'p',
                'kq-player__detail',
                `共 ${activity.set.entries.length} 題`,
            ),
        );
        if (closesAt) {
            box.append(
                el('p', 'kq-player__detail', `${formatTime(closesAt)} 截止`),
            );
        }

        if (needsLabel) {
            const form = el('form', 'kq-player__label');
            const label = el(
                'label',
                'kq-player__label-text',
                '你的名字或座號',
            );
            const input = el('input', 'kq-player__label-input');
            input.id = 'kq-player-label';
            label.htmlFor = input.id;
            input.type = 'text';
            input.maxLength = 20;
            input.autocomplete = 'off';
            input.spellcheck = false;
            input.enterKeyHint = 'go';
            input.setAttribute('autocapitalize', 'off');
            input.value = playerLabel;
            const start = el(
                'button',
                'kq-player__button kq-player__start',
                '開始',
            );
            start.type = 'submit';
            // 預覽不留紀錄，不填也能開始
            const sync = () => {
                start.disabled = !config.preview && input.value.trim() === '';
            };
            input.addEventListener('input', sync);
            sync();
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                if (start.disabled) {
                    return;
                }
                playerLabel = input.value.trim();
                void play();
            });
            form.append(label, input);
            if (error) {
                form.append(el('p', 'kq-player__error', error));
            }
            form.append(start);
            box.append(form);
        } else {
            box.append(button('kq-player__start', '開始', () => void play()));
        }

        if (config.preview) {
            box.append(
                el('p', 'kq-player__note', '預覽模式：不會留下作答紀錄'),
            );
        }
        appendOtherGames(box);
        appendReportLink(box, () => startScreen());
        show(box);
    };

    function appendOtherGames(box: HTMLElement): void {
        if (!config.otherGames?.length) {
            return;
        }
        const nav = el('nav', 'kq-player__other-games');
        nav.setAttribute('aria-label', '換一個遊戲');
        nav.append(el('p', 'kq-player__detail', '換一個遊戲'));
        const list = el('ul', 'kq-player__other-games-list');
        for (const other of config.otherGames) {
            const item = el('li', '');
            const link = el('a', 'kq-player__other-game', other.title);
            link.href = other.url;
            item.append(link);
            list.append(item);
        }
        nav.append(list);
        box.append(nav);
    }

    // 檢舉（docs/SPEC.md S-07）：只有老師建立的活動有。預覽、試玩與獨立播放器沒有對外的活動連結。
    function appendReportLink(box: HTMLElement, back: () => void): void {
        if (!offline) {
            box.append(
                button('kq-player__report-link', '檢舉這個活動', () =>
                    reportScreen(back),
                ),
            );
        }
    }

    function reportScreen(back: () => void): void {
        const box = el('div', 'kq-player__screen');
        const form = el('form', 'kq-player__report');
        const label = el(
            'label',
            'kq-player__label-text',
            '這個活動有什麼問題？',
        );
        const input = el('textarea', 'kq-player__report-input');
        input.id = 'kq-player-report';
        label.htmlFor = input.id;
        input.maxLength = 1000;
        const error = el('p', 'kq-player__error');
        error.hidden = true;
        const submit = el(
            'button',
            'kq-player__button kq-player__start',
            '送出',
        );
        submit.type = 'submit';
        form.append(
            label,
            el(
                'p',
                'kq-player__note',
                '例如內容不適合學生、侵犯他人權利。只會送給網站管理員，不會記錄你是誰。',
            ),
            input,
            error,
            submit,
            button('kq-player__secondary', '取消', back),
        );
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            if (input.value.trim() === '' || submit.disabled) {
                input.focus();
                return;
            }
            submit.disabled = true;
            api.report(activity.id, input.value.trim()).then(
                (result) => {
                    const done = el('div', 'kq-player__screen');
                    done.append(
                        el('h1', 'kq-player__title', result.message),
                        button('kq-player__start', '返回', back),
                    );
                    show(done);
                },
                (failure: unknown) => {
                    submit.disabled = false;
                    error.hidden = false;
                    error.textContent =
                        failure instanceof ApiError && failure.status === 422
                            ? failure.message
                            : failure instanceof ApiError &&
                                failure.status === 429
                              ? '送出太多次了，請稍後再試。'
                              : '沒有送出，請檢查網路後再試一次。';
                },
            );
        });
        box.append(el('h1', 'kq-player__title', '檢舉這個活動'), form);
        show(box);
        input.focus();
    }

    // 要記名的活動沒有連上伺服器時不讓學生玩：學生會以為交了作業，其實沒有紀錄。
    const failedToStart = () => {
        const box = el('div', 'kq-player__screen');
        box.append(
            el('h1', 'kq-player__title', '沒有連上伺服器'),
            el(
                'p',
                'kq-player__detail',
                '這次作答不會被記錄。請檢查網路，再試一次。',
            ),
            button('kq-player__start', '再試一次', () => void play()),
            button('kq-player__secondary', '返回', () => startScreen()),
        );
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
            if (config.trial) {
                // 試玩沒有老師可以通知；課頁只列出相容的遊戲，會到這裡通常是改了網址
                message('這一課不能用這個遊戲', '請回到這一課，換一個遊戲。');
                return;
            }
            message(
                '此活動暫時無法遊玩',
                error instanceof IncompatibleSetError
                    ? '題組與遊戲不相容，請通知老師。'
                    : '請通知老師。',
            );
            return;
        }

        plays?.count('start');

        let attempt: AttemptSession | null = null;
        if (!offline) {
            message('準備中…');
            try {
                const started = await api.start(activity.id, {
                    set_revision_id: activity.set_revision_id,
                    seed,
                    round_count: rounds.length,
                    ...(needsLabel ? { player_label: playerLabel } : {}),
                });
                attempt = api.attempt(started.attempt_id, started.token);
            } catch (error) {
                if (error instanceof ApiError && error.status === 403) {
                    // 尚未開放或已經截止：以伺服器的時間為準
                    message(activity.set.title, error.message);
                    return;
                }
                if (needsLabel) {
                    if (error instanceof ApiError && error.status === 422) {
                        startScreen(error.message);
                    } else {
                        failedToStart();
                    }
                    return;
                }
                // 不記名的活動無法建立作答紀錄時仍然讓學生玩，結果在本機判定。
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
        stopGame = () => {
            stopGame = null;
            instance?.destroy();
            instance = null;
            document.removeEventListener('visibilitychange', onHide);
        };

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
                              presented:
                                  event.presented ??
                                  presentedOptions(round, rounds),
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
                plays?.count('finish');
                // 遊戲在自己的事件處理中呼叫 emit，等它返回後再卸載。
                setTimeout(() => {
                    stopGame?.();
                    if (event.replay) {
                        void replay(event.gameScore, event.durationMs);
                    } else {
                        void finish(event.gameScore, event.durationMs);
                    }
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

        // 玩的人在遊戲中按了「再玩一次」（例如字卡的最後一張）：結束這次作答，不顯示結果。
        // 要輸入名字的活動回到開始畫面，名字已經填好，輪流用平板時也可以換人；其他的直接重新開始。
        async function replay(
            gameScore: number | undefined,
            durationMs: number,
        ): Promise<void> {
            if (attempt) {
                message('準備中…');
                // 這類遊戲不計分，上傳失敗最多少一筆看過的紀錄，不擋住下一次
                await attempt
                    .complete({
                        game_score: gameScore,
                        duration_ms: durationMs,
                    })
                    .catch(() => undefined);
            }
            if (needsLabel) {
                startScreen();
            } else {
                void play();
            }
        }

        async function finish(
            gameScore: number | undefined,
            durationMs: number,
        ): Promise<void> {
            message('計算成績中…');
            let serverResults;
            // 預覽、獨立播放器與教材試玩本來就不上傳，不必提示
            let uploaded = attempt === null && offline;
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
                gameScore,
            );
        }
    }

    function showResults(
        results: Results,
        uploaded: boolean,
        gameScore: number | undefined,
    ): void {
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

        // 遊戲自己的得分（docs/SPEC.md 7.4）：只有給了名稱（scoreLabel）的遊戲才顯示，例如打地鼠的「星星」；
        // 選擇題、配對、迷宮的得分就是答對題數，不另外顯示。不是成績，放在答對題數的下面
        const scoreLabel = game.scoreLabel?.['zh-TW'];
        if (scoreLabel !== undefined && typeof gameScore === 'number') {
            const score = el('p', 'kq-player__score');
            score.append(
                el('span', 'kq-player__score-label', scoreLabel),
                ' ',
                el('span', 'kq-player__score-value', String(gameScore)),
            );
            box.append(score);
        }

        if (needsLabel && playerLabel) {
            box.prepend(el('p', 'kq-player__game', playerLabel));
        }

        if (!uploaded) {
            box.append(
                el(
                    'p',
                    'kq-player__note',
                    needsLabel
                        ? '成績沒有上傳成功，請告訴老師。以上是這台裝置的計算結果。'
                        : '成績沒有上傳成功（可能是網路中斷），以上是這台裝置的計算結果。',
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
        if (needsLabel) {
            box.append(
                button('kq-player__secondary', '換人', () => {
                    playerLabel = '';
                    startScreen();
                }),
            );
        }
        appendOtherGames(box);
        appendReportLink(box, () => show(box));
        show(box);
    }

    startScreen();
    return handle;
}
