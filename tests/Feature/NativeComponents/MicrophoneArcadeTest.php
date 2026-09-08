<?php

use App\NativeComponents\Arcade;
use Native\Mobile\Testing\Native;
use UkeOrPuke\Audio\Events\AudioFrame;
use UkeOrPuke\Audio\Events\AudioStatus;

it('waits for capture before starting the count-in and cannot score screen taps', function () {
    $this->freezeTime();
    Native::fakeBridge()->respondTo('UkeAudio.Start', ['success' => true])->respondTo('UkeAudio.Stop', ['success' => true]);
    $round = Native::visit('/')->tap('Play with your ukulele')->followNavigation()->tap('Start arcade');
    $this->travel(10)->seconds();

    $round->firePoll('tick')->assertSet('status', 'requesting')->assertSet('elapsedMs', 0.0)
        ->emitNative(AudioStatus::class, ['sessionId' => $round->get('audioSession'), 'status' => 'listening', 'startedAtMs' => now()->getTimestampMs()])
        ->assertSet('status', 'playing')->assertDontSee('STRUM ↓');
    $this->travel(4)->seconds();
    $round->call('strum')->assertSet('score', 0);
});

it('grades the audio onset rather than delayed analysis arrival and awards each strum once', function () {
    $this->freezeTime();
    Native::fakeBridge()->respondTo('UkeAudio.Start', ['success' => true])->respondTo('UkeAudio.Stop', ['success' => true]);
    $round = Native::test(Arcade::class, data: ['inputMode' => 'microphone'])->tap('Start arcade');
    $session = $round->get('audioSession');
    $round->emitNative(AudioStatus::class, ['sessionId' => $session, 'status' => 'listening', 'startedAtMs' => now()->getTimestampMs()]);
    $this->travel(4500)->milliseconds();
    $round->emitNative(AudioFrame::class, ['sessionId' => $session, 'elapsedMs' => 3900, 'level' => 0]);
    $round->firePoll('tick')->assertSet('judgments', []);
    $frame = ['sessionId' => $session, 'elapsedMs' => 4500, 'level' => 0.08, 'chord' => 'C', 'chordConfidence' => 0.95, 'onsetMs' => 4000, 'strumId' => 1];

    $round->emitNative(AudioFrame::class, $frame)->assertSet('score', 100)
        ->emitNative(AudioFrame::class, array_replace($frame, ['elapsedMs' => 4600]))->assertSet('score', 100)
        ->emitNative(AudioFrame::class, array_replace($frame, ['elapsedMs' => 8200, 'onsetMs' => 8000, 'chord' => 'Am']))->assertSet('score', 100)
        ->emitNative(AudioFrame::class, array_replace($frame, ['elapsedMs' => 8300, 'onsetMs' => 8000, 'chord' => 'Am', 'strumId' => 2]))->assertSet('score', 200);
});

it('distinguishes wrong chords from unclear input', function (mixed $chord, float $confidence, string $judgment, int $streak) {
    $this->freezeTime();
    Native::fakeBridge()->respondTo('UkeAudio.Start', ['success' => true])->respondTo('UkeAudio.Stop', ['success' => true]);
    $round = Native::test(Arcade::class, data: ['inputMode' => 'microphone'])->tap('Start arcade')->set('streak', 2);
    $session = $round->get('audioSession');
    $round->emitNative(AudioStatus::class, ['sessionId' => $session, 'status' => 'listening', 'startedAtMs' => now()->getTimestampMs()]);
    $this->travel(4300)->milliseconds();
    $round->emitNative(AudioFrame::class, ['sessionId' => $session, 'elapsedMs' => 4300, 'level' => 0.08, 'chord' => $chord, 'chordConfidence' => $confidence, 'onsetMs' => 4000, 'strumId' => 1]);
    $this->travel(700)->milliseconds();

    $round->firePoll('tick')->assertSet('judgments', [0 => $judgment])->assertSet('score', 0)->assertSet('streak', $streak);
})->with([['Am', 0.95, 'wrong', 0], [null, 0.1, 'unclear', 2], ['C', 0.2, 'unclear', 2]]);

it('rejects late attacks and ringing tails', function (float $onset, float $frameTime) {
    $this->freezeTime();
    Native::fakeBridge()->respondTo('UkeAudio.Start', ['success' => true])->respondTo('UkeAudio.Stop', ['success' => true]);
    $round = Native::test(Arcade::class, data: ['inputMode' => 'microphone'])->tap('Start arcade');
    $session = $round->get('audioSession');
    $round->emitNative(AudioStatus::class, ['sessionId' => $session, 'status' => 'listening', 'startedAtMs' => now()->getTimestampMs()]);

    $round->emitNative(AudioFrame::class, ['sessionId' => $session, 'elapsedMs' => $frameTime, 'level' => 0.08, 'chord' => 'C', 'chordConfidence' => 0.95, 'onsetMs' => $onset, 'strumId' => 1])
        ->assertSet('score', 0);
})->with([[4241.0, 4500.0], [4000.0, 4700.0], [3000.0, 4300.0]]);

