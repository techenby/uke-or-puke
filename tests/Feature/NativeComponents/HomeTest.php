<?php

use App\Models\Score;
use App\NativeComponents\Arcade;
use App\NativeComponents\Home;
use App\NativeComponents\Scores;
use Native\Mobile\Testing\Native;

it('teaches both chord shapes and opens the selected arcade pace', function () {
    Native::test(Home::class)
        ->tap('Meet your two chords')
        ->assertSee('Ring finger on the A string, fret 3.')
        ->tap('A minor')
        ->assertSee('Middle finger on the G string, fret 2.')
        ->tap('speed-slow')
        ->tap("Let's jam")
        ->followNavigation()
        ->assertScreen(Arcade::class)
        ->assertSet('bpm', 45)
        ->assertSet('demo', false)
        ->assertSee('Start arcade');
});

it('opens an explicitly unscored demo', function () {
    Native::test(Home::class)
        ->tap('Watch a demo')
        ->followNavigation()
        ->assertSet('demo', true)
        ->assertSee('Start demo');
});

it('invites a first round while the board is empty', function () {
    Native::test(Home::class)
        ->assertSee('The board is empty.')
        ->assertSee('Play one round and every place on it is yours.');
});

it('shows the best round played so far', function () {
    Score::factory()->worth(140)->create(['input_mode' => 'tap', 'hits' => 2]);
    Score::factory()->ukulele()->worth(800)->create(['hits' => 8, 'speed' => 'fast', 'bpm' => 75]);

    Native::test(Home::class)
        ->assertDontSee('The board is empty.')
        ->assertSee('Best round, 800 points, ukulele')
        ->assertSee('Ukulele · 8 of 8 on time')
        ->assertSee('FAST / 75 BPM')
        ->assertDontSee('140');
});

it('opens the full board from the home screen', function () {
    Native::test(Home::class)
        ->tap('See the high scores')
        ->followNavigation()
        ->assertScreen(Scores::class)
        ->assertSee('TOP 10');
});
