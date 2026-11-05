<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Runs a task in N real OS processes (pcntl_fork) that start at the same instant.
 *
 * Why processes and not a loop: PHP executes one request at a time, so a loop can
 * never exercise row locks. Each forked child opens its OWN PostgreSQL connection,
 * exactly like N PHP-FPM / FrankenPHP workers handling N simultaneous requests.
 */
final class Parallel
{
    /**
     * @param  Closure(int): string  $task  receives the worker index, returns a short result label
     * @return list<string> one label per worker
     */
    public static function run(int $workers, Closure $task): array
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            throw new RuntimeException('The pcntl and posix extensions are required for concurrency tests.');
        }

        $dir = sys_get_temp_dir().'/parallel-'.bin2hex(random_bytes(6));
        mkdir($dir);

        // A forked child must never reuse the parent's socket: close it before forking.
        DB::disconnect();

        $startAt = microtime(true) + 0.5;
        $pids = [];

        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('fork failed');
            }

            if ($pid === 0) {
                // ---- child process -------------------------------------------------
                // Barrier: all workers fire together to maximise contention.
                while (microtime(true) < $startAt) {
                    usleep(200);
                }

                try {
                    $result = $task($i);
                } catch (Throwable $e) {
                    $result = 'exception:'.class_basename($e).':'.$e->getMessage();
                }

                file_put_contents($dir.'/'.$i, $result);
                DB::disconnect();

                // Skip PHPUnit/Laravel shutdown handlers of the copied parent state.
                posix_kill(getmypid(), SIGKILL);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];
        for ($i = 0; $i < $workers; $i++) {
            $file = $dir.'/'.$i;
            $results[] = is_file($file) ? (string) file_get_contents($file) : 'missing';
            @unlink($file);
        }
        @rmdir($dir);

        return $results;
    }
}
