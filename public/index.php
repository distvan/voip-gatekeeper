<?php

use App\ApplicationFactory;

require_once __DIR__ . '/../vendor/autoload.php';

ApplicationFactory::createFromEnvironment()->run();
