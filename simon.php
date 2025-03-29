<?php
/**
 * SiMon — Linux system monitor in a single PHP file.
 *
 * This tool gathers various performance metrics
 * from `/proc` filesystem and prints or sends them to InfluxDB.
 *
 * Used `/proc` data:
 * * `/proc/uptime` — system’s uptime information;
 * * `/proc/loadavg` — load average;
 * * `/proc/stat` — processor metrics and usage;
 * * `/proc/meminfo` — memory metrics and usage;
 * * `/proc/diskstats` — I/O utilization/saturation statistics;
 * * `/proc/net/dev` — network interfaces load.
 *
 * @author MaximAL of Sijeko
 * @link https://github.com/maximal/simon-php
 * @link https://maximals.ru/
 * @link https://sijeko.ru/
 * @version 1.2
 */

namespace Maximal\SiMon;

/**
 * SiMon — Linux system monitor in a single PHP file.
 *
 * @noinspection  AutoloadingIssuesInspection
 */
final class SiMon
{
	public const NAME = 'SiMon';
	public const VERSION = '1.2';
	public const REPO = 'https://github.com/maximal/simon-php';
	public const DEFAULT_CONFIG = [
		'_comment_hostname_1' => 'This machine name, for measurements tag `host`;',
		'_comment_hostname_2' => 'If `NULL`, will be taken from `/etc/hostname` file',
		'hostname' => null,
		'_comment_influx' => 'InfluxDB settings',
		'influx' => [
			'_comment_host_1' => 'Influx host name, required if Influx enabled',
			'_comment_host_2' => 'HTTP installation example, with port: influx.host.org:8086',
			'_comment_host_3' => 'HTTPS installation example: https://influx.host.org',
			'host' => 'HOST REQUIRED',
			'_comment_token' => 'Influx API token, required if Influx enabled',
			'token' => 'TOKEN REQUIRED',
			'_comment_org' => 'Influx org name, required if Influx enabled',
			'org' => 'ORG REQUIRED',
			'_comment_bucket' => 'Influx bucket name, required if Influx enabled',
			'bucket' => 'BUCKET REQUIRED',
			'_comment_precision_1' => 'Timestamps precision: s, ms, μs/us, ns',
			'_comment_precision_2' => 'It is recommended not to use `ms`, `μs`, or `ns`',
			'_comment_precision_3' => 'if the monitoring interval is greater than `1`',
			'precision' => 's',
			'_comment_tags' => 'Additional tags to be added to every measurement; ["tag1" => "value1", etc...]',
			'tags' => [],
			'_comment_enabled' => 'Whether to send data to Influx; disabled by default, console output only',
			'enabled' => false,
		],
		'uptime' => [
			'_comment_enabled' => 'Whether to track system uptime; enabled by default',
			'enabled' => true,
		],
		'cpu' => [
			'_comment_enabled' => 'Whether to track CPU usage; enabled by default',
			'enabled' => true,
		],
		'memory' => [
			'_comment_enabled' => 'Whether to track memory usage; enabled by default',
			'enabled' => true,
		],
		'disk' => [
			'_comment_enabled' => 'Whether to track disk usage; enabled by default',
			'enabled' => true,
			'_comment_mounts' => 'Mount points to track; default, empty array, means all',
			'mounts' => [],
		],
		'io' => [
			'_comment_enabled' => 'Whether to track IO usage; enabled by default',
			'enabled' => true,
			'_comment_devices' => 'Devices to track; default, empty array, means all',
			'devices' => [],
		],
		'network' => [
			'_comment_enabled' => 'Whether to track network interfaces usage; enabled by default',
			'enabled' => true,
			'_comment_interfaces' => 'Network interfaces to track; default, empty array, means all',
			'interfaces' => [],
		],
		'monitor' => [
			'_comment_enabled' => 'Whether to track monitor’s own metrics; enabled by default',
			'enabled' => true,
		],
		'_comment_print' => 'Whether to print metrics to stdout; disabled by default, set true to enable',
		'print' => false,
	];
	// Reload the config file every 10 minutes
	public const RELOAD_CONFIG_EVERY = 10 * 60;

	public const INTERVAL_MIN = 0.1;
	public const INTERVAL_MAX = 60 * 60;
	public const INTERVAL_DEFAULT = 1.0;

	public const NETWORKS_REQUIRING_EXPLICIT_CONFIG = [
		'/^lo$/i',
		'/^docker\d+$/i',
		'/^br-[0-9a-f]+$/i',
		'/^[v]eth[0-9a-f]+$/i'
	];

	public const DISKS_REQUIRING_EXPLICIT_CONFIG = ['/^loop\d+$/i'];

	// https://tldp.org/LDP/abs/html/exitcodes.html
	public const EXIT_CODE_OK = 0;
	// General (unknown) exit codes
	//public const EXIT_FATAL = 1;
	//public const EXIT_PANIC = 2;
	// Known exit codes
	public const EXIT_CODE_UNKNOWN_ARGUMENT = 3;
	public const EXIT_CODE_CURL_REQUIRED = 4;
	public const EXIT_CODE_INFLUX_CONFIG_REQUIRED = 5;
	public const EXIT_CODE_UNSUPPORTED_PLATFORM = 6;
	public const EXIT_CODE_ALREADY_RUNNING = 7;

	public string $configFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
	public array $config;
	public int $configLoadedTime = 0;

	public float $interval = self::INTERVAL_DEFAULT;

	private array $measurements = [];
	private array $networks = [];
	private array $disks = [];
	private string $hostname;
	private array $tags = [];
	private bool $hostTime = false;
	private bool $logMeasurements = false;

	private float $uptime = 0;
	private float $cpuUsage = 0;
	//private int $cpuCount = 0;
	private array $cpus = [];
	private float $memUsage = 0;
	private float $runtime = 0;
	private float $sendTime = 0;


