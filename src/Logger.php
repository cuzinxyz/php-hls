<?php
declare(strict_types=1);

namespace Src;

final class Logger
{
	private array $config;
	private string $jobId;

	public function __construct(array $config, string $jobId)
	{
		$this->config = $config;
		$this->jobId = $jobId;
	}

	public static function ensureDirectories(array $config): void
	{
		$logsDir = $config['paths']['logs'] ?? (__DIR__ . '/../storage/logs');
		if (!is_dir($logsDir)) {
			@mkdir($logsDir, 0777, true);
		}
	}

	public function path(): string
	{
		return rtrim($this->config['paths']['logs'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->jobId . '.log';
	}

	public function log(string $message): void
	{
		$line = '[' . date('Y-m-d H:i:s') . "] " . $message . "\n";
		@file_put_contents($this->path(), $line, FILE_APPEND | LOCK_EX);
	}

	public function tail(int $maxLines): array
	{
		$file = $this->path();
		if (!file_exists($file)) {
			return [];
		}
		$lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
		$tail = array_slice($lines, -$maxLines);
		return array_values($tail);
	}
}


