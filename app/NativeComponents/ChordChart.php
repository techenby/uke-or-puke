<?php

namespace App\NativeComponents;

use App\Support\BeginnerLesson;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class ChordChart extends NativeComponent
{
    public string $chord = 'C';

    public function render(): View
    {
        return view('native.chord-chart', ['shape' => BeginnerLesson::chord($this->chord)]);
    }
}