	public function run(array $argv): int
	{
		$timeStart = microtime(true);
		$this->parseParams($argv);
		$this->validateRunning();
		$iteration = 0;

		// Поехали!
		while (true) {
			$timeLoopStart = microtime(true);
			$this->runtime = $timeLoopStart - $timeStart;
			//
			$this->startMeasurements();
			$this->collectMeasurements();
			$this->sendMeasurements();
			$this->printStatus($iteration);
			$this->reloadConfig($timeLoopStart);
			//
			$iteration++;
			$timeLoopLength = microtime(true) - $timeLoopStart;
			if ($this->interval > $timeLoopLength) {
				usleep((int)(($this->interval - $timeLoopLength) * 1_000_000));
			}
		}
	}

	private function parseParams(array $argv): void
	{
		$prev = null;
		$first = true;
		foreach ($argv as $argument) {
			if ($first) {
				$first = false;
				continue;
			}
			$cleanArgument = strtolower(trim($argument));
			switch ($cleanArgument) {
				case '-?':
				case '-h':
				case '--help':
					exit(self::commandHelp($argv[0]));
				case '-v':
				case '--version':
					exit(self::commandVersion());
				case '-m':
				case '--metrics':
					exit(self::commandMetrics());
				case '-d':
				case '--defaults':
					exit(self::commandDefaults());
				case '-s':
				case '--service':
					exit(self::commandService($argv[0]));
				case '-c':
				case '--config':
					$prev = '--config';
					break;
				case '-i':
				case '--interval':
					$prev = '--interval';
					break;
				default:
					switch ($prev) {
						case '--config':
							$this->configFile = $argument;
							$this->loadConfig();
							$prev = null;
							break;
						case '--interval':
							$this->interval = (float)$cleanArgument;
							if ($this->interval < self::INTERVAL_MIN) {
								$this->interval = self::INTERVAL_MIN;
							}
							if ($this->interval > self::INTERVAL_MAX) {
								$this->interval = self::INTERVAL_MAX;
							}
							$prev = null;
							break;
						default:
							self::writeStderrLine('Unknown argument: ' . $argument);
							exit(self::EXIT_CODE_UNKNOWN_ARGUMENT);
					}
					break;
			}
		}
		if ($this->configLoadedTime === 0) {
			$this->loadConfig();
		}
	}

	private function loadConfig(): void
	{
		if (is_file($this->configFile)) {
			$this->config = array_merge(self::DEFAULT_CONFIG, require($this->configFile));
		} else {
			$this->config = self::DEFAULT_CONFIG;
		}

		if ($this->config['influx']['enabled'] ?? false) {
			$this->validateInfluxConfig('host');
			$this->validateInfluxConfig('token');
			$this->validateInfluxConfig('org');
			$this->validateInfluxConfig('bucket');
			if (!extension_loaded('curl')) {
				self::writeStderrLine('PHP cURL extension required if Influx enabled.');
				exit(self::EXIT_CODE_CURL_REQUIRED);
			}
		}

		$this->tags = $this->config['influx']['tags'] ?? [];

		$this->hostname = $this->config['hostname']
			?? self::file('/etc/hostname')
			?? 'default';

		$this->logMeasurements = $this->config['print'] ?? false;
		//$this->interval = $this->config['interval'] ?? $this->interval;

		$this->configLoadedTime = time();
	}

	private function reloadConfig(float $time): void
	{
		if ($time - $this->configLoadedTime >= self::RELOAD_CONFIG_EVERY) {
			$this->loadConfig();
		}
	}

	private function validateInfluxConfig(string $param): void
	{
		$upper = strtoupper($param);
		$value = $this->config['influx'][$param] ?? '';
		if ($value === $upper . ' REQUIRED') {
			$value = '';
		}
		if ($value === '') {
			self::writeStderrLine('Influx API ' . $param . ' required in config file if Influx enabled.');
			exit(self::EXIT_CODE_INFLUX_CONFIG_REQUIRED);
		}
	}

	private function validateRunning(): void
	{
		// 64-bit Linux?
		if (PHP_OS_FAMILY !== 'Linux' || PHP_INT_SIZE < 8) {
			self::writeStderrLine(
				self::NAME . ' works on Linux systems with at least 64-bit architecture. You have ' .
				(PHP_INT_SIZE * 8) . '-bit ' . PHP_OS_FAMILY . '.'
			);
			exit(self::EXIT_CODE_UNSUPPORTED_PLATFORM);
		}

		// `/proc` filesystem?
		if (
			!is_file('/proc/loadavg') || !is_file('/proc/stat') ||
			!is_file('/proc/meminfo') || !is_file('/proc/uptime') ||
			!is_file('/proc/net/dev') || !is_file('/proc/diskstats')
		) {
			self::writeStderrLine(self::NAME . ' needs `/proc` filesystem.');
			exit(self::EXIT_CODE_UNSUPPORTED_PLATFORM);
		}

		// Already running?
		$filename = pathinfo(__FILE__, PATHINFO_BASENAME);
		$output = $code = null;
		exec(
			'ps -eo pid,user,args | grep ' . escapeshellarg($filename),
			$output,
			$code
		);
		if ($code !== 0) {
			return;
		}
		$myPid = getmypid();
		foreach ($output as $line) {
			$match = null;
			if (!preg_match('/\s*(\d+)\s+(\S+)\s+(.*)/', $line, $match)) {
				continue;
			}
			$pid = (int)$match[0];
			$user = trim($match[2]);
			$command = trim($match[3]);
			if ($pid === $myPid) {
				continue;
			}
			if (str_starts_with($command, 'sudo ')) {
				continue;
			}
			if (str_contains($line, ' grep ')) {
				continue;
			}
			self::writeStderrLine(
				self::NAME . ' already running under `' . $user . '` user with PID ' . $pid . '.'
			);
			exit(self::EXIT_CODE_ALREADY_RUNNING);
		}
	}

