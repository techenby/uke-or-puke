<?php

namespace UkeOrPuke\Audio\Events;

class AudioStatus
{
    public function __construct(
        public string $sessionId,
        public string $status,
        public string $message = '',
        public float $startedAtMs = 0,
    ) {}
}
