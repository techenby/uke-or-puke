<?php

use App\NativeComponents\Arcade;
use Native\Mobile\Testing\Native;

it('scores an on-time tap once and prepares the next chord', function () {
    $this->freezeTime();
    $round = Native::test(Arcade::class)->tap('Start arcade');
    $this->travel(4)->seconds();

    $round->tap('strum')->tap('strum')
        ->assertSet('score', 100)
        ->assertSet('bestStreak', 1)
        ->assertSee('Uke-tastic!');
    $this->travel(300)->milliseconds();
    $round->firePoll('tick')->assertSee('Middle finger on the G string, fret 2.');
});

it('awards timing grades at their boundaries', function (int $offset, int $score) {
    $this->freezeTime();
    $round = Native::test(Arcade::class)->tap('Start arcade');
    $this->travel(4000 + $offset)->milliseconds();

    $round->tap('strum')->assertSet('score', $score);
})->with([[-240, 70], [-90, 100], [90, 100], [240, 70], [241, 0], [-241, 0]]);

it('resets the streak for a missed cue and finishes with honest results', function () {
    $this->freezeTime();
    $round = Native::test(Arcade::class)->tap('Start arcade');
    $this->travel(4)->seconds();
    $round->tap('strum');
    $this->travel(4250)->milliseconds();

    $round->firePoll('tick')
        ->assertSet('streak', 0)
        ->assertSet('bestStreak', 1)
        ->assertSee('Splat! Keep going.');
    $this->travel(30)->seconds();
    $round->firePoll('tick')
        ->assertSet('status', 'finished')
        ->assertSee('1 of 8 cues hit. 7 splats.')
        ->assertSee('13%');
});

it('freezes the round while paused and resumes without losing time', function () {
    $this->freezeTime();
    $round = Native::test(Arcade::class)->tap('Start arcade');
    $this->travel(2)->seconds();
    $round->tap('Pause');
    $this->travel(20)->seconds();

    $round->firePoll('tick')->assertSet('elapsedMs', 2000.0)
        ->call('strum')->assertSet('score', 0)
        ->tap('Keep going');
    $this->travel(2)->seconds();
    $round->tap('strum')->assertSet('score', 100);
});

it('plays all demo cues without recording a score and can restart cleanly', function () {
    $this->freezeTime();
    $round = Native::test(Arcade::class, data: ['demo' => true])->tap('Start demo');
    $this->travel(4)->seconds();
    $round->call('strum')->assertSet('score', 0);
    $this->travel(32)->seconds();

    $round->firePoll('tick')
        ->assertSet('status', 'finished')
        ->assertSet('score', 0)
        ->assertDontSee('TAP TIMING SCORE')
        ->assertSee('Demo complete. No score was recorded.')
        ->tap('Watch again')
        ->assertSet('status', 'playing')
        ->assertSet('judgments', [])
        ->assertSet('elapsedMs', 0.0);
});

it('replays with a fresh score and leaves via Home', function () {
    $this->freezeTime();
    $round = Native::test(Arcade::class)->tap('Start arcade');
    $this->travel(4)->seconds();
    $round->tap('strum');
    $this->travel(32)->seconds();

    $round->firePoll('tick')->tap('Play again')
        ->assertSet('score', 0)
        ->assertSet('streak', 0)
        ->assertSet('judgments', [])
        ->tap('Pause')->tap('Leave round')->assertWentBack();
});

it('scores all eight strums at the selected pace', function (string $speed, int $barMs) {
    $this->freezeTime();
    $round = Native::test(Arcade::class, data: ['speed' => $speed])->tap('Start arcade');

    foreach (range(1, 8) as $cue) {
        $this->travel($barMs)->milliseconds();
        $round->tap('strum');
    }
    $this->travel($barMs)->milliseconds();

    $round->firePoll('tick')
        ->assertSet('status', 'finished')
        ->assertSet('score', 800)
        ->assertSet('bestStreak', 8)
        ->assertSee('100%');
})->with([['normal', 4000], ['fast', 3200]]);

it('ignores taps before starting and after finishing', function () {
    $this->freezeTime();
    $round = Native::test(Arcade::class)->call('strum')->assertSet('score', 0);
    $round->tap('Start arcade');
    $this->travel(36)->seconds();

    $round->firePoll('tick')->call('strum')->assertSet('score', 0)->assertSet('status', 'finished');
});
