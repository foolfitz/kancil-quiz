import { startPlayer } from '@kancil-quiz/player';
import type { KancilActivity } from '@kancil-quiz/schema';

// 學生端播放頁的獨立 Vite 入口（docs/SPEC.md 10.2）。遊戲以動態載入，只下載用到的那一個。
const root = document.getElementById('player');
// 老師的「建立前預覽」直接帶入播放格式（resources/views/player.blade.php）
const playback = document.getElementById('kq-playback')?.textContent;

if (root?.dataset.activity) {
    void startPlayer({
        root,
        activityId: root.dataset.activity,
        activity: playback
            ? (JSON.parse(playback) as KancilActivity)
            : undefined,
        apiBase: '/api/v1',
        preview: root.dataset.preview === '1',
        games: {
            'maze-chase': () =>
                import('@kancil-quiz/game-maze-chase').then((m) => m.default),
            quiz: () => import('@kancil-quiz/game-quiz').then((m) => m.default),
            'flash-cards': () =>
                import('@kancil-quiz/game-flash-cards').then((m) => m.default),
            'match-up': () =>
                import('@kancil-quiz/game-match-up').then((m) => m.default),
        },
    });
}
