<?php

namespace App\Models;

use Database\Factories\ScoreFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Score extends Model
{
    /** @use HasFactory<ScoreFactory> */
    use HasFactory;

    public const BOARD_SIZE = 10;

    protected $fillable = [
        'points',
        'hits',
        'perfect',
        'best_streak',
        'input_mode',
        'speed',
        'bpm',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'hits' => 'integer',
            'perfect' => 'integer',
            'best_streak' => 'integer',
            'bpm' => 'integer',
        ];
    }

    /**
     * @param  Builder<Score>  $query
     */
    public function scopeBest(Builder $query): void
    {
        $query->orderByDesc('points')->orderBy('id');
    }

    public function rank(): int
    {
        return static::query()
            ->where(function (Builder $query): void {
                $query->where('points', '>', $this->points)
                    ->orWhere(function (Builder $query): void {
                        $query->where('points', $this->points)
                            ->where('id', '<', $this->id);
                    });
            })
            ->count() + 1;
    }

    public function playedWithUkulele(): bool
    {
        return $this->input_mode === 'microphone';
    }
}
