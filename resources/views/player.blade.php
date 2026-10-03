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
    </style>
    @vite('resources/js/player.ts')
</head>
<body>
    {{-- 學生端播放頁不使用 Inertia（docs/SPEC.md 10.2） --}}
    <div id="player" data-activity="{{ $activity->id }}" data-preview="{{ $preview ? '1' : '0' }}"></div>
    @isset($playback)
        {{-- 建立前預覽：活動還不存在，直接帶入播放格式，不向 API 取得 --}}
        <script type="application/json" id="kq-playback">{!! json_encode($playback, App\Corpus\SetContent::JSON_FLAGS | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    @endisset
</body>
</html>
