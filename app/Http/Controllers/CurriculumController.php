<?php

namespace App\Http\Controllers;

use App\Corpus\ActivityPlayback;
use App\Corpus\SetEditorData;
use App\Curriculum\Textbook;
use App\Curriculum\TextbookData;
use App\Games\GameRegistry;
use App\Models\Activity;
use App\Models\CurriculumRef;
use App\Models\Language;
use App\Models\Media;
use App\Models\Set;
use App\Models\SetEntry;
use App\Support\Licenses;
use App\Support\PageMeta;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 教材（docs/SPEC.md T-18、S-06）：依語言、冊、課瀏覽教材的詞彙，直接試玩一課。不需登入；
 * 老師登入後另外可以直接用教材題組建立活動，或複製、挑詞做成自己的題組。
 *
 * 首頁與教材頁除了 Inertia 的頁面資料，也在伺服器端輸出骨架（resources/views/skeletons/）與
 * <head> 的標題、描述（App\Support\PageMeta），搜尋引擎與連結預覽不必執行 JS 就讀得到。
 */
class CurriculumController extends Controller
{
    public function __construct(private GameRegistry $games) {}

    /**
     * 首頁：對象是老師，直接列出教材的課表，不登入就能玩（docs/SPEC.md S-06）。
     */
    public function home(Request $request): Response
    {
        $catalog = $this->catalog($request);
        $names = $catalog['languages']->pluck('name_zh')->join('、');

        return Inertia::render('Welcome', [
            ...Arr::except($catalog, 'default'),
            'meta' => PageMeta::make(
                '新住民語文教材的詞彙遊戲',
                "國教署《新住民語文學習教材》{$names}各冊各課的詞彙，配上插圖，選一課就能直接玩選擇題、迷宮、配對、字卡等遊戲。老師登入後可以用同一課建立活動，取得給學生的連結、QR code 與成績。",
                route('home', $catalog['default'] ? [] : ['language' => $catalog['language']]),
            ),
        ])->withViewData(['skeleton' => 'skeletons.catalog', 'languageRoute' => 'home']);
    }

    public function index(Request $request): Response
    {
        $catalog = $this->catalog($request);
        $name = $catalog['languages']->firstWhere('code', $catalog['language'])->name_zh ?? '';

        return Inertia::render('curriculum/Index', [
            ...Arr::except($catalog, 'default'),
            'meta' => PageMeta::make(
                "{$name}教材",
                "國教署《新住民語文學習教材》{$name}各冊各課的詞彙，配上自製的插圖。每一課都能直接玩選擇題、迷宮、配對、字卡等遊戲，老師也可以用它建立活動。",
                route('curriculum.index', ['language' => $catalog['language']]),
            ),
        ])->withViewData(['skeleton' => 'skeletons.catalog', 'languageRoute' => 'curriculum.index']);
    }

    public function show(Request $request, string $language, int $volume, int $lesson): Response
    {
        $ref = $this->lesson($language, $volume, $lesson);
        $set = $ref->set;
        $user = $request->user();
        $words = $set === null ? [] : SetEditorData::entries($set);
        // 共備庫中對應這一課的題組：共備庫要登入（docs/SPEC.md 3.2），訪客只看得到數量
        $shared = Set::query()
            ->listed()
            ->whereHas('curriculumRefs', fn ($query) => $query->whereKey($ref->id))
            ->when($set, fn ($query) => $query->whereKeyNot($set->id));

        return Inertia::render('curriculum/Lesson', [
            'lesson' => [
                'language' => ['code' => $ref->language_code, 'name_zh' => $ref->language->name_zh],
                'volume' => $ref->volume,
                'lesson' => $ref->lesson,
                'title_zh' => $ref->title_zh,
                'title_native' => $ref->title_native,
            ],
            'set' => $set === null ? null : [
                'id' => $set->id,
                'title' => $set->title,
                'description' => $set->description,
                'license' => $set->license,
                'license_name' => Licenses::name($set->license),
                'license_url' => Licenses::url($set->license),
                'authors' => array_column($set->effectiveAuthors(), 'name'),
                'can' => [
                    'activity' => Gate::allows('createActivity', $set),
                    'copy' => Gate::allows('copy', $set),
                    'edit' => Gate::allows('edit', $set),
                    'export' => $user !== null && $set->current_revision_id !== null && Gate::allows('export', $set),
                ],
            ],
            'words' => $words,
            'content' => $set === null ? null : SetEditorData::currentContent($set),
            'games' => array_values($this->games->all()),
            'imageCredits' => $set === null ? [] : $this->imageCredits($set),
            // 自己用這一課建立的活動；其他老師的活動不列出（活動連結本身就是存取憑證，3.4）
            'activities' => $set === null || $user === null ? [] : $set->activities()->where('owner_id', $user->id)->withCount('attempts')->latest()->get()
                ->map(fn (Activity $activity) => [
                    'id' => $activity->id,
                    'game' => $this->games->title($activity->game_id),
                    'attempts_count' => $activity->attempts_count,
                    'created_at' => $activity->created_at?->toIso8601String(),
                ]),
            'mySets' => $user === null ? [] : $user->sets()->whereHas('curriculumRefs', fn ($query) => $query->whereKey($ref->id))->latest('updated_at')->get(['id', 'title']),
            'shared' => $user === null ? [] : (clone $shared)
                ->with('owner:id,name')
                ->withCount('entries')
                ->latest('updated_at')
                ->limit(12)
                ->get()
                ->map(fn (Set $item) => [
                    'id' => $item->id,
                    'kind' => $item->kind,
                    'title' => $item->title,
                    'owner' => $item->owner->name,
                    'entries_count' => $item->entries_count,
                ]),
            'sharedCount' => $shared->count(),
            'libraryUrl' => route('library', ['language' => $ref->language_code, 'volume' => $ref->volume, 'lesson' => $ref->lesson]),
            'meta' => PageMeta::make(
                $ref->language->name_zh.Textbook::title($ref),
                $this->describe($ref, $words),
                Textbook::lessonUrl($ref),
                ($image = collect($words)->pluck('item.image.url')->filter()->first()) ? url($image) : null,
            ),
        ])->withViewData(['skeleton' => 'skeletons.lesson']);
    }

