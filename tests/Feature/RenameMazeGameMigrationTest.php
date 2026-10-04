<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Set;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 迷宮的遊戲 ID 由 maze-chase 改為 maze-quiz 的 migration（docs/SPEC.md 7.5）。
 */
class RenameMazeGameMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_renames_game_id_both_ways_including_deleted_activities(): void
    {
        $teacher = User::factory()->create();
        $set = Set::factory()->for($teacher, 'owner')->create();
        $make = fn (string $game) => Activity::create([
            'set_id' => $set->id,
            'game_id' => $game,
            'game_version' => '0.1.0',
            'options' => [],
            'owner_id' => $teacher->id,
        ]);
        $maze = $make('maze-chase');
        $deleted = $make('maze-chase');
        $deleted->delete();
        $quiz = $make('quiz');

        $migration = require database_path('migrations/2026_10_04_200000_rename_maze_chase_to_maze_quiz.php');
        $gameIds = fn () => Activity::withTrashed()->pluck('game_id', 'id')->all();

        $migration->up();
        $this->assertEquals(
            [$maze->id => 'maze-quiz', $deleted->id => 'maze-quiz', $quiz->id => 'quiz'],
            $gameIds(),
        );

        $migration->down();
        $this->assertEquals(
            [$maze->id => 'maze-chase', $deleted->id => 'maze-chase', $quiz->id => 'quiz'],
            $gameIds(),
        );
    }
}
