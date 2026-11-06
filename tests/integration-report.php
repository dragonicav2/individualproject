<?php

declare(strict_types=1);

/**
 * Chapter 5.2 integration scenarios, run independently with isolated rollback.
 * Run all: php tests/integration-report.php
 * Run one: php tests/integration/scenarios/01-authentication.php
 */
require __DIR__.'/integration/Support.php';

$files = [
    __DIR__.'/integration/scenarios/01-authentication.php',
    __DIR__.'/integration/scenarios/02-private-team-invite.php',
    __DIR__.'/integration/scenarios/03-tournament-checkin.php',
    __DIR__.'/integration/scenarios/04-admin-analytics.php',
    __DIR__.'/integration/scenarios/05-password-reset.php',
];

$passed = 0;
$total = count($files);
foreach ($files as $index => $file) {
    $scenario = require $file;
    try {
        runIntegrationScenario($scenario['title'], $scenario['test']);
        $passed++;
    } catch (Throwable) {
        fwrite(STDERR, "\nSTOPPED: {$passed}/{$total} scenarios passed. Fix the failed scenario, then rerun the command.\n");
        exit(1);
    }
}

echo "\nALL PASS: {$passed}/{$total} Chapter 5.2 integration scenarios passed.\n";
