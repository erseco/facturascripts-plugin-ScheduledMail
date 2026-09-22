<?php

/**
 * This file is part of ScheduledMail plugin for FacturaScripts.
 * Copyright (C) 2025 Ernesto Serrano <info@ernesto.es>
 *
 * PHPUnit bootstrap file for testing
 */

// Define FacturaScripts folder
define('FS_FOLDER', __DIR__ . '/..');

// Load composer autoloader
require_once FS_FOLDER . '/vendor/autoload.php';

// Load FacturaScripts configuration
if (file_exists(FS_FOLDER . '/config.php')) {
    require_once FS_FOLDER . '/config.php';
}

// Initialize minimal FacturaScripts environment for testing
if (!defined('FS_LANG')) {
    define('FS_LANG', 'es_ES');
}

if (!defined('FS_TIMEZONE')) {
    define('FS_TIMEZONE', 'Europe/Madrid');
}

// Register plugin namespaces with the autoloader
$loader = require FS_FOLDER . '/vendor/autoload.php';

// Register FacturaScripts Core
$loader->addPsr4('FacturaScripts\\Core\\', FS_FOLDER . '/Core');

// Register ScheduledMail
$loader->addPsr4('FacturaScripts\\Plugins\\ScheduledMail\\', FS_FOLDER . '/Plugins/ScheduledMail');

$loader->addPsr4('FacturaScripts\\Dinamic\\', FS_FOLDER . '/Dinamic');

// Deploy real table definitions before instantiating plugin models.
FacturaScripts\Core\Kernel::init();
if (!in_array('ScheduledMail', FacturaScripts\Core\Plugins::enabled(), true)) {
    if (!FacturaScripts\Core\Plugins::enable('ScheduledMail')) {
        throw new RuntimeException('Could not enable ScheduledMail for testing');
    }
}
FacturaScripts\Core\Plugins::deploy();
