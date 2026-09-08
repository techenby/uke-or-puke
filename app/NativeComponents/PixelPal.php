<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class PixelPal extends NativeComponent
{
    public string $mood = 'happy';

    public function render(): View
    {
        $pixels = [
            '...yyyyyy...', '..yyyyyyyy..', '.yyyyyyyyyy.',
            '.yykyyyykyy.', '.yykyyyykyy.', '.ppyyyyyypp.',
            '.yyykyykyyy.', '..yyykkyyy..', '...yyyyyy...', '..yy....yy..',
        ];
        if ($this->mood === 'miss') {
            $pixels[6] = '.yyyykkyyyy.';
            $pixels[7] = '..yyypmyyy..';
            $pixels[8] = '...ypmbcy...';
            $pixels[9] = '..ypmbcvyy..';
        }

        return view('native.pixel-pal', [
            'pixels' => $pixels,
            'colors' => ['y' => 'sun', 'k' => 'ink', 'p' => 'pink', 'm' => 'orange', 'b' => 'mint', 'c' => 'sky', 'v' => 'violet'],
        ]);
    }
}
