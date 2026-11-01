<?php

declare(strict_types=1);

namespace Kuman;

use RuntimeException;
use Workerman\Events\Event;
use Workerman\Events\Fiber;
use Workerman\Events\Select;
use Workerman\Protocols\Http;
use Workerman\Worker;

/**
 * Wires Workerman to the application.
 *
 * Runs in the master process, which never loads app.php: workers are forked from
 * the master, so anything loaded here would survive a reload unchanged.
 */
final class Server
{
    private const COMMANDS = ['start', 'stop', 'restart', 'reload', 'status', 'connections'];

    private const LOOPS = ['' => '', 'event' => Event::class, 'fiber' => Fiber::class, 'select' => Select::class];

    private const CALLBACKS = ['onConnect', 'onMessage', 'onClose', 'onError', 'onBufferFull', 'onBufferDrain', 'onWorkerStop', 'onWorkerReload'];

    private const USAGE = <<<'TXT'
    Usage: kuman [command] [options]

    Commands:
      start          Start the server, the default command. Add -d to daemonize.
      stop           Stop the server. Add -g to stop gracefully.
      restart        Restart the server.
      reload         Reload application code without dropping the listen socket.
      status         Show worker status.
      connections    Show open connections.

    Options:
      --port=8642    Listen port, or env PORT.
      --host=0.0.0.0 Listen address, or env KUMAN_HOST.
      --workers=N    Worker processes, or env WORKERS. Defaults to the CPU count.
      --loop=NAME    Event loop: event, select or fiber, or env KUMAN_LOOP.
      --app=app.php  Application file.
      --debug        Show exception details in responses, or env KUMAN_DEBUG=1.

    TXT;

    public static function run(string $basePath): void
    {
        global $argv;

        $command = self::command($argv);
        $config = self::config($basePath, $argv);
        $runtime = "$basePath/runtime";
        $appFile = self::path($basePath, $config['app']);

        if (in_array($command, ['start', 'restart'], true)) {
            self::check($appFile);
        }
        if (!is_dir($runtime) && !mkdir($runtime, 0777, true) && !is_dir($runtime)) {
            throw new RuntimeException("Cannot create $runtime");
        }

        Worker::$pidFile = "$runtime/kuman.pid";
        Worker::$logFile = "$runtime/kuman.log";
        Worker::$statusFile = "$runtime/kuman.status";
        Worker::$stdoutFile = "$runtime/stdout.log";
        Worker::$eventLoopClass = self::LOOPS[$config['loop']];
        Worker::$onMasterReload = self::invalidateOpcache(...);
        Http::requestClass(Request::class);

        $publicPath = (string)realpath(self::path($basePath, $config['public']));
        $worker = new Worker("http://{$config['host']}:{$config['port']}");
        $worker->name = 'kuman';
        $worker->count = $config['workers'];
        $worker->onWorkerStart = static function (Worker $worker) use ($appFile, $publicPath, $config): void {
            $define = require $appFile;
            if (!is_callable($define)) {
                throw new RuntimeException("$appFile must return a callable that receives Kuman\\App");
            }
            $app = new App($publicPath, $config['debug']);
            $define($app);
            $app->start($worker);
        };

        foreach ($config['processes'] as $name => $process) {
            self::process((string)$name, $process);
        }
        self::monitor($basePath, $config, $argv);

        Worker::runAll();
    }

    /**
     * @param array<int, string> $argv
     */
    private static function command(array &$argv): string
    {
        $words = array_values(array_filter(array_slice($argv, 1), static fn(string $arg) => !str_starts_with($arg, '-')));
        $help = array_intersect($argv, ['--help', '-h', 'help']) !== [];
        $unknown = array_diff($words, self::COMMANDS) !== [];

        if ($help || $unknown) {
            echo self::USAGE;
            exit($help ? 0 : 1);
        }
        if ($words === []) {
            $argv[] = 'start';
            return 'start';
        }
        return $words[0];
    }

