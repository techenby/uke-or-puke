<?php

namespace App\NativeComponents;

use App\Models\Score;
use App\Support\BeginnerLesson;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class Scores extends NativeComponent
{
    public function play(): void
    {
        $this->navigate('/arcade', ['speed' => 'normal', 'demo' => false]);
    }

    public function render(): View
    {
        $board = Score::query()->best()->limit(Score::BOARD_SIZE)->get();

        return view('native.scores', [
            'board' => $board,
            'boardSize' => Score::BOARD_SIZE,
            'rounds' => Score::query()->count(),
            'cues' => count(BeginnerLesson::CHORDS),
            'ukuleleRounds' => Score::query()->where('input_mode', 'microphone')->count(),
        ]);
    }
}
