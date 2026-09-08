<?php

namespace App\NativeComponents;

use App\Support\BeginnerLesson;
use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;

class Arcade extends NativeComponent
{
    public string $status = 'ready';

    public string $speed = 'normal';

    public bool $demo = false;

    public int $bpm = 60;

    public float $elapsedMs = 0;

    public float $startedAtMs = 0;

    public int $score = 0;

    public int $streak = 0;

    public int $bestStreak = 0;

    public string $feedback = 'Find the flow';

    public string $mood = 'happy';

    public float $feedbackUntilMs = 0;

    public float $lastTapMs = -1000;

    /** @var array<int, string> */
    public array $judgments = [];

    public function mount(): void
    {
        $speed = $this->data('speed', 'normal');
        $this->speed = isset(BeginnerLesson::TEMPOS[$speed]) ? $speed : 'normal';
        $this->bpm = BeginnerLesson::TEMPOS[$this->speed];
        $this->demo = (bool) $this->data('demo', false);
    }

    public function start(): void
    {
        if (! in_array($this->status, ['ready', 'finished'], true)) {
            return;
        }

        $this->score = $this->streak = $this->bestStreak = 0;
        $this->judgments = [];
        $this->elapsedMs = 0;
        $this->lastTapMs = -1000;
        $this->feedbackUntilMs = 0;
        $this->feedback = 'Get ready…';
        $this->mood = 'happy';
        $this->startedAtMs = $this->clockMs();
        $this->status = 'playing';
    }

    public function barMs(): float
    {
        return 240000 / $this->bpm;
    }

    #[Poll(100)]
    public function tick(): void
    {
        if ($this->status !== 'playing') {
            return;
        }

        $this->elapsedMs = max(0, $this->clockMs() - $this->startedAtMs);
        foreach (BeginnerLesson::CHORDS as $index => $chord) {
            if (isset($this->judgments[$index])) {
                continue;
            }

            $due = ($index + 1) * $this->barMs();
            if ($this->demo && $this->elapsedMs >= $due) {
                $this->judgments[$index] = 'demo';
                $this->showFeedback('Strum '.$chord, 'happy');
            } elseif (! $this->demo && $this->elapsedMs > $due + 240) {
                $this->judgments[$index] = 'miss';
                $this->streak = 0;
                $this->showFeedback('Splat! Keep going.', 'miss');
            }
        }

        if ($this->elapsedMs >= (count(BeginnerLesson::CHORDS) + 1) * $this->barMs()) {
            $this->status = 'finished';
        } elseif ($this->elapsedMs > $this->feedbackUntilMs) {
            $this->feedback = $this->elapsedMs < $this->barMs() ? 'Get ready…' : 'Follow the rainbow';
            $this->mood = 'happy';
        }
    }

    public function strum(): void
    {
        if ($this->status !== 'playing' || $this->demo) {
            return;
        }

        $this->tick();
        if ($this->status !== 'playing' || $this->elapsedMs - $this->lastTapMs < 250) {
            return;
        }
        $this->lastTapMs = $this->elapsedMs;

        foreach (BeginnerLesson::CHORDS as $index => $chord) {
            if (isset($this->judgments[$index])) {
                continue;
            }
            $offset = abs($this->elapsedMs - ($index + 1) * $this->barMs());
            if ($offset <= 240) {
                $perfect = $offset <= 90;
                $this->judgments[$index] = $perfect ? 'perfect' : 'good';
                $this->score += $perfect ? 100 : 70;
                $this->streak++;
                $this->bestStreak = max($this->bestStreak, $this->streak);
                $this->showFeedback($perfect ? 'Uke-tastic!' : 'Nice strum!', 'happy');

                return;
            }
        }

        $this->streak = 0;
        $this->showFeedback('Wait for the line!', 'happy');
    }

    public function pause(): void
    {
        if ($this->status === 'playing') {
            $this->tick();
            if ($this->status === 'playing') {
                $this->status = 'paused';
            }
        }
    }

    public function resume(): void
    {
        if ($this->status === 'paused') {
            $this->startedAtMs = $this->clockMs() - $this->elapsedMs;
            $this->status = 'playing';
        }
    }

    public function home(): void
    {
        $this->status = 'ready';
        $this->back();
    }

    public function onBackPressed(): void
    {
        if ($this->status === 'playing') {
            $this->pause();
        } else {
            $this->home();
        }
    }

    private function clockMs(): float
    {
        return now()->getTimestampMs();
    }

    private function showFeedback(string $text, string $mood): void
    {
        $this->feedback = $text;
        $this->mood = $mood;
        $this->feedbackUntilMs = $this->elapsedMs + 1100;
    }

    public function render(): View
    {
        $bar = $this->barMs();
        $active = min(7, max(0, (int) floor(($this->elapsedMs - 240) / $bar)));
        $chord = BeginnerLesson::CHORDS[$active];
        $cues = [];
        foreach (BeginnerLesson::CHORDS as $index => $name) {
            $top = (int) round(160 - ((($index + 1) * $bar - $this->elapsedMs) / $bar) * 160 - 20);
            if ($top >= -40 && $top <= 200 && ! isset($this->judgments[$index])) {
                $cues[] = ['index' => $index, 'chord' => $name, 'top' => $top, 'color' => BeginnerLesson::chord($name)['color']];
            }
        }
        $hits = count(array_filter($this->judgments, fn (string $value): bool => in_array($value, ['perfect', 'good'], true)));

        return view('native.arcade', [
            'chord' => $chord,
            'shape' => BeginnerLesson::chord($chord),
            'next' => BeginnerLesson::CHORDS[$active + 1] ?? null,
            'cues' => $cues,
            'countIn' => $this->elapsedMs < $bar,
            'beat' => (int) floor($this->elapsedMs / ($bar / 4)) % 4 + 1,
            'hits' => $hits,
            'accuracy' => (int) round($hits / 8 * 100),
            'perfect' => count(array_filter($this->judgments, fn (string $value): bool => $value === 'perfect')),
            'seconds' => (int) ceil(max(0, 9 * $bar - $this->elapsedMs) / 1000),
        ]);
    }
}
