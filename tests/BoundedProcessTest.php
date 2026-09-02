<?php

declare(strict_types=1);

namespace Tests;

use AdGo\Cluster\BoundedProcess;
use PHPUnit\Framework\TestCase;

/**
 * The two behaviors that actually matter here (both previously verified
 * only by hand, live - see this class's own docblock for the incident):
 * a fast command completes normally within its budget with its output
 * captured, and a command that ignores its budget gets SIGKILLed rather
 * than left to finish on its own. That second case is the whole point of
 * this class existing instead of a plain proc_open()/proc_close() - a
 * regression there (falling back to the default SIGTERM, say) would
 * silently turn every "bounded" call back into an unbounded one.
 *
 * Each case runs a small standalone PHP script file, not `php -r "..."`
 * inline - escapeshellarg()'s quoting differs enough across platforms
 * (particularly Windows' cmd.exe wrapping) that inline code with its own
 * quotes/semicolons is a real source of test flakiness unrelated to
 * BoundedProcess itself; a plain script path sidesteps that entirely.
 */
final class BoundedProcessTest extends TestCase
{
    /** @var list<string> */
    private array $scriptsToClean = [];

    protected function tearDown(): void
    {
        foreach ($this->scriptsToClean as $path) {
            @unlink($path);
        }
        $this->scriptsToClean = [];

        parent::tearDown();
    }

    private function scriptCommand(string $phpCode): string
    {
        $path = tempnam(sys_get_temp_dir(), 'asyncron-bp-test-') . '.php';
        file_put_contents($path, "<?php\n" . $phpCode);
        $this->scriptsToClean[] = $path;

        return PHP_BINARY . ' ' . escapeshellarg($path);
    }

    public function testRunCapturesOutputFromACommandThatFinishesWithinBudget(): void
    {
        $cmd = $this->scriptCommand('echo "hello-bounded-process";');

        $result = BoundedProcess::run($cmd, 5.0);

        $this->assertTrue($result['ran']);
        $this->assertFalse($result['timedOut']);
        $this->assertSame('hello-bounded-process', $result['output']);
    }

    public function testRunSigkillsAndReportsTimeoutForACommandThatOutlivesItsBudget(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // stream_set_blocking(false) on a proc_open() pipe is
            // unreliable on Windows (long-standing PHP/Windows platform
            // limitation - anonymous pipes there don't support
            // non-blocking mode the way POSIX pipes do): the very first
            // stream_get_contents() call in run()'s polling loop blocks
            // until the child's pipe closes, i.e. until it exits on its
            // own - defeating the poll-and-kill budget entirely.
            // Confirmed live 2026-08-30 with a standalone repro isolating
            // proc_terminate() itself (works correctly here) from the
            // pipe-read step (blocks for the child's full runtime).
            // Production only ever runs this on Linux (h1q/bak/res),
            // where POSIX non-blocking pipes behave correctly - this is
            // a test-environment gap, not a behavior this class can fix
            // without risking the actually-used POSIX pipe-draining path.
            $this->markTestSkipped('proc_open() pipe non-blocking mode is unreliable on Windows - see comment above.');
        }

        // sleep(30) ignores nothing special - it's simply not done by the
        // time the 1s budget expires, exactly like a slow SSH connect
        // attempt the budget is meant to guard against.
        $cmd = $this->scriptCommand('sleep(30);');

        $start   = microtime(true);
        $result  = BoundedProcess::run($cmd, 1.0);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($result['ran']);
        $this->assertTrue($result['timedOut']);
        // Proves the child was actually killed rather than merely
        // reported as timed-out while still running in the background -
        // this call must return close to the 1s budget, nowhere near the
        // child's own 30s sleep.
        $this->assertLessThan(10.0, $elapsed);
    }
}
