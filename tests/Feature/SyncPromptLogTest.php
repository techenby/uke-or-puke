<?php

use Illuminate\Support\Str;

beforeEach(function () {
    $this->logRoot = base_path('tests/tmp/'.Str::random(8));
    $this->claudeDir = $this->logRoot.'/claude';
    $this->codexDir = $this->logRoot.'/codex/2026/09/08';
    $this->output = $this->logRoot.'/out';

    mkdir($this->claudeDir.'/'.str_replace('/', '-', rtrim(base_path(), '/')), 0755, true);
    mkdir($this->codexDir, 0755, true);

    config([
        'prompt-log.claude_projects' => $this->claudeDir,
        'prompt-log.codex_sessions' => $this->logRoot.'/codex',
        'prompt-log.output' => $this->output,
        'prompt-log.timezone' => 'America/Chicago',
    ]);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->logRoot));
});

function writeClaudeTranscript(string $dir, array $entries): void
{
    $lines = array_map(fn (array $entry): string => json_encode($entry), $entries);
    file_put_contents($dir.'/session-abc.jsonl', implode("\n", $lines)."\n");
}

function writeCodexRollout(string $dir, array $entries): void
{
    $lines = array_map(fn (array $entry): string => json_encode($entry), $entries);
    file_put_contents($dir.'/rollout-2026-09-08T09-00-00-session-xyz.jsonl', implode("\n", $lines)."\n");
}

function logged(string $output): array
{
    $path = $output.'/log.jsonl';

    if (! file_exists($path) || trim(file_get_contents($path)) === '') {
        return [];
    }

    return array_map(fn (string $line): array => json_decode($line, true), file($path, FILE_IGNORE_NEW_LINES));
}

it('logs claude prompts with the model that answered', function () {
    writeClaudeTranscript($this->claudeDir.'/'.str_replace('/', '-', rtrim(base_path(), '/')), [
        ['type' => 'user', 'timestamp' => '2026-09-08T15:00:00Z', 'message' => ['role' => 'user', 'content' => 'add a chord chart']],
        ['type' => 'assistant', 'timestamp' => '2026-09-08T15:00:05Z', 'message' => ['role' => 'assistant', 'model' => 'claude-opus-5', 'content' => [['type' => 'text', 'text' => 'Chart added.']]]],
    ]);

    $this->artisan('prompts:sync')->assertSuccessful();

    expect(logged($this->output))->toHaveCount(1)
        ->and(logged($this->output)[0])
        ->toMatchArray([
            'tool' => 'claude',
            'model' => 'claude-opus-5',
            'prompt' => 'add a chord chart',
            'response' => 'Chart added.',
        ]);
});

it('drops tool results, sidechains, and synthetic errors from claude transcripts', function () {
    writeClaudeTranscript($this->claudeDir.'/'.str_replace('/', '-', rtrim(base_path(), '/')), [
        ['type' => 'user', 'timestamp' => '2026-09-08T15:00:00Z', 'message' => ['role' => 'user', 'content' => 'real prompt']],
        ['type' => 'assistant', 'timestamp' => '2026-09-08T15:00:01Z', 'isApiErrorMessage' => true, 'message' => ['role' => 'assistant', 'model' => '<synthetic>', 'content' => [['type' => 'text', 'text' => 'Login expired']]]],
        ['type' => 'assistant', 'timestamp' => '2026-09-08T15:00:02Z', 'message' => ['role' => 'assistant', 'model' => 'claude-opus-5', 'content' => [['type' => 'thinking', 'thinking' => 'hidden'], ['type' => 'tool_use', 'name' => 'Bash'], ['type' => 'text', 'text' => 'visible answer']]]],
        ['type' => 'user', 'timestamp' => '2026-09-08T15:00:03Z', 'message' => ['role' => 'user', 'content' => [['type' => 'tool_result', 'content' => 'command output']]]],
        ['type' => 'user', 'timestamp' => '2026-09-08T15:00:04Z', 'isSidechain' => true, 'message' => ['role' => 'user', 'content' => 'subagent prompt']],
        ['type' => 'user', 'timestamp' => '2026-09-08T15:00:05Z', 'message' => ['role' => 'user', 'content' => '<command-name>/login</command-name>']],
    ]);

    $this->artisan('prompts:sync')->assertSuccessful();

    $entries = logged($this->output);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['prompt'])->toBe('real prompt')
        ->and($entries[0]['response'])->toBe('visible answer');
});

