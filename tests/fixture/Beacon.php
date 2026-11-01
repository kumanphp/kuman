<?php

namespace Fixture;

class Beacon
{
    public function __construct(private string $name) {}

    public function onWorkerStart(): void
    {
        file_put_contents(__DIR__ . "/$this->name", 'started');
    }
}
