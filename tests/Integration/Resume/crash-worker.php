<?php

declare(strict_types=1);

// Runs one transfer in its own process until a chosen crash point, where WorkerCrashResumeTest kills it with SIGKILL.
// Not a test by itself; see CrashWorker.

use App\Tests\Integration\Resume\CrashWorker;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'];

exit(CrashWorker::main($arguments));
