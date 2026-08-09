<?php

namespace App\Services\Process;

/**
 * proc_open-backed pool. The scheduler image ships without ext-pcntl, so
 * forking is out; spawning real child processes is the portable option, and it
 * has the nice side effect of giving each child its own PDO connection instead
 * of sharing a socket that a fork would have duplicated.
 */
class ProcOpenProcessPool implements ProcessPoolInterface
{
    /** Poll interval while waiting for children, in microseconds. */
    private const POLL_INTERVAL_US = 50_000;

    public function runConcurrently(array $commands): array
    {
        $results = [];
        $running = [];

        foreach ($commands as $index => $command) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = @proc_open($command, $descriptors, $pipes);

            if (!is_resource($process)) {
                $results[$index] = [
                    'exit_code' => -1,
                    'stdout' => '',
                    'stderr' => 'proc_open failed',
                ];
                continue;
            }

            // The child never reads stdin; leaving it open would make it hang
            // if it ever tried.
            fclose($pipes[0]);

            // Non-blocking so one silent child cannot stall the reads of the
            // others. We still drain in the loop below, which is what keeps a
            // chatty child from deadlocking on a full pipe buffer.
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $running[$index] = [
                'process' => $process,
                'pipes' => $pipes,
                'stdout' => '',
                'stderr' => '',
            ];
        }

        while (!empty($running)) {
            foreach ($running as $index => $child) {
                $running[$index]['stdout'] .= (string) stream_get_contents($child['pipes'][1]);
                $running[$index]['stderr'] .= (string) stream_get_contents($child['pipes'][2]);

                $status = proc_get_status($child['process']);
                if ($status['running']) {
                    continue;
                }

                // proc_get_status() only reports the real exit code on the
                // first call after termination — later calls, and proc_close()
                // once the child is already reaped, return -1. Keep this one.
                $exitCode = (int) $status['exitcode'];

                // Anything written between the read above and the exit.
                $running[$index]['stdout'] .= (string) stream_get_contents($child['pipes'][1]);
                $running[$index]['stderr'] .= (string) stream_get_contents($child['pipes'][2]);

                fclose($child['pipes'][1]);
                fclose($child['pipes'][2]);
                proc_close($child['process']);

                $results[$index] = [
                    'exit_code' => $exitCode,
                    'stdout' => $running[$index]['stdout'],
                    'stderr' => $running[$index]['stderr'],
                ];
                unset($running[$index]);
            }

            if (!empty($running)) {
                usleep(self::POLL_INTERVAL_US);
            }
        }

        ksort($results);

        return $results;
    }
}
