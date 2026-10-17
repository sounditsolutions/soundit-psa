<?php

/*
 * CI guard for the MariaDB lock-order job (#7192): PHPUnit's exit code says
 * nothing about WHICH tests a --filter selected, so one renamed leg leaves the
 * job green on the other. This reads the run's JUnit log and exits non-zero
 * unless every expected test ran exactly once with at least one assertion and
 * no skip, failure or error, and no other test ran.
 *
 * Usage: php scripts/ci/check-junit-ran.php <junit.xml> <Class::method>...
 */
if ($argc < 3) {
    fwrite(STDERR, "usage: php scripts/ci/check-junit-ran.php <junit.xml> <Class::method>...\n");
    exit(2);
}

$xml = @simplexml_load_file($argv[1]);
if ($xml === false) {
    fwrite(STDERR, "FAIL: cannot read JUnit log {$argv[1]}\n");
    exit(1);
}

$expected = array_slice($argv, 2);
$seen = [];
$problems = [];
foreach ($xml->xpath('//testcase') as $case) {
    $id = (string) $case['class'].'::'.(string) $case['name'];
    $seen[$id] = ($seen[$id] ?? 0) + 1;
    if (! in_array($id, $expected, true)) {
        $problems[] = "unexpected test ran: {$id}";

        continue;
    }
    if ((int) $case['assertions'] < 1) {
        $problems[] = "{$id} made no assertion";
    }
    // PHPUnit writes <skipped/> for an incomplete test as well as a skipped one.
    foreach (['skipped' => 'skipped or incomplete', 'failure' => 'failure', 'error' => 'error'] as $outcome => $label) {
        if (isset($case->{$outcome})) {
            $problems[] = "{$id} {$label}";
        }
    }
}
foreach ($expected as $id) {
    $count = $seen[$id] ?? 0;
    if ($count !== 1) {
        $problems[] = "{$id} ran {$count} times, expected once";
    }
}

if ($problems !== []) {
    fwrite(STDERR, 'FAIL: '.implode("\nFAIL: ", $problems)."\n");
    exit(1);
}

echo 'OK: '.count($expected)." expected tests each ran once and asserted.\n";
