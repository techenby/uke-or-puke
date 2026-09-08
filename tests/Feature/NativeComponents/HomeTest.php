<?php

use App\NativeComponents\Arcade;
use App\NativeComponents\Home;
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
