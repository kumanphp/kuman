<?php

use Kuman\App;

return function (App $app) {
    $app->get('/loaded/books', fn() => 'books');
};