    /**
     * Precedence, lowest first: defaults, kuman.php, environment, flags.
     *
     * @param array<int, string> $argv
     * @return array<string, mixed>
     */
    private static function config(string $basePath, array $argv): array
    {
        $file = "$basePath/kuman.php";
        $config = [
            'host' => '0.0.0.0',
            'port' => 8642,
            'workers' => 0,
            'loop' => '',
            'debug' => false,
            'watch' => null,
            'app' => 'app.php',
            'public' => 'public',
            'memory_limit' => null,
            'processes' => [],
            ...(is_file($file) ? require $file : []),
        ];

        $env = ['port' => 'PORT', 'workers' => 'WORKERS', 'host' => 'KUMAN_HOST', 'loop' => 'KUMAN_LOOP', 'debug' => 'KUMAN_DEBUG', 'watch' => 'KUMAN_WATCH'];
        foreach ($env as $key => $name) {
            $value = getenv($name);
            if ($value !== false && $value !== '') {
                $config[$key] = $value;
            }
        }
        foreach ($argv as $arg) {
            if (preg_match('/^--(port|workers|host|loop|app)=(.*)$/', $arg, $match)) {
                $config[$match[1]] = $match[2];
            }
        }
        if (in_array('--debug', $argv, true)) {
            $config['debug'] = true;
        }

        $config['debug'] = filter_var($config['debug'], FILTER_VALIDATE_BOOL);
        $config['port'] = self::integer('port', $config['port'], 1, 65535);
        $config['workers'] = self::integer('workers', $config['workers'], 0) ?: self::cpuCount();
        if (!isset(self::LOOPS[$config['loop']])) {
            self::abort("Unknown event loop \"{$config['loop']}\". Use event, select or fiber.");
        }
        if ($config['loop'] === 'fiber' && !class_exists(\Revolt\EventLoop::class)) {
            self::abort('The fiber loop needs revolt/event-loop: composer require revolt/event-loop');
        }
        return $config;
    }

    private static function integer(string $name, mixed $value, int $min, int $max = PHP_INT_MAX): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($number === false) {
            self::abort("Invalid $name: $value");
        }
        return $number;
    }

    private static function check(string $appFile): void
    {
        if (!is_file($appFile)) {
            self::abort("Application file not found: $appFile");
        }
        // Without this a broken file only shows up as a worker restart loop, which -d hides.
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($appFile) . ' 2>&1', $output, $status);
        if ($status !== 0) {
            self::abort(implode("\n", $output));
        }
    }

    /**
     * A process handler is a class name, instantiated after fork so that it can be reloaded.
     *
     * @param array<string, mixed> $process
     */
    private static function process(string $name, array $process): void
    {
        $class = $process['handler'] ?? null;
        if (!is_string($class)) {
            self::abort("Process \"$name\" needs a handler class name.");
        }

        $worker = new Worker($process['listen'] ?? null);
        $worker->name = $name;
        $worker->count = $process['count'] ?? 1;
        $worker->reloadable = $process['reloadable'] ?? true;
        $worker->onWorkerStart = static function (Worker $worker) use ($class, $process): void {
            $handler = new $class(...($process['constructor'] ?? []));
            foreach (self::CALLBACKS as $callback) {
                if (method_exists($handler, $callback)) {
                    $worker->$callback = [$handler, $callback];
                }
            }
            if (method_exists($handler, 'onWorkerStart')) {
                $handler->onWorkerStart($worker);
            }
        };
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $argv
     */
    private static function monitor(string $basePath, array $config, array $argv): void
    {
        $watch = $config['watch'] === null ? !in_array('-d', $argv, true) : filter_var($config['watch'], FILTER_VALIDATE_BOOL);

        $worker = new Worker();
        $worker->name = 'monitor';
        $worker->reloadable = false;
        $worker->onWorkerStart = static function () use ($basePath, $watch, $config): void {
            (new Monitor($basePath, $config['memory_limit']))->start($watch);
        };
    }

    private static function invalidateOpcache(): void
    {
        if (!function_exists('opcache_get_status') || !($status = opcache_get_status())) {
            return;
        }
        foreach (array_keys($status['scripts'] ?? []) as $file) {
            opcache_invalidate($file, true);
        }
    }

    private static function cpuCount(): int
    {
        $count = PHP_OS_FAMILY === 'Darwin' ? shell_exec('sysctl -n hw.ncpu') : shell_exec('nproc');
        return (int)$count ?: 4;
    }

    private static function path(string $basePath, string $path): string
    {
        return $path[0] === '/' ? $path : "$basePath/$path";
    }

    private static function abort(string $message): never
    {
        fwrite(STDERR, "Kuman: $message\n");
        exit(1);
    }
}
