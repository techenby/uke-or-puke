<?php

namespace App\Support\PromptLog;

use Carbon\CarbonImmutable;

class Exchange
{
    public function __construct(
        public string $tool,
        public string $model,
        public string $session,
        public CarbonImmutable $timestamp,
        public string $prompt,
        public string $response,
    ) {}

    /**
     * A stable identity for this exchange, used to de-duplicate across syncs.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->tool.'|'.$this->session.'|'.$this->prompt), 0, 16);
    }

    /** @return array{id: string, ts: string, tool: string, model: string, session: string, prompt: string, response: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->fingerprint(),
            'ts' => $this->timestamp->toIso8601ZuluString(),
            'tool' => $this->tool,
            'model' => $this->model,
            'session' => $this->session,
            'prompt' => $this->prompt,
            'response' => $this->response,
        ];
    }
}
