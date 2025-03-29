<?php

/**
 * SiMon default config.
 *
 * @generated
 * @date 2024-04-26
 * @time 18:00:43 UTC
 * @link https://github.com/maximal/simon-php
 * @link https://maximals.ru
 * @link https://sijeko.ru
 */

return [
	// This machine name, for measurements tag `host`;
	// If `NULL`, will be taken from `/etc/hostname` file
	'hostname' => null,
	// Influx config
	'influx' => [
		// Influx host name, required if Influx enabled
		// HTTP installation example, with port: influx.host.org:8086
		// HTTPS installation example: https://influx.host.org
		'host' => 'HOST REQUIRED',
		// Influx API token, required if Influx enabled
		'token' => 'TOKEN REQUIRED',
		// Influx org name, required if Influx enabled
		'org' => 'ORG REQUIRED',
		// Influx bucket name, required if Influx enabled
		'bucket' => 'BUCKET REQUIRED',
		// Timestamps precision: s, ms, us/μs, ns
		// It is recommended not to use `ms`, `μs`, or `ns`
		// if the monitoring interval is greater than `1`
		'precision' => 's',
		// Additional tags to be added to every measurement; ["tag1" => "value1", etc...]
		'tags' => [],
		// Whether to send data to Influx; disabled by default, console output only
		'enabled' => false,
	],
	// Whether to print metrics to stdout; disabled by default, set true to enable
	'print' => false,
	'cpu' => [
		// Whether to track CPU usage; enabled by default
		'enabled' => true,
	],
	'memory' => [
		// Whether to track memory usage; enabled by default
		'enabled' => true,
	],
	'uptime' => [
		// Whether to track system uptime; enabled by default
		'enabled' => true,
	],
	'disk' => [
		// Whether to track disk usage; enabled by default
		'enabled' => true,
		// Mount points to track; default, empty array, means all
		'mounts' => [],
	],
	'network' => [
		// Whether to track network interfaces usage; enabled by default
		'enabled' => true,
		// Network interfaces to track; default, empty array, means all
		'interfaces' => [],
	],
	'io' => [
		// Whether to track IO usage; enabled by default
		'enabled' => true,
		// Devices to track; default, empty array, means all
		'devices' => [],
	],
	'monitor' => [
		// Whether to track monitor’s own metrics; enabled by default
		'enabled' => true,
	],
];
