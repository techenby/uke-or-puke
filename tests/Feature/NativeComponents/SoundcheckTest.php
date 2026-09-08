<?php

use App\NativeComponents\Soundcheck;
use Native\Mobile\Testing\Native;
use UkeOrPuke\Audio\Events\AudioFrame;
use UkeOrPuke\Audio\Events\AudioStatus;

it('opens soundcheck without activating the microphone', function () {
    Native::visit('/')->tap('Microphone soundcheck')->followNavigation()
        ->assertScreen(Soundcheck::class)->assertSee('Standard high-G tuning')
        ->assertNativeNotCalled('UkeAudio.Start')->assertAccessible();
});

it('shows permission denial and allows a fresh listening attempt', function () {
    Native::fakeBridge()->respondTo('UkeAudio.Start', ['success' => true])->respondTo('UkeAudio.Stop', ['success' => true]);
    $screen = Native::test(Soundcheck::class)->tap('Start listening');
    $session = $screen->get('audioSession');

    $screen->emitNative(AudioStatus::class, ['sessionId' => $session, 'status' => 'denied', 'message' => 'Enable microphone access in Settings.'])
        ->assertSee('Open Settings')->assertSet('audioSession', '')
        ->tap('Start listening')->assertSet('microphoneStatus', 'requesting');
    expect($screen->get('audioSession'))->not->toBe($session);
});

it('reports an unavailable native plugin without claiming to listen', function () {
    Native::fakeBridge()->respondTo('UkeAudio.Start', ['status' => 'error', 'message' => 'Rebuild the iPhone app.'])->respondTo('UkeAudio.Stop', ['success' => true]);

    Native::test(Soundcheck::class)->tap('Start listening')
        ->assertSet('microphoneStatus', 'error')->assertSet('audioSession', '')
        ->assertSee('Rebuild the iPhone app.');
});

it('checks exact standard tuning including octave and cents', function (int $midi, float $cents, bool $matched, string $feedback) {
    Native::fakeBridge()->respondTo('UkeAudio.Start', ['success' => true])->respondTo('UkeAudio.Stop', ['success' => true]);
    $screen = Native::test(Soundcheck::class)->tap('Start listening');
    $session = $screen->get('audioSession');
    $screen->emitNative(AudioStatus::class, ['sessionId' => $session, 'status' => 'listening']);

    $screen->emitNative(AudioFrame::class, ['sessionId' => $session, 'elapsedMs' => 200, 'level' => 0.08, 'midi' => $midi, 'cents' => $cents, 'noteConfidence' => 0.95])
        ->assertSet('matched', $matched)->assertSee($feedback);
})->with([
    [67, 25.0, true, 'In tune.'],
    [67, -25.0, true, 'In tune.'],
    [67, 26.0, false, 'tune down gently.'],
    [67, -26.0, false, 'tune up gently.'],
    [55, 0.0, false, 'Check the selected string and standard high-G tuning.'],
]);

it('clears a match for ambiguous, clipped and quiet input', function (array $signal, string $feedback) {
    Native::fakeBridge()->respondTo('UkeAudio.Start', ['success' => true])->respondTo('UkeAudio.Stop', ['success' => true]);
    $screen = Native::test(Soundcheck::class)->tap('C chord')->tap('Start listening');
    $session = $screen->get('audioSession');
    $screen->emitNative(AudioStatus::class, ['sessionId' => $session, 'status' => 'listening']);
    $frame = ['sessionId' => $session, 'elapsedMs' => 200, 'level' => 0.08, 'chord' => 'C', 'chordConfidence' => 0.95];
    $screen->emitNative(AudioFrame::class, $frame)->assertSet('matched', true);

    $screen->emitNative(AudioFrame::class, array_replace($frame, ['elapsedMs' => 300], $signal))
        ->assertSet('matched', false)->assertSee($feedback);
})->with([
    [['chordConfidence' => 0.2], 'Can’t tell yet.'],
    [['clipped' => true], 'Too loud.'],
    [['level' => 0.001], 'Ready when you are.'],
    [['chord' => 'Am'], 'Hearing Am.'],
]);

it('ignores old frames and stops listening when leaving', function () {
    Native::fakeBridge()->respondTo('UkeAudio.Start', ['success' => true])->respondTo('UkeAudio.Stop', ['success' => true]);
    $screen = Native::test(Soundcheck::class)->tap('Start listening');
    $session = $screen->get('audioSession');
    $screen->emitNative(AudioStatus::class, ['sessionId' => $session, 'status' => 'listening']);
    $frame = ['sessionId' => $session, 'elapsedMs' => 300, 'level' => 0.08, 'midi' => 67, 'cents' => 0, 'noteConfidence' => 0.95];
    $screen->emitNative(AudioFrame::class, $frame)->assertSet('matched', true);

    $screen->emitNative(AudioFrame::class, array_replace($frame, ['elapsedMs' => 200, 'level' => 0]))
        ->assertSet('matched', true)->tap('Stop listening')
        ->emitNative(AudioFrame::class, array_replace($frame, ['elapsedMs' => 400]))->assertSet('inputLevel', 0)
        ->call('onBackPressed')
        ->assertNativeCalled('UkeAudio.Stop', fn (array $params): bool => $params['sessionId'] === $session)->assertWentBack();
});

it('detects a stalled audio stream and clears the display', function () {
    $this->freezeTime();
    Native::fakeBridge()->respondTo('UkeAudio.Start', ['success' => true])->respondTo('UkeAudio.Stop', ['success' => true]);
    $screen = Native::test(Soundcheck::class)->tap('Start listening');
    $screen->emitNative(AudioStatus::class, ['sessionId' => $screen->get('audioSession'), 'status' => 'listening']);
    $this->travel(3)->seconds();

    $screen->firePoll('checkInput')->assertSet('microphoneStatus', 'interrupted')->assertSee('stopped responding');
});

it('opens app settings after permission denial', function () {
    Native::fakeBridge()->respondTo('UkeAudio.Start', ['success' => true])->respondTo('UkeAudio.Stop', ['success' => true]);
    $screen = Native::test(Soundcheck::class)->tap('Start listening');
    $screen->emitNative(AudioStatus::class, ['sessionId' => $screen->get('audioSession'), 'status' => 'denied']);

    $screen->tap('Open Settings')->assertNativeCalled('System.OpenAppSettings')->assertSet('audioSession', '');
});
