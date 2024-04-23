#!/usr/bin/env php8.2
<?php

declare(strict_types=1);

use Symfony\Component\Console\Application;
use AniTools\Command;

# Change working directory into project root
chdir(__DIR__);

include __DIR__ . '/vendor/autoload.php';

// Create Symfony app
$cliApplication = new Application('AWC Tools Backend');
$commands = [
    Command\Scrape::class,
    Command\Import::class,
    Command\MeiliManager::class,
];
foreach ($commands as $command) {
    $cliApplication->add(new $command());
}
echo date('Y-m-d H:i:s') . PHP_EOL;
echo <<<EOC
    ___          _ ______            __    
   /   |  ____  (_)_  __/___  ____  / /____
  / /| | / __ \/ / / / / __ \/ __ \/ / ___/
 / ___ |/ / / / / / / / /_/ / /_/ / (__  ) 
/_/  |_/_/ /_/_/ /_/  \____/\____/_/____/  
                                           
EOC;
echo PHP_EOL;

$cliApplication->run();