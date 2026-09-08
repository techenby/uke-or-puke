<?php

use App\Models\Score;
use App\NativeComponents\Arcade;
use App\NativeComponents\Scores;
use Native\Mobile\Testing\Native;

it('invites a first round while the board is empty', function () {
    Native::test(Scores::class)
        ->assertSee('The board is empty')
        ->assertSee('Nobody has taken a place yet.')
        ->assertSee('Play the first round');
});

it('lists the board highest first, with how each round was played', function () {
    Score::factory()->worth(140)->create(['input_mode' => 'tap', 'hits' => 2, 'perfect' => 0, 'best_streak' => 1, 'speed' => 'slow', 'bpm' => 45]);
    Score::factory()->ukulele()->worth(800)->create(['hits' => 8, 'perfect' => 8, 'best_streak' => 8, 'speed' => 'fast', 'bpm' => 75]);

    Native::test(Scores::class)
        ->assertSee('Number 1, 800 points, ukulele')
        ->assertSee('Ukulele · 8 of 8 on time · 8 perfect')
        ->assertSee('FAST / 75 BPM · STREAK 8')
        ->assertSee('Number 2, 140 points, taps')
        ->assertSee('Taps · 2 of 8 on time · 0 perfect')
        ->assertSee('SLOW / 45 BPM · STREAK 1');
});

it('counts every round played, not only the ones on the board', function () {
    Score::factory()->count(Score::BOARD_SIZE + 2)->create(['input_mode' => 'tap']);
    Score::factory()->ukulele()->count(3)->create();

    Native::test(Scores::class)
        ->assertSee(Score::BOARD_SIZE + 5 .' rounds played · 3 on the ukulele')
        ->assertSee('Number 10, ')
        ->assertDontSee('Number 11, ');
});

it('starts a round from the board', function () {
    Native::test(Scores::class)
        ->tap('Play the first round')
        ->followNavigation()
        ->assertScreen(Arcade::class)
        ->assertSet('demo', false);
});

it('offers a place to take once the board has rounds on it', function () {
    Score::factory()->create();

    Native::test(Scores::class)
        ->assertDontSee('Play the first round')
        ->tap('Take a place')
        ->followNavigation()
        ->assertScreen(Arcade::class);
});
