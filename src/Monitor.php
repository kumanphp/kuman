<?php

declare(strict_types=1);

namespace Kuman;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Workerman\Timer;

/**
 * Reloads workers when application files change and recycles workers that outgrow
 * the memory limit. Adapted from the webman Monitor process (MIT, see LICENSE).
 */
final class Monitor
{
    private const SKIP = ['.git', 'node_modules', 'public', 'runtime', 'tests', 'vendor'];

    private const EXTENSIONS = ['php', 'html', 'env'];

    private int $lastMtime;

    private int $masterPid;

    /** @var array<string, true> Inherited from the master, so a reload cannot refresh them. */
    private array $masterFiles;

    public function __construct(private readonly string $basePath, private readonly int|string|null $memoryLimit = null)
    {
        $this->lastMtime = time();
        $this->masterPid = posix_getppid();
        $this->masterFiles = array_fill_keys(get_included_files(), true);
    }

    public function start(bool $watch): void
    {
        if ($watch) {
            Timer::add(1, $this->checkFiles(...));
        }
        $limit = $this->memoryLimitInMb();
        if ($limit > 0) {
            Timer::add(60, fn() => $this->checkMemory($limit));
        }
    }

    private function checkFiles(): void
    {
        clearstatcache();
        foreach ($this->files() as $file) {
            if ($file->getMTime() <= $this->lastMtime || !in_array($file->getExtension(), self::EXTENSIONS, true)) {
                continue;
            }
            $this->lastMtime = $file->getMTime();
            $path = (string)$file->getRealPath();
            if (isset($this->masterFiles[$path])) {
                echo "$path changed, restart Kuman to apply it\n";
                continue;
            }
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
            if ($status !== 0) {
                echo implode("\n", $output), "\n";
                continue;
            }
            echo "$path changed, reloading\n";
            posix_kill($this->masterPid, SIGUSR1);
            return;
        }
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function files(): iterable
    {
        $directory = new RecursiveDirectoryIterator($this->basePath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS);
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            static fn(SplFileInfo $file) => !in_array($file->getFilename(), self::SKIP, true)
        );
        return new RecursiveIteratorIterator($filter);
    }

    private function checkMemory(int $limit): void
    {
        $children = @file_get_contents("/proc/$this->masterPid/task/$this->masterPid/children");
        foreach (explode(' ', trim((string)$children)) as $pid) {
            $status = @file_get_contents("/proc/$pid/status");
            if ($status && preg_match('/VmRSS:\s*(\d+) kB/', $status, $match) && (int)$match[1] / 1024 >= $limit) {
                posix_kill((int)$pid, SIGINT);
            }
        }
    }

    /**
     * An explicit limit wins, otherwise 80% of memory_limit. Zero disables the check.
     */
    private function memoryLimitInMb(): int
    {
        if ($this->memoryLimit !== null) {
            return (int)$this->memoryLimit;
        }
        $bytes = ini_parse_quantity((string)ini_get('memory_limit'));
        return $bytes > 0 ? max(50, (int)($bytes * 0.8 / 1048576)) : 0;
    }
}
