<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Transcript Sources
    |--------------------------------------------------------------------------
    |
    | Claude Code and Codex both write structured JSONL transcripts to disk.
    | The prompt log is rebuilt from those files rather than intercepting
    | sessions, so a sync is idempotent and can backfill old history.
    |
    */

    'claude_projects' => env('PROMPT_LOG_CLAUDE_DIR', env('HOME').'/.claude/projects'),

    'codex_sessions' => env('PROMPT_LOG_CODEX_DIR', env('HOME').'/.codex/sessions'),

    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    |
    | The committed log directory. `log.jsonl` is the durable append-only
    | record; the Markdown files are a regenerated rendering of it.
    |
    */

    'output' => env('PROMPT_LOG_OUTPUT', base_path('prompt-log')),

    /*
    |--------------------------------------------------------------------------
    | Display Timezone
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored as UTC and rendered in this timezone.
    |
    */

    'timezone' => env('PROMPT_LOG_TIMEZONE', 'America/Chicago'),

];
