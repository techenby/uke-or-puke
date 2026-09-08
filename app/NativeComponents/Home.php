<?php

namespace App\NativeComponents;

use App\Models\Score;
use App\Support\BeginnerLesson;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class Home extends NativeComponent
{
    public string $speed = 'normal';

    public bool $showLesson = false;

    public string $chord = 'C';

    public function chooseSpeed(string $speed): void
    {
        if (isset(BeginnerLesson::TEMPOS[$speed])) {
            $this->speed = $speed;
        }
    }

    public function toggleLesson(): void
    {
        $this->showLesson = ! $this->showLesson;
    }

    public function chooseChord(string $chord): void
    {
        if (in_array($chord, ['C', 'Am'], true)) {
            $this->chord = $chord;
        }
    }

    public function start(): void
    {
        $this->navigate('/arcade', ['speed' => $this->speed, 'demo' => false]);
    }

    public function demo(): void
    {
        $this->navigate('/arcade', ['speed' => $this->speed, 'demo' => true]);
    }

    public function microphone(): void
    {
        $this->navigate('/arcade', ['speed' => $this->speed, 'inputMode' => 'microphone']);
    }

    public function soundcheck(): void
    {
        $this->navigate('/soundcheck');
    }

    public function scores(): void
    {
        $this->navigate('/scores');
    }

    public function render(): View
    {
        return view('native.home', [
            'tempos' => BeginnerLesson::TEMPOS,
            'best' => Score::query()->best()->first(),
            'boardSize' => Score::BOARD_SIZE,
        ]);
    }
}
