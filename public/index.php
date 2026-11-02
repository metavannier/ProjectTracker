<?php
declare(strict_types=1);

// Front controller: every request that is not a static file (logo.png) arrives here.
require __DIR__ . '/../src/bootstrap.php';

(new ProjectTracker\App())->run();