	private function getUptime(): void
	{
		$this->uptime = (float)file_get_contents('/proc/uptime');
		$this->addNonNegativeMeasurement('uptime', $this->uptime);
	}

	private function getCpu(): void
	{
		$loadAverage = (float)file_get_contents('/proc/loadavg');
		$this->addNonNegativeMeasurement('load_average', $loadAverage);

		$cpuUsage = null;
		$procRunning = null;
		$procBlocked = null;
		$cpuCount = 0;
		// file: /proc/stat
		//   0       1     2       3       4       5    6         7      8      9          10
		//        user  nice  system    idle  ioWait  irq   softIrq  steal  guest  guest_nice
		// cpu  151882  1102   40189  891653    2676    0      1368      0      0           0
		foreach (self::textToLines(self::file('/proc/stat')) as $line) {
			$parts = preg_split('/\s+/', $line, 12, PREG_SPLIT_NO_EMPTY);
			$name = strtolower($parts[0]);

			// Running tasks / processes
			if ($name === 'procs_running' && count($parts) === 2) {
				$procRunning = (int)$parts[1];
			}

			// CPUs
			if (count($parts) !== 11) {
				continue;
			}

			if (!str_starts_with($name, 'cpu')) {
				continue;
			}

			// non-idle = user + nice + system + irq + softIrq + steal
			$working = (int)$parts[1] + (int)$parts[2] + (int)$parts[3] +
				(int)$parts[6] + (int)$parts[7] + (int)$parts[8];
			// idle = idle + ioWait
			$idle = (int)$parts[4] + (int)$parts[5];

			$lastIdle = $this->cpus[$name]['idle'] ?? null;
			$lastWorking = $this->cpus[$name]['working'] ?? null;

			if ($lastIdle !== null && $lastWorking !== null) {
				$diffWorking = $working - $lastWorking;
				// usage = 100% * diffWorking / (diffWorking + diffIdle)
				// https://github.com/htop-dev/htop/blob/40104588f38250afde9f71b6204d789039bbfe3e/linux/LinuxProcessList.c#L2075
				// https://stackoverflow.com/questions/23367857/accurate-calculation-of-cpu-usage-given-in-percentage-in-linux
				// https://stackoverflow.com/questions/1420426/how-to-calculate-the-cpu-usage-of-a-process-by-pid-in-linux-from-c/1424556#1424556
				$usage = 100.0 * $diffWorking / ($diffWorking + $idle - $lastIdle);

				if ($name === 'cpu') {
					// Add measurement for aggregate CPU usage only
					$cpuUsage = $usage;
				}
			}

			if ($name !== 'cpu') {
				// Counting cores only, without the aggregated `cpu` line
				$cpuCount++;
			}

			$this->cpus[$name]['idle'] = $idle;
			$this->cpus[$name]['working'] = $working;
		}
		if ($cpuCount > 0) {
			$loadUsage = 100.0 * $loadAverage / $cpuCount;
			// On first iteration have load_average / cpu_count as CPU usage
			$usage = $cpuUsage ?? $loadUsage;
			$this->addNonNegativeMeasurement('load_usage', $loadUsage);
			$this->addNonNegativeMeasurement('cpu_count', $cpuCount);
			$this->addNonNegativeMeasurement('cpu_usage', $usage);
			$this->cpuUsage = $usage;
			//$this->cpuCount = $cpuCount;
		}
		if ($procRunning !== null) {
			$this->addNonNegativeMeasurement('cpu_processes_running', $procRunning);
		}
		if ($procBlocked !== null) {
			$this->addNonNegativeMeasurement('cpu_processes_blocked', $procBlocked);
		}
	}

	private function getMemory(): void
	{
		$matches = null;
		if (!preg_match_all('/([a-z0-9]+):\s*(\d+)\s*kB/i', self::file('/proc/meminfo'), $matches)) {
			return;
		}

		$memValues = [];
		foreach ($matches[1] as $index => $key) {
			$measurement = match ($key) {
				'MemTotal' => 'memory_total',
				'MemFree' => 'memory_free',
				'MemAvailable' => 'memory_available',
				'Buffers' => 'memory_buffers',
				'Cached' => 'memory_cached',
				'SwapTotal' => 'memory_swap_total',
				'SwapFree' => 'memory_swap_free',
				'SwapCached' => 'memory_swap_cached',
				default => null,
			};

			$valueKB = (int)$matches[2][$index];
			$memValues[$key] = $valueKB;
			if ($measurement !== null) {
				$this->addNonNegativeMeasurement($measurement, $valueKB);
			}
		}

		$total = $memValues['MemTotal'] ?? 0;
		$free = $memValues['MemFree'] ?? null;
		$available = $memValues['MemAvailable'] ?? null;
		$buffers = $memValues['Buffers'] ?? null;
		$cached = $memValues['Cached'] ?? null;
		$swapTotal = $memValues['SwapTotal'] ?? 0;
		$swapFree = $memValues['SwapFree'] ?? null;
		$swapCached = $memValues['SwapCached'] ?? null;
		if ($available !== null && $total > 0) {
			// Using `MemAvailable`
			$used = $total - $available;
			$usage = 100.0 * $used / $total;
			$this->addNonNegativeMeasurement('memory_used', $used);
			$this->addNonNegativeMeasurement('memory_usage', $usage);
			$this->memUsage = $usage;
		}
		if ($free !== null && $buffers !== null && $cached !== null && $swapCached !== null && $total > 0) {
			// Like `htop`, not using `MemAvailable`
			$used = $total - $free - $buffers - $cached - $swapCached;
			$usage = 100.0 * $used / $total;
			$this->addNonNegativeMeasurement('memory_used_classic', $used);
			$this->addNonNegativeMeasurement('memory_usage_classic', $usage);
		}
		if ($swapFree !== null && $swapTotal > 0) {
			$swapUsed = $swapTotal - $swapFree;
			$swapUsage = 100.0 * $swapUsed / $swapTotal;
			$this->addNonNegativeMeasurement('memory_swap_used', $swapUsed);
			$this->addNonNegativeMeasurement('memory_swap_usage', $swapUsage);
		}
	}