    /**
     * 不經過活動直接玩教材的一課（docs/SPEC.md S-06）。和老師的「建立前預覽」一樣直接帶入播放格式，
     * 用遊戲的預設設定；不建立活動，也不留作答紀錄，只累計這一課、這個遊戲的次數（App\Curriculum\PlayCounts）。
     */
    public function play(string $language, int $volume, int $lesson, string $game): View
    {
        $ref = $this->lesson($language, $volume, $lesson);
        $info = $this->games->find($game);
        $set = $ref->set;
        $revision = $set?->currentRevision;
        abort_if($info === null || $set === null || $revision === null, 404);

        $activity = $set->activities()->make([
            'game_id' => $info['id'],
            'game_version' => $info['version'],
            'options' => $this->games->withDefaults($info['id'], []),
            'mode' => 'practice',
        ]);
        $activity->id = $activity->newUniqueId();

        return view('player', [
            'activity' => $activity,
            'preview' => false,
            'trial' => true,
            'backUrl' => Textbook::lessonUrl($ref),
            // 人氣統計（A-04）：播放器在開始與玩完時各送一次
            'playsUrl' => route('api.curriculum.plays', ['language' => $ref->language_code, 'volume' => $ref->volume, 'lesson' => $ref->lesson], false),
            'playback' => ActivityPlayback::payload($activity, $revision),
        ]);
    }

    /**
     * 首頁與教材頁的課表。只列出已經匯入詞彙的語言；沒有指定語言時，選第一個匯入的語言（default 為 true）。
     *
     * @return array{languages: Collection<int, Language>, language: string, default: bool, lessons: list<array<string, mixed>>}
     */
    private function catalog(Request $request): array
    {
        $imported = CurriculumRef::whereNotNull('set_id');
        $languages = Language::enabled()
            ->whereIn('code', (clone $imported)->select('language_code'))
            ->get(['code', 'name_zh', 'name_native']);
        $codes = array_map('strval', $languages->modelKeys());
        $default = (clone $imported)->whereIn('language_code', $codes)->orderBy('id')->value('language_code') ?? '';
        $language = in_array($request->query('language'), $codes, true) ? (string) $request->query('language') : $default;

        return [
            'languages' => $languages,
            'language' => $language,
            'default' => $language === $default,
            'lessons' => TextbookData::lessons($language),
        ];
    }

    private function lesson(string $language, int $volume, int $lesson): CurriculumRef
    {
        return CurriculumRef::query()
            ->where(['language_code' => $language, 'volume' => $volume, 'lesson' => $lesson])
            ->whereHas('language', fn ($query) => $query->where('enabled', true))
            ->with('language')
            ->firstOrFail();
    }

    /**
     * 例：Keluarga Saya（我的家人）的 6 個詞：ayah 爸爸、ibu 媽媽……
     *
     * @param  array<int, array<string, mixed>>  $words  SetEditorData::entries()
     */
    private function describe(CurriculumRef $ref, array $words): string
    {
        $name = $ref->title_native && $ref->title_zh ? "{$ref->title_native}（{$ref->title_zh}）" : ($ref->title_native ?? $ref->title_zh ?? Textbook::title($ref));
        if ($words === []) {
            return "{$name}：這一課還沒有匯入詞彙。";
        }
        $list = collect($words)->map(fn (array $word) => $word['item']['text'].' '.$word['item']['translation_zh'])->join('、');

        return "{$name}的 ".count($words)." 個詞：{$list}。不必登入就能直接玩選擇題、迷宮、配對、字卡等遊戲，老師也可以用這一課建立活動。";
    }

    /**
     * 插圖的署名，例：Kancil Quiz，AI 生成（CC BY 4.0）。相同的署名只列一次。
     *
     * @return list<string>
     */
    private function imageCredits(Set $set): array
    {
        return array_values($set->loadMissing('entries.item.media')->entries
            ->flatMap(fn (SetEntry $entry) => $entry->item->media ?? [])
            ->filter(fn (Media $media) => $media->kind === 'image')
            ->map(fn (Media $media) => implode('，', array_filter([
                implode('、', array_column($media->authors ?? [], 'name')),
                $media->source,
            ])).($media->license ? '（'.Licenses::name($media->license).'）' : ''))
            ->filter(fn (string $credit) => $credit !== '')
            ->unique()
            ->all());
    }
}
