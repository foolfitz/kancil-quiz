{{-- 教材課表的骨架（首頁與 /curriculum）：給不執行 JS 的程式看，Vue 掛上後整個換掉（resources/views/app.blade.php） --}}
@php($props = $page['props'])
<div class="kq-skeleton">
    <h1>{{ $props['meta']['title'] }}</h1>
    <p>{{ $props['meta']['description'] }}</p>
    @if (count($props['languages']) > 1)
        <ul>
            @foreach ($props['languages'] as $language)
                <li><a href="{{ route($languageRoute, ['language' => $language['code']]) }}">{{ $language['name_zh'] }}</a></li>
            @endforeach
        </ul>
    @endif
    @foreach (collect($props['lessons'])->groupBy('volume') as $volume => $lessons)
        <h2>第 {{ $volume }} 冊</h2>
        <ul>
            @foreach ($lessons as $lesson)
                <li>
                    <a href="{{ $lesson['url'] }}">第 {{ $lesson['lesson'] }} 課
                        @if ($lesson['title_native'])<span lang="{{ $props['language'] }}">{{ $lesson['title_native'] }}</span>@endif
                        {{ $lesson['title_zh'] }}</a>
                    @if (count($lesson['words']) > 0)
                        （{{ collect($lesson['words'])->map(fn ($word) => $word['text'].' '.$word['translation_zh'])->join('、') }}）
                    @endif
                </li>
            @endforeach
        </ul>
    @endforeach
    <p>教材來源：國教署<a href="https://mkm.k12ea.gov.tw/textbook">新住民子女教育資訊網</a>的《新住民語文學習教材》。只收錄課名與詞彙，不收錄課文、教材的插圖與音檔。</p>
</div>