	private function getDisk(): void
	{
		$output = $code = null;
		exec(
			'df --block-size=1MiB --exclude-type=tmpfs --exclude-type=devtmpfs',
			$output,
			$code
		);
		if ($code !== 0) {
			return;
		}

		$mounts = $this->config['disk']['mounts'] ?? [];
		$all = count($mounts) === 0;
		foreach ($output as $line) {
			$match = null;
			if (!preg_match('/(\S+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%\s+(\S+)/', $line, $match)) {
				continue;
			}
			$mount = $match[6];
			if (!$all && !in_array($mount, $mounts, true)) {
				continue;
			}

			$tags = ['disk' => $match[1], 'mount' => $mount];
			$total = (int)$match[2];
			$used = (int)$match[3];
			$usage = 100.0 * $used / $total;
			$this->addNonNegativeMeasurement('disk_total', $total, $tags);
			$this->addNonNegativeMeasurement('disk_used', $used, $tags);
			$this->addNonNegativeMeasurement('disk_available', (int)$match[4], $tags);
			$this->addNonNegativeMeasurement('disk_usage', $usage, $tags);
		}
	}

	private function getIo(): void
	{
		$text = self::file('/proc/diskstats');
		$time = microtime(true);
		$disks = $this->config['io']['disks'] ?? [];
		$all = count($disks) === 0;
		$maxUsage = $maxUsageDevice = null;
		foreach (self::textToLines($text) as $line) {
			$parts = preg_split('/\s+/', $line, 20, PREG_SPLIT_NO_EMPTY);
			if (count($parts) < 14) {
				continue;
			}
			$disk = $parts[2];
			$explicitlyNeeded = in_array($disk, $disks, true);
			if (!$explicitlyNeeded) {
				if (!$all) {
					continue;
				}
				if (self::matchesAnyPattern($disk, self::DISKS_REQUIRING_EXPLICIT_CONFIG)) {
					// Skip loop<n> disks and other not interesting stuff
					// if they are not required explicitly
					continue;
				}
			}

			$tags = ['device' => $disk];

			$readsCompleted = (int)$parts[3];
			$readsSectors = (int)$parts[5];
			$readsTime = (int)$parts[6];
			$writesCompleted = (int)$parts[7];
			$writesSectors = (int)$parts[9];
			$writesTime = (int)$parts[10];
			$iosCurrent = (int)$parts[11];
			$iosTime = (int)$parts[12];

			// Current IO state
			$this->addNonNegativeMeasurement('io_current_ops', $iosCurrent, $tags);

			$lastReadsCompleted = $this->disks[$disk]['readsCompleted'] ?? null;
			$lastReadsSectors = $this->disks[$disk]['readsSectors'] ?? null;
			$lastReadsTime = $this->disks[$disk]['readsTime'] ?? null;
			$lastWritesCompleted = $this->disks[$disk]['writesCompleted'] ?? null;
			$lastWritesSectors = $this->disks[$disk]['writesSectors'] ?? null;
			$lastWritesTime = $this->disks[$disk]['writesTime'] ?? null;
			$lastIosTime = $this->disks[$disk]['iosTime'] ?? null;
			$lastTime = $this->disks[$disk]['time'] ?? null;

			if ($lastTime !== null) {
				// If previous values for this disk are known, calculate IO speed and usage
				$timeDiff = $time - $lastTime;
				// Operations per second
				$writeSpeed = ($writesCompleted - $lastWritesCompleted) / $timeDiff;
				$readSpeed = ($readsCompleted - $lastReadsCompleted) / $timeDiff;
				// KiB per second; sector size = 512 B = 1/2 KiB
				$writeKiBSpeed = ($writesSectors - $lastWritesSectors) / 2 / $timeDiff;
				$readKiBSpeed = ($readsSectors - $lastReadsSectors) / 2 / $timeDiff;
				// IO utilization / device saturation
				$writeUsage = 100.0 * ($writesTime - $lastWritesTime) / $timeDiff / 1000;
				$readUsage = 100.0 * ($readsTime - $lastReadsTime) / $timeDiff / 1000;
				$ioUsage = 100.0 * ($iosTime - $lastIosTime) / $timeDiff / 1000;
				// TODO: should be weighted by CPU count?
				// https://unix.stackexchange.com/questions/581778/proc-diskstats-disk-read-time-increasing-more-than-second-per-second/581790
				// https://www.kernel.org/doc/Documentation/iostats.txt
				// https://docs.percona.com/percona-toolkit/pt-diskstats.html
				//if ($this->cpuCount > 0) {
				//$writeUsage /= $this->cpuCount;
				//$readUsage /= $this->cpuCount;
				//$ioUsage /= $this->cpuCount;
				//}
				//echo $disk, ' : ', $ioUsage, PHP_EOL;

				$this->addNonNegativeMeasurement('io_write_ops_speed', $writeSpeed, $tags);
				$this->addNonNegativeMeasurement('io_write_speed', $writeKiBSpeed, $tags);
				$this->addNonNegativeMeasurement('io_write_usage', $writeUsage, $tags);
				$this->addNonNegativeMeasurement('io_read_ops_speed', $readSpeed, $tags);
				$this->addNonNegativeMeasurement('io_read_speed', $readKiBSpeed, $tags);
				$this->addNonNegativeMeasurement('io_read_usage', $readUsage, $tags);
				$this->addNonNegativeMeasurement('io_usage', $ioUsage, $tags);
				// Find the most used device
				if ($ioUsage > 0 && ($maxUsage === null || $maxUsage < $ioUsage)) {
					$maxUsage = $ioUsage;
					$maxUsageDevice = $disk;
				}
			}

			$this->disks[$disk]['readsCompleted'] = $readsCompleted;
			$this->disks[$disk]['readsSectors'] = $readsSectors;
			$this->disks[$disk]['readsTime'] = $readsTime;
			$this->disks[$disk]['writesCompleted'] = $writesCompleted;
			$this->disks[$disk]['writesSectors'] = $writesSectors;
			$this->disks[$disk]['writesTime'] = $writesTime;
			$this->disks[$disk]['iosTime'] = $iosTime;
			$this->disks[$disk]['time'] = $time;
		}
		if ($maxUsage !== null) {
			$this->addMeasurement(
				'io_usage_max',
				$maxUsage,
				['device' => $maxUsageDevice],
				['device' => $maxUsageDevice]
			);
		}
	}

