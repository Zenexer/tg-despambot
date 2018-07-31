#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

\Zenexer\Telegram\Bot\App::main(require __DIR__ . '/config.php');
