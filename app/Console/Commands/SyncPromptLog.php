<?php

namespace App\Console\Commands;

use App\Support\PromptLog\ClaudeTranscript;
use App\Support\PromptLog\CodexTranscript;
use App\Support\PromptLog\Exchange;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SyncPromptLog extends Command
{
    protected $signature = 'prompts:sync
        {--quiet-summary : Suppress the per-run summary line}
        {--watch= : Keep running, re-syncing every N seconds (used by the Solo "Prompt log" process)}';

    protected $description = 'Rebuild the committed prompt log from Claude Code and Codex transcripts';

    public function handle(): int
    {
        $interval = (int) $this->option('watch');

        if ($interval > 0) {
            $this->info("Watching for new Claude and Codex sessions every {$interval}s");

            while (true) {
                $this->sync();
                sleep($interval);
            }
        }

        return $this->sync();
    }

    /**
     * Rebuild the log from every transcript this project owns.
     */
    private function sync(): int
    {
        $project = rtrim(base_path(), '/');

        $exchanges = $this->claudeExchanges($project)
            ->merge($this->codexExchanges($project))
            ->unique(fn (Exchange $exchange): string => $exchange->fingerprint())
            ->sortBy(fn (Exchange $exchange): string => $exchange->timestamp->toIso8601ZuluString())
            ->values();

        $output = rtrim(config('prompt-log.output'), '/');

        if (! is_dir($output)) {
            mkdir($output, 0755, true);
        }

        $this->writeJsonl($exchanges, $output);
        $this->writeMarkdown($exchanges, $output);

        if (! $this->option('quiet-summary')) {
            $this->info("Logged {$exchanges->count()} exchanges to {$output}");
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Exchange>
     */
    private function claudeExchanges(string $project): Collection
    {
        $directory = rtrim(config('prompt-log.claude_projects'), '/').'/'.str_replace('/', '-', $project);

        return collect(glob($directory.'/*.jsonl') ?: [])
            ->flatMap(fn (string $file): array => ClaudeTranscript::parse($file));
    }

    /**
     * @return Collection<int, Exchange>
     */
    private function codexExchanges(string $project): Collection
    {
        $directory = rtrim(config('prompt-log.codex_sessions'), '/');

        return collect(glob($directory.'/*/*/*/rollout-*.jsonl') ?: [])
            ->filter(fn (string $file): bool => CodexTranscript::belongsToProject($file, $project))
            ->flatMap(fn (string $file): array => CodexTranscript::parse($file, $project));
    }

    /**
     * @param  Collection<int, Exchange>  $exchanges
     */
    private function writeJsonl(Collection $exchanges, string $output): void
    {
        $lines = $exchanges
            ->map(fn (Exchange $exchange): string => json_encode($exchange->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->implode("\n");

        file_put_contents($output.'/log.jsonl', $lines === '' ? '' : $lines."\n");
    }

    /**
     * @param  Collection<int, Exchange>  $exchanges
     */
    private function writeMarkdown(Collection $exchanges, string $output): void
    {
        $timezone = config('prompt-log.timezone');

        foreach (glob($output.'/*.md') ?: [] as $stale) {
            unlink($stale);
        }

        $days = $exchanges->groupBy(fn (Exchange $exchange): string => $exchange->timestamp->setTimezone($timezone)->format('Y-m-d'));

        foreach ($days as $day => $entries) {
            $markdown = "# {$day}\n";

            foreach ($entries as $exchange) {
                $time = $exchange->timestamp->setTimezone($timezone)->format('H:i');
                $quoted = collect(explode("\n", $exchange->prompt))
                    ->map(fn (string $line): string => rtrim('> '.$line))
                    ->implode("\n");

                $markdown .= "\n## {$time} · {$exchange->model} · {$exchange->tool}\n\n";
                $markdown .= "**Prompt**\n\n{$quoted}\n\n";
                $markdown .= "**Response**\n\n{$exchange->response}\n";
            }

            file_put_contents($output.'/'.$day.'.md', $markdown);
        }

        $this->writeIndex($days, $output);
    }

    /**
     * @param  Collection<string, Collection<int, Exchange>>  $days
     */
    private function writeIndex(Collection $days, string $output): void
    {
        $index = "# Prompt Log\n\n";
        $index .= "Every prompt I gave Claude Code and Codex on this project, with the model that answered and what it said back. Tool calls are omitted.\n\n";
        $index .= "Rebuilt from the agents' own session transcripts by `php artisan prompts:sync` — `log.jsonl` is the record, the dated Markdown files are a rendering of it.\n\n";

        foreach ($days->sortKeysDesc() as $day => $entries) {
            $models = $entries->map(fn (Exchange $exchange): string => $exchange->model)->unique()->sort()->implode(', ');
            $label = $entries->count() === 1 ? 'exchange' : 'exchanges';
            $index .= "- [{$day}]({$day}.md) — {$entries->count()} {$label} · {$models}\n";
        }

        file_put_contents($output.'/README.md', $index);
    }
}