	private function getNetwork(): void
	{
		$text = self::file('/proc/net/dev');
		$time = microtime(true);
		$interfaces = $this->config['network']['interfaces'] ?? [];
		$all = count($interfaces) === 0;
		$maxInLoad = $maxInInterface = null;
		$maxOutLoad = $maxOutInterface = null;
		foreach (self::textToLines($text) as $line) {
			$match = null;
			if (!preg_match('/([a-z0-9-_]+):(.*)/', $line, $match)) {
				continue;
			}
			$interface = $match[1];
			$explicitlyNeeded = in_array($interface, $interfaces, true);
			if (!$explicitlyNeeded) {
				if (!$all) {
					continue;
				}
				if (self::matchesAnyPattern($interface, self::NETWORKS_REQUIRING_EXPLICIT_CONFIG)) {
					// Skip localhost (loopback) and some Docker interfaces
					// if they are not required explicitly
					continue;
				}
			}

			$data = preg_split('/\s+/', $match[2], -1, PREG_SPLIT_NO_EMPTY);
			if ($interface && count($data) === 16) {
				$inBytes = (int)$data[0];
				$outBytes = (int)$data[8];
				$tags = ['interface' => $interface];

				// Traffic
				$this->addNonNegativeMeasurement('network_in', $inBytes, $tags);
				$this->addNonNegativeMeasurement('network_out', $outBytes, $tags);

				$lastInBytes = $this->networks[$interface]['in'] ?? null;
				$lastOutBytes = $this->networks[$interface]['out'] ?? null;
				$lastTime = $this->networks[$interface]['time'] ?? null;
				if ($lastInBytes !== null && $lastOutBytes !== null && $lastTime !== null) {
					// If previous traffic for this interface is known, calculate the network load (speed)
					$timeDiff = $time - $lastTime;
					$inLoad = (int)round(8 * ($inBytes - $lastInBytes) / $timeDiff);
					$outLoad = (int)round(8 * ($outBytes - $lastOutBytes) / $timeDiff);
					$this->addNonNegativeMeasurement('network_load_in', $inLoad, $tags);
					$this->addNonNegativeMeasurement('network_load_out', $outLoad, $tags);
					// Find the most loaded interfaces
					if ($inLoad > 0 && ($maxInLoad === null || $maxInLoad < $inLoad)) {
						$maxInLoad = $inLoad;
						$maxInInterface = $interface;
					}
					if ($outLoad > 0 && ($maxOutLoad === null || $maxOutLoad < $outLoad)) {
						$maxOutLoad = $outLoad;
						$maxOutInterface = $interface;
					}
				}
				$this->networks[$interface]['in'] = $inBytes;
				$this->networks[$interface]['out'] = $outBytes;
				$this->networks[$interface]['time'] = $time;
			}
		}
		if ($maxInLoad !== null) {
			$this->addMeasurement(
				'network_load_in_max',
				$maxInLoad,
				['interface' => $maxInInterface],
				['interface' => $maxInInterface]
			);
		}
		if ($maxOutLoad !== null) {
			$this->addMeasurement(
				'network_load_out_max',
				$maxOutLoad,
				['interface' => $maxOutInterface],
				['interface' => $maxOutInterface]
			);
		}
	}

	private function getMonitor(): void
	{
		$this->addNonNegativeMeasurement('monitor_runtime', $this->runtime);
		$this->addNonNegativeMeasurement('monitor_memory_alloc', memory_get_usage(true));
		if ($this->sendTime > 0) {
			$this->addNonNegativeMeasurement('monitor_metrics_sent_time', $this->sendTime);
		}
	}

	private function startMeasurements(): void
	{
		$this->measurements = [];
	}

	private function collectMeasurements(): void
	{
		if ($this->config['uptime']['enabled'] ?? true) {
			$this->getUptime();
		}
		if ($this->config['cpu']['enabled'] ?? true) {
			$this->getCpu();
		}
		if ($this->config['memory']['enabled'] ?? true) {
			$this->getMemory();
		}
		if ($this->config['disk']['enabled'] ?? true) {
			$this->getDisk();
		}
		if ($this->config['io']['enabled'] ?? true) {
			$this->getIo();
		}
		if ($this->config['network']['enabled'] ?? true) {
			$this->getNetwork();
		}
		if ($this->config['monitor']['enabled'] ?? true) {
			$this->getMonitor();
		}
	}

	private function addMeasurement(
		string $measurement,
		int|float|bool $value,
		array $tags = [],
		array $fields = []
	): void {
		foreach ($this->tags as $tag => $tagValue) {
			$tags[$tag] = $tagValue;
		}
		$tags['host'] = $this->hostname;

		// https://docs.influxdata.com/influxdb/v2/write-data/best-practices/optimize-writes/#sort-tags-by-key
		if (count($tags) > 1) {
			ksort($tags);
		}

		$fullMeasurement = [self::escapeMeasurement($measurement)];
		foreach ($tags as $tag => $tagValue) {
			$fullMeasurement [] = self::escapeTag($tag) . '=' . self::escapeTag($tagValue);
		}

		$values = ['value' => $value];
		foreach ($fields as $field => $fieldValue) {
			$values[$field] = $fieldValue;
		}
		$this->measurements[implode(',', $fullMeasurement)] = $values;
	}

