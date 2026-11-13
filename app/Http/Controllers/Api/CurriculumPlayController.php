<?php

namespace App\Http\Controllers\Api;

use App\Curriculum\PlayCounts;
use App\Games\GameRegistry;
use App\Http\Controllers\Controller;
use App\Models\CurriculumRef;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * 教材試玩的計次（docs/SPEC.md S-06、A-04）：播放器在按下「開始」與玩完時各送一次。
 * 只累計每課、每個遊戲、每天的次數，不存 IP，也不設 cookie；最壞的情況是數字被灌高，不影響其他資料。
 * 用課文句子玩的（unit 為 sentence，例如轉盤）與用詞彙玩的分開算。
 */
class CurriculumPlayController extends Controller
{
    public function __construct(private GameRegistry $games) {}

    public function store(Request $request, string $language, int $volume, int $lesson): Response
    {
        $data = $request->validate([
            'game' => ['required', 'string', Rule::in(array_keys($this->games->all()))],
            'event' => ['required', Rule::in(array_keys(PlayCounts::EVENTS))],
            'unit' => ['nullable', Rule::in(['word', 'sentence'])],
        ]);
        $unit = $data['unit'] ?? 'word';

        // 和試玩頁相同：語言要開放，這一課要有教材題組（用句子玩的要有句子題組）
        $ref = CurriculumRef::query()
            ->where(['language_code' => $language, 'volume' => $volume, 'lesson' => $lesson])
            ->whereNotNull('set_id')
            ->when($unit === 'sentence', fn ($query) => $query->whereNotNull('sentence_set_id'))
            ->whereHas('language', fn ($query) => $query->where('enabled', true))
            ->firstOrFail();

        PlayCounts::record($ref, $data['game'], $data['event'], $unit);

        return response()->noContent();
    }
}
