<?php

use Kuman\App;

return function (App $app) {
    $app->get('/panel', fn() => 'panel');
    $app->load('stats.php');
};
