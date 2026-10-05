<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                // 有 JS 時藏起伺服器輸出的骨架（resources/views/skeletons/），等 Vue 掛上
                document.documentElement.classList.add('js');

                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }

            .js .kq-skeleton {
                display: none;
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            @include('partials.page-meta', ['meta' => $page['props']['meta'] ?? null])
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        {{-- 公開頁面（docs/SPEC.md S-06）沒有 SSR 時：與 <x-inertia::app /> 相同，只是在 #app 中先放骨架，給不執行 JS 的程式看。
             Vue 掛上時會清空 #app（沒有 data-server-rendered，不做 hydration）。正式環境不跑 SSR；
             本機開著 Vite 開發伺服器時 Inertia 會自動 SSR，就照常用 SSR 的結果。 --}}
        @if (isset($skeleton) && app(Inertia\Ssr\SsrState::class)->dispatch() === null)
            <script data-page="app" type="application/json">{!! json_encode($page, JSON_HEX_TAG) !!}</script>
            <div id="app">@include($skeleton)</div>
        @else
            <x-inertia::app />
        @endif
    </body>
</html>
