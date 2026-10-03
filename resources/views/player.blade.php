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
</body>
</html>
