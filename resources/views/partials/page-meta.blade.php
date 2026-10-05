{{-- 公開頁面的 <head>（App\Support\PageMeta）。data-inertia 與 PageMeta.vue 的 head-key 相同，Inertia 接手後原地更新。 --}}
@if ($meta)
    <title>{{ $meta['title'] }} - {{ config('app.name') }}</title>
    <meta data-inertia="description" name="description" content="{{ $meta['description'] }}">
    <link data-inertia="canonical" rel="canonical" href="{{ $meta['url'] }}">
    <meta data-inertia="og:type" property="og:type" content="website">
    <meta data-inertia="og:title" property="og:title" content="{{ $meta['title'] }}">
    <meta data-inertia="og:description" property="og:description" content="{{ $meta['description'] }}">
    <meta data-inertia="og:url" property="og:url" content="{{ $meta['url'] }}">
    @if ($meta['image'])
        <meta data-inertia="og:image" property="og:image" content="{{ $meta['image'] }}">
    @endif
@else
    <title>{{ config('app.name', 'Laravel') }}</title>
@endif
