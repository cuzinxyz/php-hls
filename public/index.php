<?php

declare(strict_types=1);

// Simple front controller and router for the HLS service

// Autoload (simple PSR-4 for Src\*), safe with open_basedir
spl_autoload_register(function ($class): void {
	$prefix = 'Src\\';
	$len = strlen($prefix);
	if (strncmp($prefix, $class, $len) !== 0) {
		return; // not our namespace
	}
	$open = (string)ini_get('open_basedir');
	$parent = dirname(__DIR__);
	$parentAllowed = ($open === '') || (strpos($open, $parent) !== false) || (strpos($open, $parent . DIRECTORY_SEPARATOR) !== false);
	$projectRoot = $parentAllowed ? $parent : __DIR__;
	$baseDir = $projectRoot . '/src/';
	$relativeClass = substr($class, $len);
	$file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
	if (@file_exists($file)) {
		require $file;
	}
});

use Src\Config;
use Src\Logger;
use Src\Transcoder;
use Src\JobRepository;

// Initialize config and ensure directories, avoiding open_basedir violations
$open = (string)ini_get('open_basedir');
$parent = dirname(__DIR__);
$parentAllowed = ($open === '') || (strpos($open, $parent) !== false) || (strpos($open, $parent . DIRECTORY_SEPARATOR) !== false);
$projectRoot = $parentAllowed ? $parent : __DIR__;
$configPath = $projectRoot . '/config/app.php';
if (!@file_exists($configPath)) {
	header('Content-Type: application/json');
	http_response_code(500);
	echo json_encode([
		'error' => 'Config not accessible due to open_basedir',
		'detail' => 'Allow parent directory in open_basedir or place config under public/config/app.php',
		'expected' => $configPath,
	]);
	exit;
}
$config = Config::load($configPath);
Logger::ensureDirectories($config);
JobRepository::ensureDirectories($config);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	header('HTTP/1.1 204 No Content');
	exit;
}

function respondJson(array $data, int $status = 200): void
{
	header('Content-Type: application/json');
	http_response_code($status);
	echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
	// Serve HLS static files safely: /hls/{jobId}/{file}
	if (strpos($uri, '/hls/') === 0 && $method === 'GET') {
		$rel = substr($uri, strlen('/hls/'));
		if ($rel === false) { $rel = ''; }
		$rel = str_replace(['..','\\'], ['','/'], $rel);
		$path = rtrim($config['paths']['hls'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
		$real = realpath($path) ?: $path;
		$hlsRoot = realpath($config['paths']['hls']) ?: $config['paths']['hls'];
		if (strpos($real, $hlsRoot) !== 0 || !is_file($real)) {
			respondJson(['error' => 'Not Found'], 404);
		}
		$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
		$mime = 'application/octet-stream';
		if ($ext === 'm3u8') { $mime = 'application/vnd.apple.mpegurl'; }
		if ($ext === 'ts') { $mime = 'video/mp2t'; }
		if ($ext === 'mp4') { $mime = 'video/mp4'; }
		header('Content-Type: ' . $mime);
		readfile($real);
		exit;
	}
	if ($uri === '/' && $method === 'GET') {
		respondJson([
			'status' => 'ok',
			'service' => 'php-hls',
			'php' => PHP_VERSION,
		]);
	}

	if ($uri === '/upload' && $method === 'POST') {
		if (!isset($_FILES['file'])) {
			respondJson(['error' => 'Missing file field: file'], 400);
		}
		$uploaded = $_FILES['file'];
		if (($uploaded['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
			respondJson(['error' => 'Upload error', 'code' => $uploaded['error']], 400);
		}
		$originalName = $uploaded['name'] ?? ('upload_' . date('Ymd_His'));
		$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
		$allowed = ['mp4', 'mov', 'mkv', 'avi', 'webm', 'm4v'];
		if (!in_array($ext, $allowed, true)) {
			respondJson(['error' => 'Unsupported file type'], 400);
		}
		$jobId = bin2hex(random_bytes(8));
		$uploadDir = rtrim($config['paths']['uploads'], DIRECTORY_SEPARATOR);
		$inputPath = $uploadDir . DIRECTORY_SEPARATOR . $jobId . '.' . $ext;
		if (!move_uploaded_file($uploaded['tmp_name'], $inputPath)) {
			respondJson(['error' => 'Failed to move uploaded file'], 500);
		}

		// Create job and start async transcoding
		$repo = new JobRepository($config);
		$repo->createJob($jobId, [
			'original_name' => $originalName,
			'input_path' => $inputPath,
			'status' => 'queued',
			'created_at' => time(),
		]);

		$transcoder = new Transcoder($config, new Logger($config, $jobId));
		$transcoder->transcodeAsync($jobId, $inputPath);

		respondJson([
			'job_id' => $jobId,
			'manifest' => '/hls/' . $jobId . '/master.m3u8',
			'status_url' => '/status?id=' . $jobId,
			'logs_url' => '/logs?id=' . $jobId,
		]);
	}

	if ($uri === '/status' && $method === 'GET') {
		$jobId = $_GET['id'] ?? '';
		if ($jobId === '') {
			respondJson(['error' => 'Missing id'], 400);
		}
		$repo = new JobRepository($config);
		$status = $repo->getJob($jobId);
		if ($status === null) {
			respondJson(['error' => 'Job not found'], 404);
		}
		respondJson($status);
	}

	if ($uri === '/logs' && $method === 'GET') {
		$jobId = $_GET['id'] ?? '';
		if ($jobId === '') {
			respondJson(['error' => 'Missing id'], 400);
		}
		$logger = new Logger($config, $jobId);
		$lines = $logger->tail(500);
		respondJson(['id' => $jobId, 'lines' => $lines]);
	}

	respondJson(['error' => 'Not Found'], 404);
} catch (Throwable $e) {
	// Best-effort structured error
	respondJson([
		'error' => 'Unhandled',
		'message' => $e->getMessage(),
		'trace' => getenv('APP_DEBUG') === '1' ? $e->getTrace() : [],
	], 500);
}
