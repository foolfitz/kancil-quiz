<?php

namespace App\Filament\Pages;

use App\Curriculum\CurriculumImages;
use App\Curriculum\CurriculumImporter;
use App\Curriculum\Textbook;
use App\Curriculum\VolumeFile;
use App\Models\CurriculumRef;
use App\Models\Language;
use App\Models\Set as SetModel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * 匯入教材（docs/SPEC.md 3.6、A-03），只有管理員能用。兩件事各自獨立，可以先匯入詞彙、之後再補插圖：
 *
 * - 匯入詞彙：上傳一冊的 JSON（VolumeFile），先預覽每一課的結果，確認後才寫入。
 * - 上傳插圖：一次上傳多張圖，依檔名對應到這一冊的詞（CurriculumImages）。先列出配對結果，
 *   配不到或對應不唯一的圖可以手動選詞，確認後才寫入。
 *
 * @phpstan-import-type Result from CurriculumImporter
 * @phpstan-import-type Word from CurriculumImages
 * @phpstan-import-type Volume from VolumeFile
 *
 * @property-read Schema $wordsForm
 * @property-read Schema $imagesForm
 */
class ImportCurriculum extends Page
{
    protected static ?string $title = '匯入教材';

    protected static ?string $slug = 'import-curriculum';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    /** @var array<string, mixed>|null */
    public ?array $words = [];

    /** @var array<string, mixed>|null */
    public ?array $images = [];

    /** @var array{summary: string, lessons: list<Result>}|null 匯入詞彙的預覽 */
    public ?array $wordsPreview = null;

