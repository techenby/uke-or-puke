<?php

namespace App\Support\PromptLog;

use Carbon\CarbonImmutable;

class CodexTranscript
{
    /**
     * Parse one Codex rollout file, keeping only turns run inside the given project.
     *
     * @return list<Exchange>
     */
    public static function parse(string $file, string $projectPath): array
    {
        $exchanges = [];
        $prompt = null;
        $promptAt = null;
        $responses = [];
        $model = 'unknown';
        $session = '';
        $inProject = false;

        $flush = function () use (&$exchanges, &$prompt, &$promptAt, &$responses, &$model, &$session): void {
            if ($prompt === null) {
                return;
            }

            $response = trim(implode("\n\n", $responses));

            if ($response !== '') {
                $exchanges[] = new Exchange('codex', $model, $session, $promptAt, $prompt, $response);
            }

            $prompt = null;
            $responses = [];
        };

        foreach (self::lines($file) as $entry) {
            $payload = $entry['payload'] ?? null;

            if (! is_array($payload)) {
                continue;
            }

            if ($entry['type'] === 'session_meta') {
                $session = $payload['session_id'] ?? '';
                $inProject = self::isInProject($payload['cwd'] ?? '', $projectPath);
            }

            if ($entry['type'] === 'turn_context') {
                $inProject = self::isInProject($payload['cwd'] ?? '', $projectPath);

                if (is_string($payload['model'] ?? null)) {
                    $model = $payload['model'];
                }
            }

            if (! $inProject || ($entry['type'] !== 'response_item') || ($payload['type'] ?? null) !== 'message') {
                continue;
            }

            if (($payload['role'] ?? null) === 'user') {
                $text = self::text($payload['content'] ?? null, 'input_text');

                if ($text === null || self::isInjectedContext($text)) {
                    continue;
                }

                $flush();

                $prompt = $text;
                $promptAt = self::timestamp($entry);
            }

            if (($payload['role'] ?? null) === 'assistant' && $prompt !== null) {
                $text = self::text($payload['content'] ?? null, 'output_text');

                if ($text !== null) {
                    $responses[] = $text;
                }
            }
        }

        $flush();

        return $exchanges;
    }

    /**
     * Read the session's working directory without parsing the whole file.
     */
    public static function belongsToProject(string $file, string $projectPath): bool
    {
        $handle = fopen($file, 'r');

        if ($handle === false) {
            return false;
        }

        $line = fgets($handle);
        fclose($handle);

        $entry = json_decode($line ?: '', true);

        return self::isInProject($entry['payload']['cwd'] ?? '', $projectPath);
    }

    private static function isInProject(mixed $cwd, string $projectPath): bool
    {
        return is_string($cwd) && $cwd !== '' && str_starts_with($cwd, $projectPath);
    }

    /**
     * Codex sends environment and instruction blocks through the user role; those are not prompts.
     */
    private static function isInjectedContext(string $text): bool
    {
        return (bool) preg_match('/^<[a-z_]+>/', $text);
    }

    private static function text(mixed $content, string $type): ?string
    {
        if (! is_array($content)) {
            return null;
        }

        $parts = [];

        foreach ($content as $block) {
            if (($block['type'] ?? null) === $type && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        $text = trim(implode("\n", $parts));

        return $text === '' ? null : $text;
    }

    private static function timestamp(array $entry): CarbonImmutable
    {
        return CarbonImmutable::parse($entry['timestamp'] ?? 'now')->utc();
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private static function lines(string $file): \Generator
    {
        $handle = fopen($file, 'r');

        if ($handle === false) {
            return;
        }

        while (($line = fgets($handle)) !== false) {
            $entry = json_decode($line, true);

            if (is_array($entry) && isset($entry['type'])) {
                yield $entry;
            }
        }

        fclose($handle);
    }
}
