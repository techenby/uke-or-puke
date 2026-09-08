<?php

use App\Models\Score;
use App\NativeComponents\Arcade;
use App\NativeComponents\Scores;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

function playPerfectRound(): TestableComponent
{
    $round = Native::test(Arcade::class)->tap('Start arcade');

    foreach (range(1, 8) as $cue) {
        test()->travel(4)->seconds();
        $round->tap('strum');
    }
    test()->travel(4)->seconds();

    return $round->firePoll('tick');
}

it('keeps a finished round on the board', function () {
    $this->freezeTime();

    playPerfectRound()->assertSet('status', 'finished');

    $score = Score::sole();
    expect($score->points)->toBe(800)
        ->and($score->hits)->toBe(8)
        ->and($score->perfect)->toBe(8)
        ->and($score->best_streak)->toBe(8)
        ->and($score->input_mode)->toBe('tap')
        ->and($score->speed)->toBe('normal')
        ->and($score->bpm)->toBe(60);
});

it('keeps a round nobody scored on, splats and all', function () {
    $this->freezeTime();
    $round = Native::test(Arcade::class)->tap('Start arcade');
    $this->travel(36)->seconds();

    $round->firePoll('tick')->assertSet('status', 'finished');

    $score = Score::sole();
    expect($score->points)->toBe(0)
        ->and($score->hits)->toBe(0)
        ->and($score->best_streak)->toBe(0);
});

it('does not record a demo round, which plays itself', function () {
    $this->freezeTime();
    $round = Native::test(Arcade::class, data: ['demo' => true])->tap('Start demo');
    $this->travel(36)->seconds();

    $round->firePoll('tick')->assertSet('status', 'finished');

    expect(Score::count())->toBe(0);
});

it('announces a new high score when the round tops the board', function () {
    $this->freezeTime();
    Score::factory()->worth(700)->create();

    playPerfectRound()->assertSet('rank', 1)->assertSee('NEW HIGH SCORE');
});

it('names the place a round took on the board', function () {
    $this->freezeTime();
    Score::factory()->worth(900)->create();

    playPerfectRound()->assertSet('rank', 2)->assertSee('NO. 2 ON THE BOARD');
});

it('still saves a round that misses the board', function () {
    $this->freezeTime();
    Score::factory()->count(Score::BOARD_SIZE)->worth(800)->create();
    $round = Native::test(Arcade::class)->tap('Start arcade');
    $this->travel(36)->seconds();

    $round->firePoll('tick')
        ->assertSet('rank', Score::BOARD_SIZE + 1)
        ->assertSee('the top '.Score::BOARD_SIZE.' is still up for grabs');
    expect(Score::count())->toBe(Score::BOARD_SIZE + 1);
});

it('starts a replay with no place on the board', function () {
    $this->freezeTime();

    playPerfectRound()->assertSet('rank', 1)
        ->tap('Play again')
        ->assertSet('rank', 0)
        ->assertDontSee('NEW HIGH SCORE');
});

it('opens the board from the results screen', function () {
    $this->freezeTime();

    playPerfectRound()
        ->tap('See the high scores')
        ->followNavigation()
        ->assertScreen(Scores::class)
        ->assertSee('Number 1, 800 points, taps');
});

it('does not offer the board after a demo, which records nothing', function () {
    $this->freezeTime();
    $round = Native::test(Arcade::class, data: ['demo' => true])->tap('Start demo');
    $this->travel(36)->seconds();

    $round->firePoll('tick')->assertDontSee('See the high scores');
});
