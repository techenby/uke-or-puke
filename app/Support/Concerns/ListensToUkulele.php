<?php

namespace App\Support\Concerns;

use Illuminate\Support\Str;
use Native\Mobile\Attributes\On;
use Native\Mobile\Facades\System;
use RuntimeException;
use UkeOrPuke\Audio\Audio;
use UkeOrPuke\Audio\Events\AudioStatus;

trait ListensToUkulele
{
    public string $audioSession = '';

    public string $microphoneStatus = 'off';

    public string $microphoneMessage = 'Microphone off';

    public float $lastAudioAtMs = 0;

    public float $lastAudioFrameMs = -1;

    public int $inputLevel = 0;

    protected function setupMicrophone(): void
    {
        $this->registerCleanup(fn () => $this->stopMicrophone());
    }

    protected function startMicrophone(): void
    {
        $this->stopMicrophone();
        $this->audioSession = (string) Str::uuid();
        $this->lastAudioFrameMs = -1;
        $this->inputLevel = 0;
        $this->lastAudioAtMs = now()->getTimestampMs();
        $this->microphoneStatus = 'requesting';
        $this->microphoneMessage = 'Waiting for microphone…';

        try {
            app(Audio::class)->start($this->audioSession);
        } catch (RuntimeException $exception) {
            $this->audioStatus($this->audioSession, 'error', $exception->getMessage());
        }
    }

    public function stopMicrophone(): void
    {
        $id = $this->audioSession;
        $this->audioSession = '';
        $this->microphoneStatus = 'off';
        $this->microphoneMessage = 'Microphone off';
        $this->inputLevel = 0;
        if ($id !== '') {
            try {
                app(Audio::class)->stop($id);
            } catch (RuntimeException $exception) {
                report($exception);
            }
        }
    }

    #[On(AudioStatus::class)]
    public function audioStatus(string $sessionId, string $status, string $message = '', float $startedAtMs = 0): void
    {
        if ($sessionId !== $this->audioSession || $sessionId === '') {
            return;
        }
        if ($status === 'listening' && $this->microphoneStatus === 'requesting') {
            $this->microphoneStatus = $status;
            $this->microphoneMessage = 'Listening to your ukulele';
            $this->lastAudioAtMs = now()->getTimestampMs();
            $this->microphoneReady($startedAtMs);
        } elseif (in_array($status, ['denied', 'error', 'interrupted', 'stopped'], true)) {
            $this->stopMicrophone();
            $this->microphoneStatus = $status;
            $this->microphoneMessage = $message ?: 'Start listening again.';
            $this->microphoneInterrupted();
        }
    }

    protected function acceptAudioFrame(string $sessionId, float $elapsedMs, float $level): bool
    {
        if ($sessionId === '' || $sessionId !== $this->audioSession || $this->microphoneStatus !== 'listening'
            || ! is_finite($elapsedMs) || $elapsedMs <= $this->lastAudioFrameMs) {
            return false;
        }
        $this->lastAudioFrameMs = $elapsedMs;
        $this->lastAudioAtMs = now()->getTimestampMs();
        $this->inputLevel = (int) round(min(1, max(0, $level) * 8) * 10);

        return true;
    }

    protected function checkMicrophoneConnection(): void
    {
        if ($this->microphoneStatus === 'listening' && now()->getTimestampMs() - $this->lastAudioAtMs > 2500) {
            $this->audioStatus($this->audioSession, 'interrupted', 'The microphone stopped responding. Please try again.');
        }
    }

    public function microphoneSettings(): void
    {
        $this->stopMicrophone();
        $this->microphoneInterrupted();
        System::appSettings();
    }

    protected function microphoneReady(float $startedAtMs): void {}

    protected function microphoneInterrupted(): void {}
}
