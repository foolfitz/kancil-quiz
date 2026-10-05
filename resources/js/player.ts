import { check, groupGames } from '@kancil-quiz/deck';
import type { GameRequirements } from '@kancil-quiz/games-sdk';
import { startPlayer } from '@kancil-quiz/player';
import type { OtherGame } from '@kancil-quiz/player';
import type { KancilActivity } from '@kancil-quiz/schema';

// 學生端播放頁的獨立 Vite 入口（docs/SPEC.md 10.2）。遊戲以動態載入，只下載用到的那一個。
const root = document.getElementById('player');
// 老師的「建立前預覽」與訪客的教材試玩直接帶入播放格式（resources/views/player.blade.php）
const playbackJson = document.getElementById('kq-playback')?.textContent;
const playback = playbackJson
    ? (JSON.parse(playbackJson) as KancilActivity)
    : undefined;

interface TrialGame extends OtherGame {
    id: string;
    requires: GameRequirements;
}

// 教材試玩：同一課的其他遊戲，只列出這一課能玩的，順序與課頁相同（docs/SPEC.md S-06、7.5）
function otherGames(): OtherGame[] | undefined {
    const json = document.getElementById('kq-trial-games')?.textContent;
    if (!json || !playback) {
        return undefined;
    }
    const games = (JSON.parse(json) as TrialGame[]).filter(
        (game) =>
            game.id !== playback.game.id &&
            check(playback.set, game.requires).ok,
    );
    return groupGames(games).flatMap((group) =>
        group.games.map(({ title, url }) => ({ title, url })),
    );
}

if (root?.dataset.activity) {
    void startPlayer({
        root,
        activityId: root.dataset.activity,
        activity: playback,
        apiBase: '/api/v1',
        preview: root.dataset.preview === '1',
        // 訪客從教材的一課直接試玩（docs/SPEC.md S-06）
        trial: root.dataset.trial === '1',
        playsUrl: root.dataset.playsUrl,
        otherGames: otherGames(),
        games: {
            'maze-quiz': () =>
                import('@kancil-quiz/game-maze-quiz').then((m) => m.default),
            quiz: () => import('@kancil-quiz/game-quiz').then((m) => m.default),
            'flash-cards': () =>
                import('@kancil-quiz/game-flash-cards').then((m) => m.default),
            'match-up': () =>
                import('@kancil-quiz/game-match-up').then((m) => m.default),
            'card-wall': () =>
                import('@kancil-quiz/game-card-wall').then((m) => m.default),
            'spin-wheel': () =>
                import('@kancil-quiz/game-spin-wheel').then((m) => m.default),
        },
    });
}
