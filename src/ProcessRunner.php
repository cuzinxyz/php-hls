<?php
declare(strict_types=1);

namespace Src;

final class ProcessRunner
{
	public static function buildPriorityPrefix(): string
	{
		if (Config::osIsWindows()) {
			// Use low priority on Windows
			return 'start /B /LOW ';
		}
		// Use nice and ionice when available on Linux
		$parts = [];
		if (self::isCommandAvailable('ionice')) {
			$parts[] = 'ionice -c2 -n7';
		}
		if (self::isCommandAvailable('nice')) {
			$parts[] = 'nice -n 10';
		}
		return $parts ? (implode(' ', $parts) . ' ') : '';
	}

	/**
	 * Execute a command with timeout, stream output to log, returns exit code.
	 */
	public static function run(string $command, int $timeoutSeconds, callable $onStdout, callable $onStderr): int
	{
		$descriptors = [
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$env = null;
		$cwd = null;
		$cmd = $command;
		$process = proc_open($cmd, $descriptors, $pipes, $cwd, $env);
		if (!is_resource($process)) {
			throw new \RuntimeException('Failed to start process');
		}
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$start = time();
		$exitCode = null;
		try {
			while (true) {
				$stdout = stream_get_contents($pipes[1]) ?: '';
				$stderr = stream_get_contents($pipes[2]) ?: '';
				if ($stdout !== '') { $onStdout($stdout); }
				if ($stderr !== '') { $onStderr($stderr); }
				$status = proc_get_status($process);
				if (!$status['running']) {
					$exitCode = $status['exitcode'];
					break;
				}
				if (time() - $start > $timeoutSeconds) {
					// Kill process tree
					self::terminateProcess($process, $status['pid'] ?? null);
					$exitCode = 124; // timeout
					break;
				}
				usleep(100_000);
			}
		} finally {
			foreach ($pipes as $p) { if (is_resource($p)) { fclose($p); } }
			if (is_resource($process)) { proc_close($process); }
		}
		return (int)$exitCode;
	}

	private static function terminateProcess($process, ?int $pid): void
	{
		if (Config::osIsWindows()) {
			if ($pid) {
				@pclose(popen('taskkill /F /T /PID ' . (int)$pid, 'r'));
			}
			return;
		}
		$proc = proc_get_status($process);
		$pid = $pid ?: ($proc['pid'] ?? null);
		if ($pid) {
			if (self::isCommandAvailable('pkill')) {
				@exec('pkill -TERM -P ' . (int)$pid . ' 2>/dev/null');
			}
			@exec('kill -TERM ' . (int)$pid . ' 2>/dev/null');
		}
	}

	private static function isCommandAvailable(string $name): bool
	{
		$which = @trim((string)shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null || which ' . escapeshellarg($name) . ' 2>/dev/null'));
		return $which !== '';
	}
}


