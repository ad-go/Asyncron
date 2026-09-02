<?php

declare(strict_types=1);

namespace AdGo\Cluster;

/**
 * Bounded proc_open() wrapper - runs $cmd, gives it up to $maxSeconds to
 * finish on its own (polling proc_get_status() every 100ms, draining
 * stdout/stderr as it goes so a chatty child never blocks on a full pipe
 * buffer), then SIGKILLs it if it hasn't.
 *
 * SIGTERM (proc_open's/proc_terminate()'s own default) doesn't work here:
 * a child stuck inside an uninterruptible blocking call (a slow SSH
 * connect attempt, say) never even checks for a pending SIGTERM until
 * that call itself returns, and proc_close() blocks until the child
 * actually exits - so a "bounded" budget could silently become however
 * long that one blocking call takes. SIGKILL (9) can't be caught,
 * blocked, or deferred by the child at all - the OS ends it immediately,
 * which is what "bounded" actually needs to mean here.
 *
 * Found live 2026-08-22 as SettingsController::runBoundedSpark(), then
 * independently re-found and hand-copied (matching comment and all) into
 * LongPollController::burstOwnQueue() - this is that logic, shared, so a
 * future fix to one no longer risks silently missing the other.
 */
final class BoundedProcess
{
    /**
     * @return array{ran: bool, timedOut: bool, output: string, seconds: float}
     */
    public static function run(string $cmd, float $maxSeconds): array
    {
        $start = microtime(true);

        // bypass_shell: Windows-only, no-op on POSIX (where this actually
        // runs in production) - without it, proc_open() wraps $cmd through
        // cmd.exe on Windows, and proc_get_status()/proc_terminate() end up
        // tracking that wrapper instead of the real child, making the
        // 'running' status unreliable and SIGKILL below a no-op against the
        // process that's actually still running.
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (! is_resource($proc)) {
            return ['ran' => false, 'timedOut' => false, 'output' => '', 'seconds' => 0.0];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        while (microtime(true) - $start < $maxSeconds) {
            $output .= (string) stream_get_contents($pipes[1]);
            $output .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (! $status['running']) {
                break;
            }
            usleep(100000);
        }

        $timedOut = false;
        $status   = proc_get_status($proc);
        if ($status['running']) {
            @proc_terminate($proc, 9);
            $timedOut = true;
        }
        $output .= (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        @proc_close($proc);

        return [
            'ran'      => true,
            'timedOut' => $timedOut,
            'output'   => trim($output),
            'seconds'  => round(microtime(true) - $start, 1),
        ];
    }
}
