import { startPlayer } from '@kancil-quiz/player';

// 學生端播放頁的獨立 Vite 入口（docs/SPEC.md 10.2）。遊戲以動態載入，只下載用到的那一個。
const root = document.getElementById('player');

if (root?.dataset.activity) {
    void startPlayer({
        root,
        activityId: root.dataset.activity,
        apiBase: '/api/v1',
        preview: root.dataset.preview === '1',
        games: {
            'maze-chase': () =>
                import('@kancil-quiz/game-maze-chase').then((m) => m.default),
            quiz: () => import('@kancil-quiz/game-quiz').then((m) => m.default),
        },
    });
}
