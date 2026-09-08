<?php

namespace App\NativeComponents;

use App\Support\BeginnerLesson;
use App\Support\Concerns\ListensToUkulele;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
use UkeOrPuke\Audio\Events\AudioFrame;

class Arcade extends NativeComponent
{
    use ListensToUkulele;

    public string $inputMode = 'tap';

    public float $audioOffsetMs = 0;

    public int $lastStrumId = 0;

    /** @var array<int, string> */
    public array $heardCues = [];

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
        $this->inputMode = ! $this->demo && $this->data('inputMode') === 'microphone' ? 'microphone' : 'tap';
        $this->setupMicrophone();
    }

    public function start(): void
    {
        if (! in_array($this->status, ['ready', 'finished'], true)) {
            return;
        }

        $this->score = $this->streak = $this->bestStreak = 0;
        $this->judgments = [];
        $this->heardCues = [];
        $this->elapsedMs = 0;
        $this->lastTapMs = -1000;
        $this->feedbackUntilMs = 0;
        $this->feedback = 'Get ready…';
        $this->mood = 'happy';
        $this->beginInput();
    }

    private function beginInput(): void
    {
        if ($this->inputMode === 'microphone') {
            $this->audioOffsetMs = $this->elapsedMs;
            $this->lastStrumId = 0;
            $this->status = 'requesting';
            $this->startMicrophone();
        } else {
            $this->startedAtMs = $this->clockMs() - $this->elapsedMs;
            $this->status = 'playing';
        }
    }

    protected function microphoneReady(float $startedAtMs): void
    {
        if ($this->status === 'requesting') {
            $this->startedAtMs = $startedAtMs - $this->audioOffsetMs;
            $this->status = 'playing';
        }
    }

    protected function microphoneInterrupted(): void
    {
        if (in_array($this->status, ['playing', 'requesting'], true)) {
            $this->status = 'paused';
        }
    }

    #[On(AudioFrame::class)]
    public function audioFrame(string $sessionId, float $elapsedMs, float $level, mixed $chord = null, float $chordConfidence = 0, mixed $onsetMs = null, int $strumId = 0, bool $clipped = false): void
    {
        if ($this->inputMode !== 'microphone' || $this->status !== 'playing'
            || ! $this->acceptAudioFrame($sessionId, $elapsedMs, $level)) {
            return;
        }
        if ($onsetMs === null || $strumId <= $this->lastStrumId || $strumId <= 0
            || $elapsedMs - (float) $onsetMs > 650 || (float) $onsetMs > $elapsedMs) {
            return;
        }
        $onset = (float) $onsetMs + $this->audioOffsetMs;
        foreach (BeginnerLesson::CHORDS as $index => $target) {
            $offset = abs($onset - ($index + 1) * $this->barMs());
            if (isset($this->judgments[$index]) || $offset > 240) {
                continue;
            }
            $this->heardCues[$index] = 'unclear';
            if ($clipped || $level < 0.006 || $chordConfidence < 0.75 || ! in_array($chord, ['C', 'Am'], true)) {
                $this->showFeedback($clipped ? 'A little farther from the phone' : 'Can’t tell yet — keep strumming', 'happy');

                return;
            }
            $this->lastStrumId = $strumId;
            if ($chord !== $target) {
                $this->judgments[$index] = 'wrong';
                $this->streak = 0;
                $this->showFeedback('Heard '.$chord.' — try '.$target, 'miss');

                return;
            }
            $this->awardHit($index, $offset);

            return;
        }
    }

    public function barMs(): float
    {
        return 240000 / $this->bpm;
    }

    #[Poll(100)]
    public function tick(): void
    {
        if ($this->inputMode === 'microphone') {
            $this->checkMicrophoneConnection();
        }
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
            } elseif (! $this->demo && $this->elapsedMs > $due + ($this->inputMode === 'microphone' ? 950 : 240)) {
                $unclear = ($this->heardCues[$index] ?? null) === 'unclear';
                $this->judgments[$index] = $unclear ? 'unclear' : 'miss';
                if (! $unclear) {
                    $this->streak = 0;
                }
                $this->showFeedback($unclear ? 'Couldn’t hear that one. Keep going!' : 'Splat! Keep going.', $unclear ? 'happy' : 'miss');
            }
        }

        if ($this->elapsedMs >= (count(BeginnerLesson::CHORDS) + 1) * $this->barMs()) {
            $this->status = 'finished';
            if ($this->inputMode === 'microphone') {
                $this->stopMicrophone();
            }
        } elseif ($this->elapsedMs > $this->feedbackUntilMs) {
            $this->feedback = $this->elapsedMs < $this->barMs() ? 'Get ready…' : 'Follow the rainbow';
            $this->mood = 'happy';
        }
    }

    public function strum(): void
    {
        if ($this->status !== 'playing' || $this->demo || $this->inputMode === 'microphone') {
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
                $this->awardHit($index, $offset);

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
                if ($this->inputMode === 'microphone') {
                    $this->stopMicrophone();
                }
            }
        }
    }

    public function resume(): void
    {
        if ($this->status === 'paused') {
            $this->beginInput();
        }
    }

    public function home(): void
    {
        $this->stopMicrophone();
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

    private function awardHit(int $index, float $offset): void
    {
        $perfect = $offset <= 90;
        $this->judgments[$index] = $perfect ? 'perfect' : 'good';
        $this->score += $perfect ? 100 : 70;
        $this->streak++;
        $this->bestStreak = max($this->bestStreak, $this->streak);
        $this->showFeedback($perfect ? 'Uke-tastic!' : 'Nice strum!', 'happy');
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
            'unclear' => count(array_filter($this->judgments, fn (string $value): bool => $value === 'unclear')),
            'seconds' => (int) ceil(max(0, 9 * $bar - $this->elapsedMs) / 1000),
        ]);
    }
}
