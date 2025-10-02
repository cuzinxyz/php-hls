<?php
return [
	'paths' => [
		'root' => realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'),
		'uploads' => (realpath(__DIR__ . '/..') ?: (__DIR__ . '/..')) . '/storage/uploads',
		'hls' => (realpath(__DIR__ . '/..') ?: (__DIR__ . '/..')) . '/storage/hls',
		'jobs' => (realpath(__DIR__ . '/..') ?: (__DIR__ . '/..')) . '/storage/jobs',
		'logs' => (realpath(__DIR__ . '/..') ?: (__DIR__ . '/..')) . '/storage/logs',
	],
	'ffmpeg' => [
		'binary' => null,
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


