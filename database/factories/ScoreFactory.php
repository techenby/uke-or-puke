<?php

namespace Database\Factories;

use App\Models\Score;
use App\Support\BeginnerLesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Score>
 */
class ScoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hits = fake()->numberBetween(0, count(BeginnerLesson::CHORDS));
        $perfect = fake()->numberBetween(0, $hits);
        $speed = fake()->randomElement(array_keys(BeginnerLesson::TEMPOS));

        return [
            'points' => $perfect * 100 + ($hits - $perfect) * 70,
            'hits' => $hits,
            'perfect' => $perfect,
            'best_streak' => fake()->numberBetween(0, $hits),
            'input_mode' => fake()->randomElement(['tap', 'microphone']),
            'speed' => $speed,
            'bpm' => BeginnerLesson::TEMPOS[$speed],
        ];
    }

    public function ukulele(): static
    {
        return $this->state(fn (array $attributes): array => ['input_mode' => 'microphone']);
    }

    public function worth(int $points): static
    {
        return $this->state(fn (array $attributes): array => ['points' => $points]);
    }
}