it('logs codex prompts and skips injected context blocks', function () {
    writeCodexRollout($this->codexDir, [
        ['type' => 'session_meta', 'timestamp' => '2026-09-08T14:00:00Z', 'payload' => ['session_id' => 'xyz', 'cwd' => rtrim(base_path(), '/')]],
        ['type' => 'turn_context', 'timestamp' => '2026-09-08T14:00:01Z', 'payload' => ['cwd' => rtrim(base_path(), '/'), 'model' => 'gpt-6-astra']],
        ['type' => 'response_item', 'timestamp' => '2026-09-08T14:00:02Z', 'payload' => ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => '<environment_context>cwd stuff</environment_context>']]]],
        ['type' => 'response_item', 'timestamp' => '2026-09-08T14:00:03Z', 'payload' => ['type' => 'message', 'role' => 'developer', 'content' => [['type' => 'input_text', 'text' => 'skills instructions']]]],
        ['type' => 'response_item', 'timestamp' => '2026-09-08T14:00:04Z', 'payload' => ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'build the arcade screen']]]],
        ['type' => 'response_item', 'timestamp' => '2026-09-08T14:00:05Z', 'payload' => ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Screen built.']]]],
    ]);

    $this->artisan('prompts:sync')->assertSuccessful();

    expect(logged($this->output))->toHaveCount(1)
        ->and(logged($this->output)[0])->toMatchArray([
            'tool' => 'codex',
            'model' => 'gpt-6-astra',
            'prompt' => 'build the arcade screen',
            'response' => 'Screen built.',
        ]);
});

it('ignores codex sessions from other projects', function () {
    writeCodexRollout($this->codexDir, [
        ['type' => 'session_meta', 'timestamp' => '2026-09-08T14:00:00Z', 'payload' => ['session_id' => 'other', 'cwd' => '/Users/techenby/Sites/Tighten/client']],
        ['type' => 'turn_context', 'timestamp' => '2026-09-08T14:00:01Z', 'payload' => ['cwd' => '/Users/techenby/Sites/Tighten/client', 'model' => 'gpt-6-astra']],
        ['type' => 'response_item', 'timestamp' => '2026-09-08T14:00:02Z', 'payload' => ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'client secret work']]]],
        ['type' => 'response_item', 'timestamp' => '2026-09-08T14:00:03Z', 'payload' => ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'done']]]],
    ]);

    $this->artisan('prompts:sync')->assertSuccessful();

    expect(logged($this->output))->toBeEmpty();
});

it('renders dated markdown and an index, and stays idempotent across runs', function () {
    writeClaudeTranscript($this->claudeDir.'/'.str_replace('/', '-', rtrim(base_path(), '/')), [
        ['type' => 'user', 'timestamp' => '2026-09-08T15:00:00Z', 'message' => ['role' => 'user', 'content' => "line one\nline two"]],
        ['type' => 'assistant', 'timestamp' => '2026-09-08T15:00:05Z', 'message' => ['role' => 'assistant', 'model' => 'claude-opus-5', 'content' => [['type' => 'text', 'text' => 'Answer.']]]],
    ]);

    $this->artisan('prompts:sync')->assertSuccessful();
    $this->artisan('prompts:sync')->assertSuccessful();

    $markdown = file_get_contents($this->output.'/2026-09-08.md');

    expect(logged($this->output))->toHaveCount(1)
        ->and($markdown)->toContain('## 10:00 · claude-opus-5 · claude')
        ->and($markdown)->toContain("> line one\n> line two")
        ->and(file_get_contents($this->output.'/README.md'))->toContain('[2026-09-08](2026-09-08.md) — 1 exchange');
});
