<?php

namespace Database\Factories;

use App\Models\Set;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Set>
 */
class SetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'vocab',
            'title' => '水果',
            'language_code' => 'vi',
            'owner_id' => User::factory(),
            'faces' => ['prompt' => ['translation_zh'], 'answer' => ['text']],
            'license' => 'CC-BY-4.0',
        ];
    }

    public function quiz(): static
    {
        return $this->state(fn () => ['kind' => 'quiz', 'title' => '打招呼', 'faces' => null]);
    }
}
