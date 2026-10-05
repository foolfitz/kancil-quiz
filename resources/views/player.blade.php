<!DOCTYPE html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>{{ config('app.name') }}</title>
    <style>
        html, body { margin: 0; height: 100%; }
        #player { min-height: 100%; display: flex; }
        /* 教材試玩：疊在播放器工具列的左邊（右邊是聲音開關），與獨立播放器的「換遊戲」相同 */
        .kq-trial-back {
            position: fixed; top: 0.25rem; left: 0.5rem; z-index: 10;
            display: inline-flex; align-items: center; min-height: 44px; padding: 0.25rem 0.75rem;
            color: #61707d; font-family: system-ui, -apple-system, 'Segoe UI', 'Noto Sans TC', sans-serif;
            font-size: 1rem; text-decoration: none;
        }
    </style>
    @vite('resources/js/player.ts')
</head>
<body>
    {{-- 學生端播放頁不使用 Inertia（docs/SPEC.md 10.2） --}}
    <div id="player" data-activity="{{ $activity->id }}" data-preview="{{ $preview ? '1' : '0' }}" @if ($trial ?? false) data-trial="1" @endif></div>
    @isset($backUrl)
        {{-- 教材試玩（docs/SPEC.md S-06）：回到那一課換遊戲 --}}
        <a class="kq-trial-back" href="{{ $backUrl }}">← 回到這一課</a>
    @endisset
    @isset($playback)
        {{-- 建立前預覽與教材試玩：活動不存在，直接帶入播放格式，不向 API 取得 --}}
        <script type="application/json" id="kq-playback">{!! json_encode($playback, App\Corpus\SetContent::JSON_FLAGS | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    @endisset
</body>
</html>
