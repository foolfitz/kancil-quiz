<?php

namespace App\Http\Controllers\Api;

use App\Games\GameRegistry;
use App\Grading\Judge;
use App\Grading\PlayerLabel;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Attempt;
use App\Models\AttemptResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 學生作答（docs/SPEC.md 10.3）。學生不登入，以開始作答時拿到的 token 識別；
 * 正式成績由伺服器依作答時的題組版本判定（7.4）。不存 IP。
 */
class AttemptController extends Controller
{
    public function __construct(private GameRegistry $games) {}

    public function store(Request $request, Activity $activity): JsonResponse
    {
        // 截止前開始的作答，截止後仍可以送完（responses、complete 不檢查時間）
        abort_if($activity->status() === 'scheduled', 403, '這個活動還沒開放');
        abort_if($activity->status() === 'closed', 403, '這個活動已經截止');

        if (is_string($request->input('player_label'))) {
            $request->merge(['player_label' => PlayerLabel::normalize($request->input('player_label'))]);
        }

        $data = $request->validate([
            'set_revision_id' => [
                'required', 'ulid',
                Rule::exists('set_revisions', 'id')->where('set_id', $activity->set_id),
            ],
            'seed' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'round_count' => ['required', 'integer', 'min:1', 'max:1000'],
            'player_label' => [$activity->requiresLabel() ? 'required' : 'nullable', 'string', 'max:'.PlayerLabel::MAX_LENGTH],
        ]);

        $token = Str::random(40);
        $attempt = $activity->attempts()->create([
            ...$data,
            'token_hash' => hash('sha256', $token),
            'started_at' => now(),
        ]);

        return response()->json(['attempt_id' => $attempt->id, 'token' => $token], 201);
    }

    public function responses(Request $request, Attempt $attempt): JsonResponse
    {
        // 頁面關閉時 sendBeacon 送來的是 text/plain 的 JSON
        if (! $request->isJson() && $request->getContent() !== '') {
            $request->merge((array) json_decode($request->getContent(), true));
        }

        $this->authorizeToken($request, $attempt);
        abort_if($attempt->completed_at !== null, 409, '這次作答已經結束');

        $data = $request->validate([
            'responses' => ['required', 'array', 'min:1', 'max:200'],
            'responses.*.entry_id' => ['required', 'ulid'],
            'responses.*.presented' => ['present', 'array', 'max:12'],
            'responses.*.presented.*' => ['string', 'max:64'],
            'responses.*.selected' => ['nullable', 'array', 'max:6'],
            'responses.*.selected.*' => ['string', 'max:64'],
            'responses.*.client_correct' => ['nullable', 'boolean'],
            'responses.*.duration_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $content = $attempt->revision->content();
        $shape = $this->games->shape($attempt->activity->game_id);
        $entryIds = array_map(fn ($entry) => $entry->id, $content->entries);

        $rows = [];
        foreach ($data['responses'] as $i => $response) {
            if (! in_array($response['entry_id'], $entryIds, true)) {
                throw ValidationException::withMessages(["responses.{$i}.entry_id" => '題組中沒有這一題']);
            }

            $selected = $response['selected'] ?? null;
            $rows[] = [
                'attempt_id' => $attempt->id,
                'entry_id' => $response['entry_id'],
                'presented' => json_encode($response['presented']),
                'selected' => $selected === null ? null : json_encode($selected),
                'correct' => $selected === null ? null : Judge::judge($content, $shape, $response['entry_id'], $response['presented'], $selected),
                'client_correct' => $response['client_correct'] ?? null,
                'duration_ms' => $response['duration_ms'] ?? null,
                'created_at' => now(),
            ];
        }

        AttemptResponse::insert($rows);

        return response()->json(['accepted' => count($rows)]);
    }

    public function complete(Request $request, Attempt $attempt): JsonResponse
    {
        $this->authorizeToken($request, $attempt);

        $data = $request->validate([
            'game_score' => ['nullable', 'integer'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $content = $attempt->revision->content();
        $shape = $this->games->shape($attempt->activity->game_id);
        $responses = $attempt->responses()->get();

        if ($attempt->completed_at === null) {
            $attempt->update([
                'completed_at' => now(),
                'correct_count' => Judge::countCorrect($content, $shape, $responses->map(fn (AttemptResponse $response) => [
                    'entry_id' => $response->entry_id,
                    'presented' => $response->presented,
                    'selected' => $response->selected,
                ])->all()),
                'game_score' => $data['game_score'] ?? null,
                'duration_ms' => $data['duration_ms'] ?? null,
            ]);
        }

        // 每題第一筆作答的判定，供結算畫面列出答錯的題目（S-03）。
        $results = $responses->unique('entry_id')->map(fn (AttemptResponse $response) => [
            'entry_id' => $response->entry_id,
            'correct' => $response->correct,
        ])->values();

        return response()->json([
            'correct_count' => $attempt->correct_count,
            'round_count' => $attempt->round_count,
            'results' => $results,
        ]);
    }

    private function authorizeToken(Request $request, Attempt $attempt): void
    {
        abort_unless($attempt->hasToken((string) $request->input('token')), 403, '作答憑證不正確');
    }
}