    /** @var array<string, array<string, Word>> 同一個請求中，一冊的詞只查一次 */
    private array $volumeWords = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('admin');
    }

    public function mount(): void
    {
        $this->wordsForm->fill(['language_code' => 'id', 'force' => false]);
        $this->imagesForm->fill([
            'language_code' => 'id',
            'replace' => false,
            'authors' => implode('、', array_column(Textbook::IMAGE_ATTRIBUTION['authors'], 'name')),
            'license' => Textbook::IMAGE_ATTRIBUTION['license'],
            'source' => Textbook::IMAGE_ATTRIBUTION['source'],
            'matches' => [],
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('匯入詞彙')
                ->description('上傳一冊的課名與詞彙（JSON），可以重複匯入：詞以目標語文字對應，已有的插圖、錄音與題目都會保留。資料檔中的課文不會讀取。')
                ->schema([
                    Form::make([EmbeddedSchema::make('wordsForm')])
                        ->id('words-form')
                        ->livewireSubmitHandler('previewWords')
                        ->footer([
                            Actions::make([
                                Action::make('previewWords')->label('預覽')->submit('previewWords')
                                    ->visible(fn () => $this->wordsPreview === null),
                            ]),
                        ]),
                    Group::make(fn () => $this->wordsPreviewComponents())
                        ->visible(fn () => $this->wordsPreview !== null),
                    Actions::make([
                        Action::make('importWords')->label('確認匯入')->color('success')->action(fn () => $this->importWords()),
                        Action::make('cancelWords')->label('取消')->color('gray')->action(fn () => $this->wordsPreview = null),
                    ])->visible(fn () => $this->wordsPreview !== null),
                ]),
            Section::make('上傳插圖')
                ->description('選好語言與冊，一次上傳多張圖，依檔名對應到詞：檔名轉小寫，空白與符號都當成「_」，例如 ibu_guru.png 對應 ibu guru、tidak_apa_apa.png 對應 tidak apa-apa。同一冊中好幾課都有的詞，用同一張圖。')
                ->schema([
                    Form::make([EmbeddedSchema::make('imagesForm')])
                        ->id('images-form')
                        ->livewireSubmitHandler('matchImages')
                        ->footer([
                            Actions::make([
                                Action::make('matchImages')->label('配對')->submit('matchImages')
                                    ->visible(fn () => ! $this->hasMatches()),
                            ]),
                        ]),
                    // 放在表單外：與「配對」放在同一處，Livewire 更新畫面時會把按鈕直接改成送出鈕，Alpine 會出錯
                    Actions::make([
                        Action::make('attachImages')->label('確認上傳')->color('success')->action(fn () => $this->attachImages()),
                        Action::make('cancelImages')->label('取消')->color('gray')->action(fn () => $this->resetMatches()),
                    ])->visible(fn () => $this->hasMatches()),
                ]),
        ]);
    }

    public function wordsForm(Schema $schema): Schema
    {
        $reset = fn () => $this->wordsPreview = null;

        return $schema
            ->statePath('words')
            ->components([
                Grid::make(2)->schema([
                    Select::make('language_code')->label('語言')->options(fn () => $this->languages())
                        ->required()->selectablePlaceholder(false)->live()->afterStateUpdated($reset),
                    TextInput::make('volume')->label('冊')->integer()->minValue(1)->required()
                        ->live(onBlur: true)->afterStateUpdated($reset),
                ]),
                FileUpload::make('file')->label('資料檔')
                    ->helperText('可以直接用整理教材時的「課文與詞彙.json」，或 repo 中的 volume.json。插圖在下方另外上傳。')
                    ->acceptedFileTypes(['application/json', 'text/plain'])
                    ->storeFiles(false)
                    ->required()
                    ->live()->afterStateUpdated($reset),
                Toggle::make('force')->label('覆寫審核者在網站上的修正')
                    ->helperText('預設會略過審核者在網站上修正過的課。先把修正寫回資料檔，再勾選這個選項匯入。')
                    ->live()->afterStateUpdated($reset),
            ]);
    }

    public function imagesForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('images')
            ->components([
                Grid::make(2)->schema([
                    Select::make('language_code')->label('語言')->options(fn () => $this->languages())
                        ->required()->selectablePlaceholder(false)->live()
                        ->afterStateUpdated(function (Set $set) {
                            $set('volume', null);
                            $this->resetMatches();
                        }),
                    Select::make('volume')->label('冊')
                        ->options(fn (Get $get) => CurriculumRef::query()
                            ->where('language_code', $get('language_code'))
                            ->whereNotNull('set_id')
                            ->distinct()
                            ->orderBy('volume')
                            ->pluck('volume')
                            ->mapWithKeys(fn (int $volume) => [$volume => "第 {$volume} 冊"]))
                        ->helperText('只列出已經匯入詞彙的冊。')
                        ->required()->live()->afterStateUpdated(fn () => $this->resetMatches()),
                ]),
                FileUpload::make('files')->label('插圖')
                    ->helperText('PNG、JPEG 或 WebP，每張最大 8 MB。會轉成長邊最多 1024 px 的 WebP。')
                    ->multiple()
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                    ->maxSize(8 * 1024)
                    ->storeFiles(false)
                    ->required()
                    ->live()->afterStateUpdated(fn () => $this->resetMatches()),
                Toggle::make('replace')->label('取代已有的插圖')
                    ->helperText('預設只加到還沒有插圖的詞。')
                    ->live(),
                Fieldset::make('插圖的署名')->columns(3)->schema([
                    TextInput::make('authors')->label('作者')->helperText('多位作者以「、」分隔。')->required(),
                    Select::make('license')->label('授權')->options(array_combine(SetModel::LICENSES, SetModel::LICENSES))
                        ->required()->selectablePlaceholder(false),
                    TextInput::make('source')->label('出處')->required(),
                ]),
                Repeater::make('matches')->label('配對結果')
                    ->table([
                        TableColumn::make('檔案'),
                        TableColumn::make('對應的詞'),
                        TableColumn::make('結果'),
                    ])
                    ->schema([
                        Text::make(fn (Get $get) => (string) $get('name')),
                        Select::make('word')->hiddenLabel()->placeholder('不使用')
                            ->options(fn () => array_map(
                                fn (array $word) => CurriculumImages::label($word).($word['missing'] ? '' : '，已有插圖'),
                                $this->currentWords(),
                            ))
                            ->live(),
                        Text::make(fn (Get $get) => $this->matchStatus($get)[0])
                            ->badge()
                            ->color(fn (Get $get) => $this->matchStatus($get)[1]),
                    ])
                    ->addable(false)->deletable(false)->reorderable(false)
                    ->visible(fn () => $this->hasMatches()),
                Text::make(fn () => $this->missingWordsText())->visible(fn () => $this->hasMatches()),
            ]);
    }

    public function previewWords(): void
    {
        $state = $this->wordsForm->getState();
        $volume = $this->readWords($state);
        $preview = $this->asFileErrors(fn () => app(CurriculumImporter::class)->preview($volume, (bool) $state['force']));

        $words = array_sum(array_map(fn (array $lesson) => count($lesson['vocabulary']), $volume['lessons']));
        $this->wordsPreview = [
            'summary' => "{$this->languageName($volume['language'])}第 {$volume['volume']} 冊（{$volume['textbook']}）：".count($volume['lessons'])." 課、{$words} 個詞",
            'lessons' => $preview,
        ];
    }

    public function importWords(): void
    {
        $state = $this->wordsForm->getState();
        $volume = $this->readWords($state);
        $results = $this->asFileErrors(fn () => app(CurriculumImporter::class)->importVolume($volume, null, (bool) $state['force']));

        $counts = array_count_values(array_column($results, 'result'));
        $labels = ['created' => '新增', 'updated' => '更新', 'unchanged' => '沒有變動', 'skipped' => '略過'];
        Notification::make()
            ->title("已匯入{$this->languageName($volume['language'])}第 {$volume['volume']} 冊")
            ->body(implode('、', array_map(fn (string $result, int $count) => "{$labels[$result]} {$count} 課", array_keys($counts), $counts)))
            ->success()
            ->send();

        $this->wordsPreview = null;
        $this->wordsForm->fill(['language_code' => $volume['language'], 'force' => false]);
        // 接著上傳這一冊的插圖
        $this->images['language_code'] = $volume['language'];
        $this->images['volume'] = $volume['volume'];
        $this->resetMatches();
    }

    public function matchImages(): void
    {
        $state = $this->imagesForm->getState();
        $words = $this->currentWords();

        $rows = [];
        foreach ($this->uploadedImages($state) as $id => $file) {
            $candidates = CurriculumImages::candidates($file->getClientOriginalName(), $words);
            $rows[(string) Str::uuid()] = [
                'file' => $id,
                'name' => $file->getClientOriginalName(),
                'word' => count($candidates) === 1 ? $candidates[0] : null,
                'ambiguous' => count($candidates) > 1,
            ];
        }
        $this->images['matches'] = $rows;
    }

    public function attachImages(): void
    {
        $state = $this->imagesForm->getState();
        $files = $this->uploadedImages($state);

        $chosen = [];
        foreach ($this->images['matches'] ?? [] as $row) {
            if (blank($row['word'] ?? null)) {
                continue;
            }
            if (isset($chosen[$row['word']])) {
                $this->notifyError('有兩張圖對應到同一個詞，請改成「不使用」其中一張。');

                return;
            }
            $file = $files[$row['file']] ?? null;
            if ($file === null) {
                $this->notifyError('圖檔已經變更，請重新配對。');

                return;
            }
            $chosen[$row['word']] = $file->getRealPath();
        }
        if ($chosen === []) {
            $this->notifyError('沒有選擇要上傳的插圖。');

            return;
        }

        $authors = array_values(array_filter(array_map(trim(...), preg_split('/[、,，]/u', (string) $state['authors']) ?: [])));
        try {
            $result = app(CurriculumImages::class)->attach(
                (string) $state['language_code'],
                (int) $state['volume'],
                $chosen,
                ['authors' => array_map(fn (string $name) => ['name' => $name], $authors), 'license' => (string) $state['license'], 'source' => (string) $state['source']],
                (bool) $state['replace'],
            );
        } catch (ValidationException $e) {
            $this->notifyError(implode("\n", array_merge(...array_values($e->errors()))));

            return;
        }

        Notification::make()
            ->title($result['words'] === 0 ? '這些詞都已經有插圖' : "已加上 {$result['words']} 個詞的插圖")
            ->body($result['lessons'] === [] ? '要換掉原本的插圖，請勾選「取代已有的插圖」。' : '第 '.implode('、', $result['lessons']).' 課產生了新版本。')
            ->success()
            ->send();

        $this->imagesForm->fill([...$state, 'files' => [], 'matches' => []]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return Volume
     */
    private function readWords(array $state): array
    {
        $file = $state['file'] ?? null;
        $file = is_array($file) ? reset($file) : $file;
        if (! $file instanceof TemporaryUploadedFile) {
            throw ValidationException::withMessages(['words.file' => '請上傳資料檔。']);
        }

        return $this->asFileErrors(fn () => VolumeFile::fromUpload((string) $file->get(), (string) $state['language_code'], (int) $state['volume']));
    }

    /**
     * 資料檔的錯誤顯示在檔案欄位下方。
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asFileErrors(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            $this->wordsPreview = null;
            throw ValidationException::withMessages(['words.file' => array_merge(...array_values($e->errors()))]);
        }
    }

    /**
     * @return list<Text|Flex>
     */
    private function wordsPreviewComponents(): array
    {
        $labels = ['created' => '新增', 'updated' => '更新', 'unchanged' => '沒有變動', 'skipped' => '略過：審核者在網站上修正過'];
        $colors = ['created' => 'success', 'updated' => 'info', 'unchanged' => 'gray', 'skipped' => 'warning'];

        return [
            Text::make($this->wordsPreview['summary'] ?? '')->weight('bold'),
            ...array_map(fn (array $row) => Flex::make([
                Text::make($row['title']),
                Text::make("{$row['words']} 個詞")->color('gray')->grow(false),
                Text::make($labels[$row['result']])->badge()->color($colors[$row['result']])->grow(false),
            ]), $this->wordsPreview['lessons'] ?? []),
        ];
    }

    /**
     * @return array{0: string, 1: string} 結果與顏色
     */
    private function matchStatus(Get $get): array
    {
        $key = $get('word');
        if (blank($key)) {
            return $get('ambiguous') ? ['有好幾個詞符合，請選擇', 'warning'] : ['找不到對應的詞', 'danger'];
        }

        $chosen = array_count_values(array_filter(array_map(fn (array $row) => $row['word'] ?? null, $this->images['matches'] ?? [])));
        if (($chosen[$key] ?? 0) > 1) {
            return ['和其他圖對應到同一個詞', 'danger'];
        }

        $word = $this->currentWords()[$key] ?? null;
        if ($word === null) {
            return ['找不到對應的詞', 'danger'];
        }
        if ($word['missing']) {
            return ['加上插圖', 'success'];
        }

        return $get('../../replace') ? ['取代原本的插圖', 'info'] : ['已有插圖，略過', 'gray'];
    }

    private function missingWordsText(): string
    {
        $chosen = array_filter(array_map(fn (array $row) => $row['word'] ?? null, $this->images['matches'] ?? []));
        $missing = array_filter($this->currentWords(), fn (array $word) => $word['missing'] && ! in_array($word['key'], $chosen, true));

        return $missing === []
            ? '上傳後，這一冊的詞都有插圖了。'
            : '上傳後仍沒有插圖的詞（'.count($missing).' 個）：'.implode('、', array_map(fn (array $word) => $word['text'], $missing));
    }

    /**
     * @return array<string, Word>
     */
    private function currentWords(): array
    {
        $language = (string) ($this->images['language_code'] ?? '');
        $volume = (int) ($this->images['volume'] ?? 0);

        return $this->volumeWords["{$language}/{$volume}"] ??= $volume > 0 ? CurriculumImages::words($language, $volume) : [];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, TemporaryUploadedFile> 以暫存檔名為鍵
     */
    private function uploadedImages(array $state): array
    {
        $files = [];
        foreach ((array) ($state['files'] ?? []) as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $files[$file->getFilename()] = $file;
            }
        }

        return $files;
    }

    private function hasMatches(): bool
    {
        return filled($this->images['matches'] ?? null);
    }

    private function resetMatches(): void
    {
        $this->images['matches'] = [];
    }

    private function notifyError(string $message): void
    {
        Notification::make()->title($message)->danger()->send();
    }

    /**
     * @return array<string, string>
     */
    private function languages(): array
    {
        return Language::orderBy('sort')->pluck('name_zh', 'code')->all();
    }

    private function languageName(string $code): string
    {
        return (string) Language::whereKey($code)->value('name_zh');
    }
}
