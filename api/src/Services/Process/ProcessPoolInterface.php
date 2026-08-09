<?php

namespace App\Services\Process;

/**
 * Runs several OS processes at once and hands back what each one produced.
 *
 * Abstracted so the callers that fan work out across child processes stay
 * unit-testable: the real implementation is the only piece that touches
 * proc_open, and a fake can replay canned results.
 */
interface ProcessPoolInterface
{
    /**
     * Start every command, wait for all of them, return their results in the
     * same order as the input. Never throws for a child that failed — a
     * non-zero exit code is a result, not an exception.
     *
     * Each command is an argv array (`['php', 'script.php', '--worker']`), not
     * a shell string: no quoting rules to get right, no shell to inject into.
     *
     * @param  array<int, string[]> $commands
     * @return array<int, array{exit_code: int, stdout: string, stderr: string}>
     */
    public function runConcurrently(array $commands): array;
}
