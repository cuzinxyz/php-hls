<?php
declare(strict_types=1);

// Minimal CLI worker to run a single HLS transcode job

spl_autoload_register(function ($class): void {
	$prefix = 'Src\\';
	$baseDir = __DIR__ . '/../src/';
	$len = strlen($prefix);
	if (strncmp($prefix, $class, $len) !== 0) {
		return;
	}
	$relativeClass = substr($class, $len);
	$file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
	if (file_exists($file)) { require $file; }
});

use Src\Config;
use Src\Logger;
use Src\Transcoder;
use Src\JobRepository;

if ($argc < 3) {
	fwrite(STDERR, "Usage: php bin/transcode.php <jobId> <inputPath>\n");
	exit(2);
}

$jobId = (string)$argv[1];
$inputPath = (string)$argv[2];

$config = Config::load(__DIR__ . '/../config/app.php');
Logger::ensureDirectories($config);
JobRepository::ensureDirectories($config);

$logger = new Logger($config, $jobId);
$transcoder = new Transcoder($config, $logger);
$logger->log('Worker started with PHP: ' . (PHP_BINARY ?: 'unknown'));
$exit = $transcoder->transcodeSync($jobId, $inputPath);
exit($exit);


