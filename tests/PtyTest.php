<?php

declare(strict_types=1);

namespace PhPty\Pty\Tests;

use PhPty\Pty\Pty;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class PtyTest extends TestCase
{
    /** @var list<Pty> */
    private array $spawned = [];

    protected function tear_down(): void
    {
        foreach ($this->spawned as $pty) {
            $pty->close();
        }
        $this->spawned = [];
    }

    private function spawn(array $command, int $rows, int $cols): Pty
    {
        return $this->spawned[] = Pty::spawn($command, $rows, $cols);
    }

    protected function set_up(): void
    {
        foreach (['FFI', 'pcntl', 'posix'] as $extension) {
            if (!\extension_loaded($extension)) {
                $this->markTestSkipped("The {$extension} extension is required to exercise Pty.");
            }
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Pty is Unix-only.');
        }
    }

    public function testSpawnRunsTheCommandAndReadsItsOutput(): void
    {
        $pty = $this->spawn(['/bin/echo', 'hello from pty'], 24, 80);

        $this->assertGreaterThan(0, $pty->pid());
        $this->assertStringContainsString('hello from pty', $this->readToEnd($pty));
    }

    public function testWriteReachesTheChildStdin(): void
    {
        // The child reads one line and echoes it back, then exits — so the
        // output ends and readToEnd() terminates.
        $pty = $this->spawn(['/bin/sh', '-c', 'read line; echo "got:$line"'], 24, 80);
        $pty->write("ping\n");

        $this->assertStringContainsString('got:ping', $this->readToEnd($pty));
    }

    public function testTheChildSeesTheRequestedWindowSize(): void
    {
        // stty size prints "rows cols" for the controlling terminal. spawn sizes
        // the child's Tty in-band before the command runs, so this holds even
        // across the several spawns a test run makes in one process — where the
        // pty-layer winp does not (ADR-0012).
        $pty = $this->spawn(['/bin/sh', '-c', 'stty size'], 7, 30);

        $this->assertStringContainsString('7 30', $this->readToEnd($pty));
    }

    public function testAnEmptyCommandIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Pty::spawn([], 24, 80);
    }

    public function testReadingAfterCloseIsAnError(): void
    {
        $pty = $this->spawn(['/bin/echo', 'x'], 24, 80);
        $pty->close();

        $this->expectException(\RuntimeException::class);
        $pty->read();
    }

    /**
     * Poll the non-blocking Controller until the child has exited and its output
     * is drained.
     */
    private function readToEnd(Pty $pty): string
    {
        $output = '';
        while (true) {
            $chunk = $pty->read();
            if ($chunk !== '') {
                $output .= $chunk;
                continue;
            }
            if (!$pty->isRunning()) {
                while (($chunk = $pty->read()) !== '') {
                    $output .= $chunk;
                }

                return $output;
            }
            \usleep(2000);
        }
    }
}
