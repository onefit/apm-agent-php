<?php

declare(strict_types=1);

error_reporting(E_ALL | E_STRICT);

use Elastic\Apm\Tests\TestsRootDir;

// Ensure that composer has installed all dependencies
if (!file_exists(dirname(__DIR__) . '/composer.lock')) {
    die(
        "Dependencies must be installed using composer:\n\nphp composer.phar install --dev\n\n"
        . "See http://getcomposer.org for help with installing composer\n"
    );
}

require __DIR__ . '/../vendor/autoload.php';

require __DIR__ . '/polyfills/load.php';

TestsRootDir::$fullPath = __DIR__;
