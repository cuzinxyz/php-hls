<?php
declare(strict_types=1);

namespace Src;

final class JobRepository
{
	private array $config;

	public function __construct(array $config)
	{
		$this->config = $config;
	}

	public static function ensureDirectories(array $config): void
	{
		$jobsDir = $config['paths']['jobs'] ?? (__DIR__ . '/../storage/jobs');
		if (!is_dir($jobsDir)) {
			@mkdir($jobsDir, 0777, true);
		}
	}

	private function jobFile(string $jobId): string
	{
		return rtrim($this->config['paths']['jobs'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $jobId . '.json';
	}

	public function createJob(string $jobId, array $data): void
	{
		$this->write($jobId, $data);
	}

	public function updateStatus(string $jobId, string $status, array $extra = []): void
	{
		$job = $this->getJob($jobId) ?? [];
		$job['status'] = $status;
		$job['updated_at'] = time();
		foreach ($extra as $k => $v) {
			$job[$k] = $v;
		}
		$this->write($jobId, $job);
	}

	public function getJob(string $jobId): ?array
	{
		$file = $this->jobFile($jobId);
		if (!file_exists($file)) {
			return null;
		}
		$json = @file_get_contents($file);
		$data = json_decode($json ?: 'null', true);
		return is_array($data) ? $data : null;
	}

	private function write(string $jobId, array $data): void
	{
		$file = $this->jobFile($jobId);
		$tmp = $file . '.tmp';
		@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		@rename($tmp, $file);
	}
}


