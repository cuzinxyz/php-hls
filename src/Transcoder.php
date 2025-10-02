<?php
declare(strict_types=1);

namespace Src;

final class Transcoder
{
	private array $config;
	private Logger $logger;

	public function __construct(array $config, Logger $logger)
	{
		$this->config = $config;
		$this->logger = $logger;
	}

	public function transcodeAsync(string $jobId, string $inputPath): void
	{
		$php = self::detectPhpBinary();
		$root = rtrim($this->config['paths']['root'], DIRECTORY_SEPARATOR);
		$script = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'transcode.php';
		$cmd = '"' . $php . '" ' . escapeshellarg($script) . ' ' . escapeshellarg($jobId) . ' ' . escapeshellarg($inputPath);
		if (Config::osIsWindows()) {
			// Use cmd to spawn detached background process on Windows
			$background = 'cmd.exe /C start "hls-transcode" /B ' . $cmd;
			$this->logger->log('Spawn (win): ' . $background);
			@pclose(@popen($background, 'r'));
			return;
		}
		$background = ProcessRunner::buildPriorityPrefix() . $cmd . ' >/dev/null 2>&1 &';
		@exec($background);
	}

	public function transcodeSync(string $jobId, string $inputPath): int
	{
		$repo = new JobRepository($this->config);
		$repo->updateStatus($jobId, 'running');

		$hlsOutDir = rtrim($this->config['paths']['hls'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $jobId;
		if (!is_dir($hlsOutDir)) { @mkdir($hlsOutDir, 0777, true); }

		$ffmpeg = (string)$this->config['ffmpeg']['binary'];
		$timeout = (int)$this->config['ffmpeg']['timeout_seconds'];
		$segmentSeconds = (int)$this->config['ffmpeg']['segment_seconds'];
		$playlistSize = (int)$this->config['ffmpeg']['playlist_size'];
		$ladder = $this->config['ffmpeg']['bitrate_ladder'];
		$useGpu = (bool)$this->config['ffmpeg']['gpu'];

		$mapCmds = [];
		$varStreams = [];
		$masterLines = ['#EXTM3U'];
		$index = 0;
		foreach ($ladder as $r) {
			$name = (string)$r['name'];
			$w = (int)$r['width'];
			$h = (int)$r['height'];
			$videoK = (int)$r['videoK'];
			$audioK = (int)$r['audioK'];
			$vCodec = $useGpu ? 'h264_nvenc' : 'libx264';
			$vPreset = $useGpu ? 'p5' : 'veryfast';
			$profile = 'main';
			$level = '4.0';
			$outName = $name . '.m3u8';
			$mapCmds[] = sprintf(
				" -map v:0 -map a:0 -c:v %s -preset %s -b:v %dk -maxrate %dk -bufsize %dk -vf scale=w=%d:h=%d:force_original_aspect_ratio=decrease:flags=lanczos -c:a aac -b:a %dk -ac 2 -f hls -hls_time %d -hls_list_size %d -hls_flags independent_segments+program_date_time -hls_segment_filename %s/%s_%%03d.ts %s/%s",
				$vCodec,
				$vPreset,
				$videoK,
				(int)round($videoK * 1.07),
				$videoK * 2,
				$w,
				$h,
				$audioK,
				$segmentSeconds,
				$playlistSize,
				escapeshellarg($hlsOutDir),
				escapeshellarg($name),
				escapeshellarg($hlsOutDir),
				escapeshellarg($outName)
			);
			$bandwidth = ($videoK + $audioK) * 1000;
			$masterLines[] = sprintf('#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%dx%d', $bandwidth, $w, $h);
			$masterLines[] = $outName;
			$index++;
		}

		$hwaccel = $useGpu ? ' -hwaccel auto' : '';
		$inputEsc = escapeshellarg($inputPath);
		$cmd = sprintf('"%s" -y%s -i %s %s', $ffmpeg, $hwaccel, $inputEsc, implode(' ', $mapCmds));

		$this->logger->log('Starting ffmpeg: ' . $cmd);
		$exit = ProcessRunner::run(
			$cmd,
			$timeout,
			fn(string $o) => $this->logger->log(rtrim($o)),
			fn(string $e) => $this->logger->log('[ERR] ' . rtrim($e))
		);

		if ($exit === 0) {
			$master = implode("\n", $masterLines) . "\n";
			@file_put_contents($hlsOutDir . DIRECTORY_SEPARATOR . 'master.m3u8', $master);
			$repo->updateStatus($jobId, 'completed', [
				'manifest' => '/hls/' . $jobId . '/master.m3u8',
			]);
			$this->logger->log('Transcode completed');
		} else {
			$repo->updateStatus($jobId, 'failed', [ 'exit_code' => $exit ]);
			$this->logger->log('Transcode failed with exit code ' . $exit);
		}

		return $exit;
	}

	private static function detectPhpBinary(): string
	{
		$bin = PHP_BINARY;
		if ($bin && is_file($bin)) {
			if (Config::osIsWindows() && preg_match('/php-cgi\.exe$/i', $bin)) {
				$cli = dirname($bin) . DIRECTORY_SEPARATOR . 'php.exe';
				if (is_file($cli)) { return $cli; }
			}
			return $bin;
		}
		if (Config::osIsWindows()) {
			$paths = [
				getenv('PHP_BINARY') ?: '',
				'php.exe',
				dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php.exe',
				getenv('LARAGON_ROOT') ? (rtrim(getenv('LARAGON_ROOT'), '\\/') . '\\bin\\php\\php.exe') : '',
				'C:\\laragon\\bin\\php\\php.exe',
			];
			foreach ($paths as $p) {
				if ($p && file_exists($p)) { return $p; }
			}
			return 'php.exe';
		}
		return 'php';
	}
}


