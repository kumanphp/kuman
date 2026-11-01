<?php

// Loaded in the master. The class below stays unloaded until a worker needs it.
spl_autoload_register(function (string $class) {
    if ($class === 'Fixture\Beacon') {
        require __DIR__ . '/Beacon.php';
    }
});

return [
    'workers' => 1,
    'processes' => [
        'beacon' => ['handler' => 'Fixture\Beacon', 'constructor' => ['beacon-file']],
    ],
];
