<?php

namespace App\Support\PromptLog;

use Carbon\CarbonImmutable;

class ClaudeTranscript
{
    /**
     * Text that Claude Code injects into the user role but that the user never typed.
     */
    private const NOISE_PREFIXES = [
        '<command-name>',
        '<command-message>',
        '<local-command-stdout>',
        '<system-reminder>',
        '<user-prompt-submit-hook>',
        'Caveat: The messages below were generated',
    ];

    /**
     * Parse one Claude Code session transcript into prompt/response exchanges.
     *
     * @return list<Exchange>
     */
    public static function parse(string $file): array
    {
        $exchanges = [];
        $prompt = null;
        $promptAt = null;
        $model = 'unknown';
        $responses = [];
        $session = pathinfo($file, PATHINFO_FILENAME);

        $flush = function () use (&$exchanges, &$prompt, &$promptAt, &$responses, &$model, $session): void {
            if ($prompt === null) {
                return;
            }

            $response = trim(implode("\n\n", $responses));

            if ($response !== '') {
                $exchanges[] = new Exchange('claude', $model, $session, $promptAt, $prompt, $response);
            }

            $prompt = null;
            $responses = [];
        };

        foreach (self::lines($file) as $entry) {
            if (($entry['isSidechain'] ?? false) === true) {
                continue;
            }

            $message = $entry['message'] ?? null;

            if (! is_array($message)) {
                continue;
            }

            if ($entry['type'] === 'user') {
                if (($entry['isMeta'] ?? false) === true) {
                    continue;
                }

                $text = self::userText($message['content'] ?? null);

                if ($text === null) {
                    continue;
                }

                $flush();

                $prompt = $text;
                $promptAt = self::timestamp($entry);
            }

            if ($entry['type'] === 'assistant' && $prompt !== null) {
                if (($entry['isApiErrorMessage'] ?? false) === true) {
                    continue;
                }

                $entryModel = $message['model'] ?? null;

                if (is_string($entryModel) && $entryModel !== '<synthetic>') {
                    $model = $entryModel;
                }

                foreach (self::assistantText($message['content'] ?? null) as $text) {
                    $responses[] = $text;
                }
            }
        }

        $flush();

        return $exchanges;
    }

    /**
     * Pull the typed prompt out of a user entry, ignoring tool results and injected context.
     */
    private static function userText(mixed $content): ?string
    {
        if (is_string($content)) {
            return self::clean($content);
        }

        if (! is_array($content)) {
            return null;
        }

        $parts = [];

        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'tool_result') {
                return null;
            }

            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return $parts === [] ? null : self::clean(implode("\n", $parts));
    }

    /**
     * Visible assistant prose only — thinking blocks and tool calls are dropped.
     *
     * @return list<string>
     */
    private static function assistantText(mixed $content): array
    {
        if (! is_array($content)) {
            return [];
        }

        $parts = [];

        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text = trim($block['text']);

                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return $parts;
    }

    private static function clean(string $text): ?string
    {
        $text = trim(preg_replace('/<system-reminder>.*?<\/system-reminder>/s', '', $text) ?? $text);

        foreach (self::NOISE_PREFIXES as $prefix) {
            if (str_starts_with($text, $prefix)) {
                return null;
            }
        }

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
