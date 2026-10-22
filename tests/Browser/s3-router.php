<?php

require_once __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/s3-clock.php';
chdir(__DIR__.'/../../public');

return require __DIR__.'/../../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
