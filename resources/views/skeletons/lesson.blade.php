{{-- 教材一課的骨架：給不執行 JS 的程式看，Vue 掛上後整個換掉（resources/views/app.blade.php） --}}
@php($props = $page['props'])
@php($lesson = $props['lesson'])
<div class="kq-skeleton">
    <p>
        <a href="{{ route('curriculum.index', ['language' => $lesson['language']['code']]) }}">{{ $lesson['language']['name_zh'] }}教材</a>
        第 {{ $lesson['volume'] }} 冊第 {{ $lesson['lesson'] }} 課
    </p>
    <h1>
        @if ($lesson['title_native'])<span lang="{{ $lesson['language']['code'] }}">{{ $lesson['title_native'] }}</span>@endif
        {{ $lesson['title_zh'] }}
    </h1>
    @if (count($props['words']) > 0)
        <h2>詞彙（{{ count($props['words']) }} 個）</h2>
        <ul>
            @foreach ($props['words'] as $word)
                <li><span lang="{{ $lesson['language']['code'] }}">{{ $word['item']['text'] }}</span>：{{ $word['item']['translation_zh'] }}</li>
            @endforeach
        </ul>
        <p>{{ $props['set']['description'] ?? '' }} 授權：{{ $props['set']['license'] ?? '' }}。教材來源：國教署<a href="https://mkm.k12ea.gov.tw/textbook">新住民子女教育資訊網</a>。</p>
    @else
        <p>這一課還沒有匯入詞彙。</p>
    @endif
</div>
