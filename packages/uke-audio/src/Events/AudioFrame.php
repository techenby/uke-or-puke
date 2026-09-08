<?php

namespace UkeOrPuke\Audio\Events;

class AudioFrame
{
    public function __construct(
        public string $sessionId,
        public float $elapsedMs,
        public float $level,
        public ?int $midi = null,
        public ?float $cents = null,
        public float $noteConfidence = 0,
        public ?string $chord = null,
        public float $chordConfidence = 0,
        public ?float $onsetMs = null,
        public int $strumId = 0,
        public bool $clipped = false,
    ) {}
}
