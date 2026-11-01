<?php

use Kuman\App;
use Kuman\Request;
use Kuman\Sse;

return function (App $app) {
    $app->get('/', fn() => 'Kuman is running.');

    $app->get('/users/:id', fn(Request $req) => ['id' => $req->param('id')]);

    $app->sse('/time', function (Request $req, Sse $sse) {
        $sse->every(1, fn() => $sse->send(['time' => date('c')]));
    });

    $app->ws('/echo', [
        'message' => fn($conn, $data) => $conn->send($data),
    ]);
};
