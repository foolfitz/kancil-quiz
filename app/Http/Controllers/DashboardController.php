<?php

namespace App\Http\Controllers;

use App\Games\GameRegistry;
use App\Models\Activity;
use App\Models\Set;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, GameRegistry $games): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'sets' => $user->sets()->withCount('entries')->latest('updated_at')->limit(6)->get()
                ->map(fn (Set $set) => [
                    'id' => $set->id,
                    'kind' => $set->kind,
                    'title' => $set->title,
                    'entries_count' => $set->entries_count,
                ]),
            'activities' => $user->activities()->with('set:id,title')->withCount('attempts')->latest()->limit(6)->get()
                ->map(fn (Activity $activity) => [
                    'id' => $activity->id,
                    'game' => $games->title($activity->game_id),
                    'set_title' => $activity->set?->title,
                    'attempts_count' => $activity->attempts_count,
                ]),
        ]);
    }
}
