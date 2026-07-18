<?php

declare(strict_types=1);

namespace PhPty\Pty;

use FFI;

/**
 * A pseudo-terminal with a child process running on it. spawn() creates the pair
 * and starts the child on the Device end; the parent keeps the Controller end,
 * reading the child's output and writing its input. See pty/CONTEXT.md and
 * docs/adr/0012-pty-spawn-openpty-pcntl-fork-inband-winsize.md.
 *
 * The pair is made with openpty and the child forked with pcntl_fork — a
 * PHP-managed fork rather than the C-level one inside forkpty(3). The window size
 * is set in-band, by having the child run `stty` on its own Tty before the
 * command, because setting it at the pty layer (openpty/forkpty winp) proved
 * unreliable across repeated spawns in one PHP process.
 */
final class Pty
{
    private FFI $libc;
    private int $controller;
    private int $pid;
    private bool $closed = false;
    private bool $exited = false;

    private function __construct(FFI $libc, int $controller, int $pid)
    {
        $this->libc = $libc;
        $this->controller = $controller;
        $this->pid = $pid;
    }

    /**
     * Start a child process on a new pseudo-terminal of the given size.
     *
     * @param list<string> $command argv; the first element is the program
     */
    public static function spawn(array $command, int $rows, int $cols, ?FFI $libc = null): self
    {
        if ($command === []) {
            throw new \InvalidArgumentException('A command is required to spawn.');
        }
        if ($rows < 1 || $cols < 1) {
            throw new \InvalidArgumentException('rows and cols must be positive.');
        }
        $libc = $libc ?? Libc::load();

        $controller = $libc->new('int');
        $device = $libc->new('int');
        if ($libc->openpty(FFI::addr($controller), FFI::addr($device), null, null, null) !== 0) {
            throw new \RuntimeException('openpty failed.');
        }
        $controllerFd = $controller->cdata;
        $deviceFd = $device->cdata;

        $pid = \pcntl_fork();
        if ($pid === -1) {
            $libc->close($controllerFd);
            $libc->close($deviceFd);
            throw new \RuntimeException('pcntl_fork failed.');
        }

        if ($pid === 0) {
            self::runChild($libc, self::withWindowSize($command, $rows, $cols), $controllerFd, $deviceFd);
            \posix_kill(\posix_getpid(), \SIGKILL); // unreachable safety net
        }

        $libc->close($deviceFd);

        // Read the Controller without blocking, so a Sync can drain what is
        // available and stop rather than wait for output that may never come.
        $flags = $libc->fcntl($controllerFd, Libc::F_GETFL, 0);
        $libc->fcntl($controllerFd, Libc::F_SETFL, $flags | Libc::O_NONBLOCK);

        return new self($libc, $controllerFd, $pid);
    }

    /**
     * Wrap the command so the child sizes its own Tty before running it. The
     * command elements stay separate argv entries — `exec "$@"` runs them
     * without re-parsing, so there is nothing to quote and nothing to inject.
     *
     * @param list<string> $command
     * @return list<string>
     */
    private static function withWindowSize(array $command, int $rows, int $cols): array
    {
        $script = \sprintf('stty rows %d cols %d; exec "$@"', $rows, $cols);

        return \array_merge(['/bin/sh', '-c', $script, 'sh'], $command);
    }

    /**
     * In the forked child: adopt the Device as controlling Tty and stdio, then
     * become the command. Never returns.
     *
     * @param list<string> $command
     */
    private static function runChild(FFI $libc, array $command, int $controllerFd, int $deviceFd): void
    {
        \posix_setsid();
        $libc->ioctl($deviceFd, Libc::TIOCSCTTY, 0);
        $libc->dup2($deviceFd, 0);
        $libc->dup2($deviceFd, 1);
        $libc->dup2($deviceFd, 2);
        if ($deviceFd > 2) {
            $libc->close($deviceFd);
        }
        $libc->close($controllerFd);

        @\pcntl_exec($command[0], \array_slice($command, 1));
        // exec only returns on failure; leave hard, skipping PHP shutdown.
        \posix_kill(\posix_getpid(), \SIGKILL);
    }

    public function pid(): int
    {
        return $this->pid;
    }

    /** Whether the child is still running. Reaps it if it has just exited. */
    public function isRunning(): bool
    {
        if ($this->closed || $this->exited) {
            return false;
        }
        $result = \pcntl_waitpid($this->pid, $status, \WNOHANG);
        if ($result !== 0) {
            $this->exited = true;

            return false;
        }

        return true;
    }

    /** Write bytes to the Controller — the child sees them on its stdin. */
    public function write(string $bytes): int
    {
        $this->assertOpen();
        $length = \strlen($bytes);
        if ($length === 0) {
            return 0;
        }
        $buffer = $this->libc->new("char[{$length}]", false);
        FFI::memcpy($buffer, $bytes, $length);
        $written = $this->libc->write($this->controller, $buffer, $length);
        FFI::free($buffer);

        return (int) $written;
    }

    /**
     * Read up to $length bytes the child has written. Returns '' at end of
     * output — when the child has closed the Device, whether that surfaces as
     * EOF (macOS) or EIO (Linux).
     */
    public function read(int $length = 4096): string
    {
        $this->assertOpen();
        $buffer = $this->libc->new("char[{$length}]", false);
        $count = $this->libc->read($this->controller, $buffer, $length);
        $result = $count > 0 ? FFI::string($buffer, $count) : '';
        FFI::free($buffer);

        return $result;
    }

    /** Reap the child and release the Controller. Idempotent. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->libc->close($this->controller);
        if (!$this->exited) {
            // Kill first, then reap: a still-running Subject (e.g. one blocked on
            // input) would otherwise leave waitpid blocking forever.
            @\posix_kill($this->pid, \SIGKILL);
            \pcntl_waitpid($this->pid, $status);
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \RuntimeException('The Pty is closed.');
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