	private function addNonNegativeMeasurement(string $measurement, int|float $value, array $tags = []): void
	{
		if ($value < 0) {
			return;
		}
		$this->addMeasurement($measurement, $value, $tags);
	}

	private function sendMeasurements(): void
	{
		$timeStart = microtime(true);
		$lines = [];
		foreach ($this->measurements as $measurement => $fields) {
			$values = [];
			foreach ($fields as $field => $value) {
				$field = self::escapeTag($field);
				if (is_int($value)) {
					// Influx 2.x+
					//$valueString = $value . ($value < 0 ? 'i' : 'u');
					// Influx 1.x
					$values []= $field . '=' . $value . 'i';
				} elseif (is_bool($value)) {
					$values []= $field . '=' . ($value ? 'true' : 'false');
				} elseif (is_string($value)) {
					$values []= $field . '="' . self::escapeValue($value) . '"';
				} else {
					$values []= $field . '=' . $value;
				}
			}

			$lines [] = "\t" . $measurement . '    ' . implode(',', $values) . $this->influxTimestamp();
		}
		if ($this->logMeasurements) {
			self::log(implode("\n", $lines));
		}
		if ($this->config['influx']['enabled'] ?? false) {
			$this->writeToInflux(implode("\n", $lines));
		}
		$this->sendTime = microtime(true) - $timeStart;
	}

	private function printStatus(int $iteration): void
	{
		$system = [];
		if ($this->config['uptime']['enabled'] ?? true) {
			$system []= 'uptime: ' . self::time($this->uptime);
		}
		if ($this->config['cpu']['enabled'] ?? true) {
			$system []= sprintf('CPU: %.1f%%', $this->cpuUsage);
		}
		if ($this->config['memory']['enabled'] ?? true) {
			$system []= sprintf('memory: %.1f%%', $this->memUsage);
		}
		$monitor = [];
		if ($this->config['influx']['enabled'] ?? false) {
			$monitor []= 'send time: ' . (int)(1000 * $this->sendTime) . ' ms';
		}
		self::log(sprintf(
			'#%d%s' .
			'    Monitor:  runtime: %s  memory: %s current  %s peak%s',
			$iteration,
			count($system) > 0 ? ('    System:  ' . implode('  ', $system)) : '',
			self::time($this->runtime),
			self::bytes(memory_get_usage(true)),
			self::bytes(memory_get_peak_usage(true)),
			count($monitor) > 0 ? ('  ' . implode('  ', $monitor)) : ''
		));
	}

	private static function commandVersion(): int
	{
		echo 'v', self::VERSION, PHP_EOL;
		return self::EXIT_CODE_OK;
	}

	private static function commandHelp(string $file): int
	{
		$spacePads = str_repeat(' ', strlen(self::NAME));
		self::printLines([
			self::NAME . '    Linux system monitor by MaximAL of Sijeko' . '    v' . self::VERSION,
			$spacePads . '    =========================================',
			'Usage:',
			"\t" . 'php  ' . $file . '  [options]',
			'Options:',
			"\t" . '-h  --help            ' . 'Show this help',
			"\t" . '-v  --version         ' . 'Show version information',
			"\t" . '-m  --metrics         ' . 'Show metrics example with comments',
			"\t" . '-d  --defaults        ' . 'Print default config file',
			"\t" . '-s  --service         ' . 'Print service config file with installation instructions',
			"\t" . '-c  --config [FILE]   ' . 'Use config file; default: config.php',
			"\t" . '-i  --interval [NUM]  ' . 'Interval in seconds, from ' . self::INTERVAL_MIN .
			' to ' . self::INTERVAL_MAX . '; default: ' . self::INTERVAL_DEFAULT,
			'',
			'This tool gathers various performance metrics',
			'from `/proc` filesystem and prints or sends them to InfluxDB.',
			'',
			'Tested on Debian and Ubuntu installations.',
			'Help for other distributions is highly appreciated.',
			'',
			self::REPO,
		]);
		return self::EXIT_CODE_OK;
	}