it('resumes with a new audio session and excludes time spent paused', function () {
    $this->freezeTime();
    Native::fakeBridge()->respondTo('UkeAudio.Start', ['success' => true])->respondTo('UkeAudio.Stop', ['success' => true]);
    $round = Native::test(Arcade::class, data: ['inputMode' => 'microphone'])->tap('Start arcade');
    $old = $round->get('audioSession');
    $round->emitNative(AudioStatus::class, ['sessionId' => $old, 'status' => 'listening', 'startedAtMs' => now()->getTimestampMs()]);
    $this->travel(2)->seconds();
    $round->tap('Pause')->assertNativeCalled('UkeAudio.Stop', fn (array $params): bool => $params['sessionId'] === $old);
    $this->travel(20)->seconds();
    $round->tap('Keep going');
    $session = $round->get('audioSession');
    $round->emitNative(AudioStatus::class, ['sessionId' => $session, 'status' => 'listening', 'startedAtMs' => now()->getTimestampMs()]);
    $frame = ['sessionId' => $old, 'elapsedMs' => 2200, 'level' => 0.08, 'chord' => 'C', 'chordConfidence' => 0.95, 'onsetMs' => 2000, 'strumId' => 1];

    $round->emitNative(AudioFrame::class, $frame)->assertSet('score', 0)
        ->emitNative(AudioFrame::class, array_replace($frame, ['sessionId' => $session]))->assertSet('score', 100);
});

it('pauses on interruption without turning the missing audio into misses', function () {
    $this->freezeTime();
    Native::fakeBridge()->respondTo('UkeAudio.Start', ['success' => true])->respondTo('UkeAudio.Stop', ['success' => true]);
    $round = Native::test(Arcade::class, data: ['inputMode' => 'microphone'])->tap('Start arcade');
    $session = $round->get('audioSession');
    $round->emitNative(AudioStatus::class, ['sessionId' => $session, 'status' => 'listening', 'startedAtMs' => now()->getTimestampMs()]);

    $round->emitNative(AudioStatus::class, ['sessionId' => $session, 'status' => 'interrupted', 'message' => 'Audio was interrupted.'])
        ->assertSet('status', 'paused')->assertSet('audioSession', '');
    $this->travel(20)->seconds();
    $round->firePoll('tick')->assertSet('judgments', [])->assertSee('Audio was interrupted.');
});

it('can resolve an unclear attack before its recognition deadline', function () {
    $this->freezeTime();
    Native::fakeBridge()->respondTo('UkeAudio.Start', ['success' => true])->respondTo('UkeAudio.Stop', ['success' => true]);
    $round = Native::test(Arcade::class, data: ['inputMode' => 'microphone'])->tap('Start arcade');
    $session = $round->get('audioSession');
    $round->emitNative(AudioStatus::class, ['sessionId' => $session, 'status' => 'listening', 'startedAtMs' => now()->getTimestampMs()]);
    $frame = ['sessionId' => $session, 'elapsedMs' => 4200, 'level' => 0.08, 'onsetMs' => 4000, 'strumId' => 1];

    $round->emitNative(AudioFrame::class, $frame)->assertSet('judgments', [])
        ->emitNative(AudioFrame::class, array_replace($frame, ['elapsedMs' => 4300, 'chord' => 'C', 'chordConfidence' => 0.75]))
        ->assertSet('score', 100)->assertSet('judgments', [0 => 'perfect']);
});

it('finishes microphone rounds and releases the input', function () {
    $this->freezeTime();
    Native::fakeBridge()->respondTo('UkeAudio.Start', ['success' => true])->respondTo('UkeAudio.Stop', ['success' => true]);
    $round = Native::test(Arcade::class, data: ['inputMode' => 'microphone'])->tap('Start arcade');
    $session = $round->get('audioSession');
    $round->emitNative(AudioStatus::class, ['sessionId' => $session, 'status' => 'listening', 'startedAtMs' => now()->getTimestampMs()]);
    foreach (['C', 'Am', 'C', 'Am', 'C', 'Am', 'C', 'Am'] as $index => $chord) {
        $this->travel(4)->seconds();
        $round->emitNative(AudioFrame::class, ['sessionId' => $session, 'elapsedMs' => ($index + 1) * 4000 + 300, 'level' => 0.08, 'onsetMs' => ($index + 1) * 4000, 'strumId' => $index + 1, 'chord' => $chord, 'chordConfidence' => 0.85]);
        $round->firePoll('tick');
    }
    $this->travel(4)->seconds();
    $round->emitNative(AudioFrame::class, ['sessionId' => $session, 'elapsedMs' => 36000, 'level' => 0]);

    $round->firePoll('tick')->assertSet('status', 'finished')->assertSet('score', 800)
        ->assertSet('audioSession', '')->assertSee('8 of 8 chords matched on time.')
        ->assertNativeCalled('UkeAudio.Stop', fn (array $params): bool => $params['sessionId'] === $session);
});
