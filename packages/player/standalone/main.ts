import { check } from '@kancil-quiz/deck';
import flashCards from '@kancil-quiz/game-flash-cards';
import matchUp from '@kancil-quiz/game-match-up';
import mazeQuiz from '@kancil-quiz/game-maze-quiz';
import quiz from '@kancil-quiz/game-quiz';
import type { GameModule } from '@kancil-quiz/games-sdk';
import type { KancilActivity } from '@kancil-quiz/schema';
import { ZipError, openSetZip, startPlayer } from '../src';
import type { PlayerHandle, SetZip } from '../src';
import './standalone.css';

// 獨立播放器（docs/SPEC.md O-02）：只用靜態檔案播放匯出的題組 zip，不需要伺服器，作答不會上傳。
// 開啟方式：選擇或拖入 zip 檔；或以 ?zip=<網址> 載入（例如公開題組的 /api/v1/sets/{id}/export），
// 可以再加上 &game=<遊戲 ID> 直接開始。所有遊戲都打包在同一個檔案中，離線也能用。

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyGame = GameModule<any>;

const GAMES: AnyGame[] = [quiz, mazeQuiz, flashCards, matchUp];

const LANGUAGE_NAMES: Record<string, string> = {
    id: '印尼語',
    vi: '越南語',
    ms: '馬來語',
    fil: '菲律賓語',
    th: '泰語',
    km: '柬埔寨語',
    my: '緬甸語',
};

const LICENSE_NAMES: Record<string, string> = {
    'CC-BY-4.0': 'CC BY 4.0',
    'CC-BY-SA-4.0': 'CC BY-SA 4.0',
    'CC0-1.0': 'CC0 1.0',
};

const app = document.getElementById('app') as HTMLElement;
let current: SetZip | null = null;
let player: PlayerHandle | null = null;

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

function screen(...children: HTMLElement[]): void {
    player?.destroy();
    player = null;
    document.title = 'Kancil Quiz 獨立播放器';
    const box = el('main', 'kq-standalone');
    box.append(
        el('p', 'kq-standalone__brand', 'Kancil Quiz 獨立播放器'),
        ...children,
    );
    app.replaceChildren(box);
}

function release(): void {
    for (const url of current?.urls ?? []) {
        URL.revokeObjectURL(url);
    }
    current = null;
}

function showPicker(error?: string): void {
    release();
    const title = el('h1', 'kq-standalone__title', '打開題組');
    const detail = el(
        'p',
        'kq-standalone__detail',
        '選擇從 Kancil Quiz 匯出的題組 zip 檔，或把檔案拖到這個頁面。題組只在這台裝置上播放，作答不會上傳。',
    );
    const input = el('input', 'kq-standalone__file');
    input.type = 'file';
    input.accept = '.zip,application/zip';
    input.addEventListener('change', () => {
        const file = input.files?.[0];
        if (file) {
            void open(() => file.arrayBuffer());
        }
    });
    const label = el('label', 'kq-standalone__button');
    label.append('選擇 zip 檔', input);

    const children: HTMLElement[] = [title, detail, label];
    if (error) {
        const alert = el('p', 'kq-standalone__error', error);
        alert.setAttribute('role', 'alert');
        children.push(alert);
    }
    screen(...children);
}

async function open(
    load: () => Promise<ArrayBuffer>,
    failure = '無法讀取這個檔案，請確認是從 Kancil Quiz 匯出的題組 zip 檔。',
): Promise<boolean> {
    screen(el('h1', 'kq-standalone__title', '讀取中…'));
    try {
        const zip = await openSetZip(await load());
        release();
        current = zip;
        showGames();
        return true;
    } catch (error) {
        showPicker(error instanceof ZipError ? error.message : failure);
        return false;
    }
}

function showGames(): void {
    if (!current) {
        showPicker();
        return;
    }
    const { set, license } = current;

    const facts = el('p', 'kq-standalone__detail');
    facts.textContent = [
        LANGUAGE_NAMES[set.language] ?? set.language,
        `共 ${set.entries.length} 題`,
        `作者：${set.authors.map((author) => author.name).join('、')}`,
        `授權：${LICENSE_NAMES[set.license] ?? set.license}`,
    ].join('｜');

    const list = el('ul', 'kq-standalone__games');
    for (const game of GAMES) {
        const report = check(set, game.requires);
        const item = el('li', '');
        const button = el('button', 'kq-standalone__game');
        button.type = 'button';
        button.disabled = !report.ok;
        button.append(
            el('span', 'kq-standalone__game-title', game.title['zh-TW']),
        );
        const problem = report.issues.find(
            (issue) => issue.severity === 'error',
        );
        if (problem) {
            button.append(
                el('span', 'kq-standalone__game-issue', problem.message),
            );
        }
        button.addEventListener('click', () => void play(game));
        item.append(button);
        list.append(item);
    }

    const other = el('button', 'kq-standalone__link', '換一個題組');
    other.type = 'button';
    other.addEventListener('click', () => showPicker());

    const children: HTMLElement[] = [
        el('h1', 'kq-standalone__title', set.title),
        facts,
        el('h2', 'kq-standalone__subtitle', '選一個遊戲'),
        list,
    ];
    if (license) {
        const details = el('details', 'kq-standalone__license');
        details.append(el('summary', '', '授權與出處'), el('pre', '', license));
        children.push(details);
    }
    children.push(other);
    screen(...children);
    app.querySelector<HTMLElement>('.kq-standalone__title')?.setAttribute(
        'lang',
        set.language,
    );
}

async function play(game: AnyGame): Promise<void> {
    if (!current) {
        return;
    }
    const { set } = current;
    // 獨立播放器沒有活動，以題組組出活動播放格式（6.6），使用遊戲的預設設定
    const activity: KancilActivity = {
        format: 'kancil-activity',
        version: 1,
        id: set.id,
        game: {
            id: game.id,
            version: game.version,
            options: game.defaultOptions,
        },
        mode: 'practice',
        set_revision_id: set.id,
        set,
    };

    player?.destroy();
    const area = el('div', 'kq-standalone__player');
    const back = el('button', 'kq-standalone__back', '← 換遊戲');
    back.type = 'button';
    back.addEventListener('click', showGames);
    app.replaceChildren(area, back);
    player = await startPlayer({
        root: area,
        activityId: set.id,
        apiBase: '',
        standalone: true,
        activity,
        games: { [game.id]: () => Promise.resolve(game) },
    });
}

// 拖入 zip 檔
document.addEventListener('dragover', (event) => event.preventDefault());
document.addEventListener('drop', (event) => {
    event.preventDefault();
    const file = event.dataTransfer?.files[0];
    if (file) {
        void open(() => file.arrayBuffer());
    }
});

async function boot(): Promise<void> {
    const params = new URLSearchParams(location.search);
    const url = params.get('zip');
    if (!url) {
        showPicker();
        return;
    }

    const loaded = await open(async () => {
        const response = await fetch(url);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        return response.arrayBuffer();
    }, '無法下載這個題組，請確認網址正確、題組已經公開。');
    const game = GAMES.find((g) => g.id === params.get('game'));
    if (loaded && current && game && check(current.set, game.requires).ok) {
        void play(game);
    }
}

void boot();