	private static function commandMetrics(): int
	{
		self::printLines([
			'##',
			'# Metrics for InfluxDB',
			'#',
			'# @link ' . self::REPO,
			'# @link https://docs.influxdata.com/influxdb/v2/reference/syntax/line-protocol/',
			'##',
			'',
			'#### Uptime ####',
			'# System’s uptime; seconds',
			'uptime,host=[hostname]                 value=[float]',
			'',
			'#### CPU metrics ####',
			'# System load average for the last minute',
			'load_average,host=[hostname]           value=[float]',
			'# 100% * load_average / cpu_count, can be > 100',
			'load_usage,host=[hostname]             value=[float, percents]',
			'# CPU count',
			'cpu_count,host=[hostname]              value=[uint]',
			'# Number of processes currently running on CPUs',
			'cpu_processes_running,host=[hostname]  value=[uint]',
			'# Number of blocked processes, waiting for I/O to complete',
			'cpu_processes_blocked,host=[hostname]  value=[uint]',
			'# CPU usage',
			'cpu_usage,host=[hostname]              value=[float, percents]',
			'',
			'#### Memory metrics ####',
			'# RAM memory; kibibytes (KiB = 1024 B)',
			'memory_total,host=[hostname]           value=[uint]',
			'memory_free,host=[hostname]            value=[uint]',
			'memory_available,host=[hostname]       value=[uint]',
			'memory_buffers,host=[hostname]         value=[uint]',
			'memory_cached,host=[hostname]          value=[uint]',
			'# Swap memory; KiB',
			'memory_swap_total,host=[hostname]      value=[uint]',
			'memory_swap_free,host=[hostname]       value=[uint]',
			'memory_swap_cached,host=[hostname]     value=[uint]',
			'# Used memory, bytes; total − available; KiB',
			'memory_used,host=[hostname]            value=[uint]',
			'# Memory usage; 100% * memory_used / memory_total',
			'memory_usage,host=[hostname]           value=[float, percents]',
			'# Used memory (like htop); total − free − buffers − cached − swap_cached; KiB',
			'memory_used_classic,host=[hostname]    value=[uint]',
			'# Memory usage (like htop); 100% * memory_used_classic / memory_total',
			'memory_usage_classic,host=[hostname]   value=[float, percents]',
			'# Used swap memory; swap_total - swap_free; KiB',
			'memory_swap_used,host=[hostname]       value=[uint]',
			'# Swap usage; 100% * swap_used / swap_total',
			'memory_swap_usage,host=[hostname]      value=[float, percents]',
			'',
			'#### Disk metrics, for each monitored mount point ####',
			'# Disk total; mebibytes (MiB = 1024 * 1024 B)',
			'disk_total,disk=[filesystem],host=[hostname],mount=/      value=[uint]',
			'# Disk used; MiB',
			'disk_used,disk=[filesystem],host=[hostname],mount=/       value=[uint]',
			'# Disk available; MiB',
			'disk_available,disk=[filesystem],host=[hostname],mount=/  value=[uint]',
			'# Disk usage; 100% * disk_used / disk_total',
			'disk_usage,disk=[filesystem],host=[hostname],mount=/      value=[float, percents]',
			'',
			'#### Network metrics, for each monitored interface ####',
			'# Received traffic; bytes',
			'network_in,host=[hostname],interface=[name]               value=[uint]',
			'# Transmitted traffic; bytes',
			'network_out,host=[hostname],interface=[name]              value=[uint]',
			'# Receiving load / speed; bps, bits per second',
			'network_load_in,host=[hostname],interface=[name]          value=[uint]',
			'# Transmitting load / speed; bps, bits per second',
			'network_load_out,host=[hostname],interface=[name]         value=[uint]',
			'',
			'# Most loaded device (with maximum receiving load)',
			'network_load_in_max,host=[hostname],interface=[name]      value=[uint],interface=[name]',
			'# Most loaded device (with maximum transmitting load)',
			'network_load_out_max,host=[hostname],interface=[name]     value=[uint],interface=[name]',
			'',
			'#### IO metrics, for each monitored device ####',
			'# Number of I/O operations currently in progress',
			'io_current_ops,host=[hostname],device=[name]              value=[uint]',
			'# Write operations completed per second',
			'io_write_ops_speed,host=[hostname],device=[name]          value=[float]',
			'# Write speed; KiB per second',
			'io_write_speed,host=[hostname],device=[name]              value=[float]',
			'# Write usage / utilization / saturation',
			'io_write_usage,host=[hostname],device=[name]              value=[float, percents]',
			'# Read operations completed per second',
			'io_read_ops_speed,host=[hostname],device=[name]           value=[float]',
			'# Read speed; KiB per second',
			'io_read_speed,host=[hostname],device=[name]               value=[float]',
			'# Read usage / utilization / saturation',
			'io_read_usage,host=[hostname],device=[name]               value=[float, percents]',
			'# IO usage / utilization / saturation',
			'io_usage,host=[hostname],device=[name]                    value=[float, percents]',
			'',
			'# Most used device (with maximum IO usage)',
			'io_usage_max,host=[hostname],device=[name]                value=[float, percents],device=[name]',
			'',
			'#### Monitor’s own metrics ####',
			'# Monitor’s runtime; seconds',
			'monitor_runtime,host=[hostname]            value=[float]',
			'# Memory allocated in heap; bytes',
			'monitor_memory_alloc,host=[hostname]       value=[uint]',
			'# Metrics send time; seconds',
			'monitor_metrics_sent_time,host=[hostname]  value=[float]'
		]);
		return self::EXIT_CODE_OK;
	}

	private static function commandDefaults(): int
	{
		$defaults = 'return ' . var_export(self::DEFAULT_CONFIG, true);
		$defaults = preg_replace('/ => \n\s+/', ' => ', $defaults);
		$hostname = str_replace(
			'\'',
			'\\\'',
			self::file('/etc/hostname') ?? 'unknown'
		);
		$defaults = str_replace(
			[' array (', ')', '  ', '\'hostname\' => NULL,'],
			[' [', ']', "\t", '\'hostname\' => \'' . $hostname . '\','],
			$defaults
		);
		$defaults = preg_replace('/\'_comment_\w+\' => \'([^\']+)\',/', '// $1', $defaults);

		self::printLines([
			'<?php',
			'',
			'/**',
			' * ' . self::NAME . ' default config.',
			' *',
			' * @generated',
			' * @date ' . date('Y-m-d'),
			' * @time ' . date('H:i:s T'),
			' * @link ' . self::REPO,
			' * @link https://maximals.ru/',
			' * @link https://sijeko.ru/',
			' */',
			'',
			preg_replace('/\[\n\s+]/', '[]', $defaults) . ';',
		]);
		return self::EXIT_CODE_OK;
	}

