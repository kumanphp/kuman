<?php

// Without a return: the file defines its routes on $app directly.
$app->get('/loaded/notes', fn() => 'notes');
