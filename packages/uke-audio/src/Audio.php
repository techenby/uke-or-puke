<?php

namespace UkeOrPuke\Audio;

use RuntimeException;

class Audio
{
    public function start(string $sessionId): void
    {
        $this->call('UkeAudio.Start', ['sessionId' => $sessionId]);
    }

    public function stop(string $sessionId): void
    {
        $this->call('UkeAudio.Stop', ['sessionId' => $sessionId]);
    }

    /** @param array<string, mixed> $parameters */
    private function call(string $method, array $parameters): void
    {
        if (! function_exists('nativephp_call')) {
            throw new RuntimeException('Microphone play needs the iPhone app.');
        }

        $result = json_decode(nativephp_call($method, json_encode($parameters, JSON_THROW_ON_ERROR)) ?? 'null', true);
        if (! is_array($result) || ($result['success'] ?? false) !== true) {
            throw new RuntimeException($result['message'] ?? 'Microphone support is unavailable. Rebuild the iPhone app with the audio plugin.');
        }
    }
}