	private static function commandService(string $file): int
	{
		$user = trim(shell_exec('whoami'));
		$path = escapeshellarg(__FILE__);
		$config = escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'config.php');
		self::printLines([
			'####',
			'# Running ' . self::NAME . ' in background as a current user’s (' . $user . ') service:',
			'# 1. Create service dir:   mkdir -p ~/.local/share/systemd/user',
			'# 2. Create service file:  php  ' . $file . ' --service  >  ~/.local/share/systemd/user/simon.service',
			'# 3. Enable the service:   systemctl --user enable simon',
			'# 4. Start the service:    systemctl --user start  simon',
			'# 5. Extend service life:  sudo loginctl enable-linger ' . $user,
			'# 6. Check service logs:   journalctl --user -fu simon',
			'####',
			'# Disabling current user’s (' . $user . ') service:',
			'# 1. Stop the service:     systemctl --user stop    simon',
			'# 2. Disable the service:  systemctl --user disable simon',
			'# 3. Disable lingering:    sudo loginctl disable-linger ' . $user,
			'# 4. Remove service file:  rm  ~/.local/share/systemd/user/simon.service',
			'####',
			'',
			'[Unit]',
			'Description=' . self::NAME . ', Linux system monitor in a single PHP file',
			'StartLimitIntervalSec=60',
			'StartLimitBurst=4',
			'',
			'[Service]',
			'#ExecStart=/usr/bin/php /path/to/simon/simon.php -c /path/to/simon/config.php -i 15',
			'ExecStart=/usr/bin/php ' . $path .' -c ' . $config . ' -i 15',
			'Restart=on-failure',
			'RestartSec=1',
			'# Do not restart on the following exit codes (won’t be successful):',
			'RestartPreventExitStatus=3 4 5 6 7',
			'# 1, 2 — general (unknown) codes, service should be restarted',
			'',
			'# Hardening',
			'SystemCallArchitectures=native',
			'MemoryDenyWriteExecute=true',
			'NoNewPrivileges=true',
			'',
			'[Install]',
			'WantedBy=default.target',
		]);
		return self::EXIT_CODE_OK;
	}

	private static function log(string $string): void
	{
		echo $string, PHP_EOL;
	}

	private static function printLines(array $lines): void
	{
		echo implode(PHP_EOL, $lines), PHP_EOL;
	}

	private static function file(string $filename): ?string
	{
		if (!is_file($filename)) {
			return null;
		}
		return trim(file_get_contents($filename) ?: '');
	}

	private static function textToLines(string $text): array
	{
		return preg_split('/(\r\n|\r|\n)/', $text);
	}

	private static function matchesAnyPattern(string $string, array $patterns): bool
	{
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $string)) {
				return true;
			}
		}
		return false;
	}

	private static function escapeMeasurement(string $name): string
	{
		// https://docs.influxdata.com/influxdb/latest/reference/syntax/line-protocol/#special-characters
		return str_replace([',', ' '], ['\\,', '\\ '], $name);
	}

	/**
	 * Escape tag key, tag value, or field key for InfluxDB
	 */
	private static function escapeTag(string $value): string
	{
		// https://docs.influxdata.com/influxdb/latest/reference/syntax/line-protocol/#special-characters
		return str_replace([',', '=', ' '], ['\\,', '\\=', '\\ '], $value);
	}

	private static function escapeValue(string $value): string
	{
		// https://docs.influxdata.com/influxdb/latest/reference/syntax/line-protocol/#special-characters
		return str_replace(['"', '\\'], ['\\"', '\\\\'], $value);
	}

	private function influxTimestamp(): string
	{
		if (!$this->hostTime) {
			return '';
		}
		$time = match ($this->config['influx']['precision'] ?? 's') {
			'n', 'ns' => 1000 * (int)round(1_000_000_000 * microtime(true)),
			'μ', 'μs', 'u', 'us' => (int)round(1_000_000 * microtime(true)),
			'm', 'ms' => (int)round(1_000 * microtime(true)),
			default => time(),
		};
		return '    ' . $time;
	}

	private function writeToInflux(string $data): void
	{
		$config = $this->config;
		$url = rtrim($config['influx']['host'], '/') . '/api/v2/write' .
			'?org=' . urlencode($config['influx']['org'] ?? 'default') .
			'&bucket=' . urlencode($config['influx']['bucket'] ?? 'default') .
			'&precision=' . urlencode($config['influx']['precision'] ?? 's');
		$token = $config['influx']['token'];
		$curl = curl_init();
		curl_setopt_array($curl, [
			CURLOPT_URL => $url,
			CURLOPT_HTTPHEADER => ['Authorization: Token ' . $token],
			CURLOPT_POSTFIELDS => $data,
			CURLOPT_POST => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
		]);
		$response = curl_exec($curl);
		$httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		if ($httpCode !== 204) {
			self::writeStderrLine(
				'Error writing to InfluxDB on ' . $url .
				'; data: ' . PHP_EOL . $data . PHP_EOL .
				'; code: ' . $httpCode . '; response: ' . $response
			);
			//return false;
		}
		//return true;
	}

	private static function writeStderrLine(string $message): void
	{
		fwrite(STDERR, $message . PHP_EOL);
	}

	private static function bytes(int $bytes): string
	{
		if ($bytes < 1000) {
			return $bytes . ' B';
		}
		if ($bytes < 999 * 1024) {
			$number = $bytes / 1024.0;
			$unit = ' KiB';
		} elseif ($bytes < 999 * 1024 * 1024) {
			$number = $bytes / 1024.0 / 1024;
			$unit = ' MiB';
		} elseif ($bytes < 999 * 1024 * 1024 * 1024) {
			$number = $bytes / 1024.0 / 1024 / 1024;
			$unit = ' GiB';
		} else {
			$number = $bytes / 1024.0 / 1024 / 1024 / 1024;
			$unit = ' TiB';
		}
		return round($number, $number < 10 ? 2 : 0) . $unit;
	}

	private static function time(float $seconds): string
	{
		//TODO: 59.99 bug
		$seconds = round($seconds, 1);
		$min = (int)($seconds / 60) % 60;
		$hours = (int)($seconds / 60 / 60) % 24;
		$days = (int)($seconds / 60 / 60 / 24);
		$sec = fmod($seconds, 60);
		if ($days > 0) {
			return sprintf('%dd%02d:%02d:%04.1f', $days, $hours, $min, $sec);
		}
		if ($hours > 0) {
			return sprintf('%d:%02d:%04.1f', $hours, $min, $sec);
		}
		return sprintf('%d:%04.1f', $min, $sec);
	}
}


if (isset($argv) && count($argv) > 0 && realpath($argv[0]) === __FILE__) {
	// Run only in CLI mode, if explicitly executed
	exit((new SiMon())->run($argv));
}
