<?php

namespace App\Support;

class BeginnerLesson
{
    public const CHORDS = ['C', 'Am', 'C', 'Am', 'C', 'Am', 'C', 'Am'];

    public const TEMPOS = ['slow' => 45, 'normal' => 60, 'fast' => 75];

    /** @return array{frets: list<int>, finger: int, instruction: string, color: string} */
    public static function chord(string $name): array
    {
        return $name === 'Am'
            ? ['frets' => [2, 0, 0, 0], 'finger' => 2, 'instruction' => 'Middle finger on the G string, fret 2.', 'color' => 'mint']
            : ['frets' => [0, 0, 0, 3], 'finger' => 3, 'instruction' => 'Ring finger on the A string, fret 3.', 'color' => 'pink'];
    }
}
