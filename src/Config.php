<?php
declare(strict_types=1);

namespace Src;

final class Config
{
	public static function load(string $configPath): array
	{
		$defaults = [
			'paths' => [
				'root' => realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'),
				'uploads' => (realpath(__DIR__ . '/..') ?: (__DIR__ . '/..')) . '/storage/uploads',
				'hls' => (realpath(__DIR__ . '/..') ?: (__DIR__ . '/..')) . '/storage/hls',
				'jobs' => (realpath(__DIR__ . '/..') ?: (__DIR__ . '/..')) . '/storage/jobs',
				'logs' => (realpath(__DIR__ . '/..') ?: (__DIR__ . '/..')) . '/storage/logs',
			],
			'ffmpeg' => [
				'binary' => null, // auto-detect
				'threads' => max(1, (int)floor((float)(ini_get('pdo_mysql.default_socket') ? 2 : 0) + (float)@shell_exec('nproc 2>/dev/null') ?: 0)),
				'gpu' => false,
				'timeout_seconds' => 1800,
				'bitrate_ladder' => [
					['name' => '360p', 'width' => 640, 'height' => 360, 'videoK' => 800, 'audioK' => 96],
					['name' => '480p', 'width' => 842, 'height' => 480, 'videoK' => 1400, 'audioK' => 128],
					['name' => '720p', 'width' => 1280, 'height' => 720, 'videoK' => 2800, 'audioK' => 128],
					['name' => '1080p', 'width' => 1920, 'height' => 1080, 'videoK' => 5000, 'audioK' => 160],
				],
				'segment_seconds' => 6,
				'playlist_size' => 10,
			],
		];

		$user = [];
		if (file_exists($configPath)) {
			$user = require $configPath;
			if (!is_array($user)) {
				$user = [];
			}
		}

		$config = self::arrayMergeRecursiveDistinct($defaults, $user);
		self::ensureDirectories($config);
		$config['ffmpeg']['binary'] = self::detectFfmpegBinary($config);
		return $config;
	}

	private static function ensureDirectories(array $config): void
	{
		foreach (['uploads','hls','jobs','logs'] as $key) {
			$dir = $config['paths'][$key];
			if (!is_dir($dir)) {
				@mkdir($dir, 0777, true);
			}
		}
	}

	public static function detectFfmpegBinary(array $config): string
	{
		if (!empty($config['ffmpeg']['binary'])) {
			return (string)$config['ffmpeg']['binary'];
		}
		$possible = [];
		if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
			$possible = [
				'ffmpeg.exe',
				__DIR__ . '/../bin/windows/ffmpeg.exe',
				'C:\\ffmpeg\\bin\\ffmpeg.exe',
			];
		} else {
			$possible = [
				'/usr/bin/ffmpeg',
				'/usr/local/bin/ffmpeg',
				'/bin/ffmpeg',
				'ffmpeg',
			];
		}
		foreach ($possible as $bin) {
			$path = $bin;
			if ($bin === 'ffmpeg') {
				$which = @trim((string)shell_exec('which ffmpeg 2>/dev/null'));
				if ($which !== '') {
					$path = $which;
				}
			}
			if ($path !== '' && (is_executable($path) || self::isCallableOnWindows($path))) {
				return $path;
			}
		}
		return 'ffmpeg';
	}

	private static function isCallableOnWindows(string $path): bool
	{
		if (stripos(PHP_OS_FAMILY, 'Windows') === false) {
			return false;
		}
		return (bool)preg_match('/\\.exe$/i', $path) && file_exists($path);
	}

	private static function arrayMergeRecursiveDistinct(array $base, array $merge): array
	{
		foreach ($merge as $key => $value) {
			if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
				$base[$key] = self::arrayMergeRecursiveDistinct($base[$key], $value);
			} else {
				$base[$key] = $value;
			}
		}
		return $base;
	}

	public static function osIsWindows(): bool
	{
		return stripos(PHP_OS_FAMILY, 'Windows') !== false;
	}
}


