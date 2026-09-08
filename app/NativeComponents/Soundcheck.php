<?php

namespace App\NativeComponents;

use App\Support\Concerns\ListensToUkulele;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
use UkeOrPuke\Audio\Events\AudioFrame;

class Soundcheck extends NativeComponent
{
    use ListensToUkulele;

    public string $target = 'G4';

    public string $feedback = 'Pluck one open string at a time.';

    public string $heard = '—';

    public bool $matched = false;

    public const NOTES = ['G4' => 67, 'C4' => 60, 'E4' => 64, 'A4' => 69];

    public function mount(): void
    {
        $this->setupMicrophone();
    }

    public function listen(): void
    {
        $this->heard = '—';
        $this->matched = false;
        $this->startMicrophone();
    }

    public function chooseTarget(string $target): void
    {
        if (isset(self::NOTES[$target]) || in_array($target, ['C', 'Am'], true)) {
            $this->target = $target;
            $this->matched = false;
            $this->heard = '—';
            $this->feedback = isset(self::NOTES[$target]) ? 'Pluck only this open string.' : 'Strum all four strings.';
        }
    }

    #[On(AudioFrame::class)]
    public function audioFrame(string $sessionId, float $elapsedMs, float $level, mixed $midi = null, mixed $cents = null, float $noteConfidence = 0, mixed $chord = null, float $chordConfidence = 0, bool $clipped = false): void
    {
        if (! $this->acceptAudioFrame($sessionId, $elapsedMs, $level)) {
            return;
        }
        $this->matched = false;
        $this->heard = '—';
        if ($clipped) {
            $this->feedback = 'Too loud. Move the phone a little farther away.';
        } elseif ($level < 0.006) {
            $this->feedback = 'Ready when you are. Play a little closer.';
        } elseif (isset(self::NOTES[$this->target])) {
            if ($midi === null || $cents === null || $noteConfidence < 0.8) {
                $this->feedback = 'Can’t tell yet. Pluck one string and let it ring.';

                return;
            }
            $notes = ['C', 'C♯', 'D', 'D♯', 'E', 'F', 'F♯', 'G', 'G♯', 'A', 'A♯', 'B'];
            $midi = (int) $midi;
            $this->heard = $notes[($midi % 12 + 12) % 12].((int) floor($midi / 12) - 1);
            $offset = ($midi - self::NOTES[$this->target]) * 100 + (float) $cents;
            $this->matched = abs($offset) <= 25;
            $this->feedback = match (true) {
                $this->matched => 'That’s '.$this->target.'! In tune.',
                abs($offset) > 100 => 'Hearing '.$this->heard.'. Check the selected string and standard high-G tuning.',
                $offset < 0 => 'A little low — tune up gently.',
                default => 'A little high — tune down gently.',
            };
        } elseif (in_array($chord, ['C', 'Am'], true) && $chordConfidence >= 0.75) {
            $this->heard = $chord;
            $this->matched = $chord === $this->target;
            $this->feedback = $this->matched ? 'That sounds like '.$this->target.'!' : 'Hearing '.$chord.'. Check the fingering for '.$this->target.'.';
        } else {
            $this->feedback = 'Can’t tell yet. Let all four strings ring clearly.';
        }
    }

    #[Poll(500)]
    public function checkInput(): void
    {
        $this->checkMicrophoneConnection();
        if ($this->microphoneStatus !== 'listening') {
            $this->matched = false;
            $this->heard = '—';
        }
    }

    public function home(): void
    {
        $this->stopMicrophone();
        $this->back();
    }

    public function onBackPressed(): void
    {
        $this->home();
    }

    public function render(): View
    {
        return view('native.soundcheck');
    }
}
