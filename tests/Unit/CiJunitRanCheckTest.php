<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * scripts/ci/check-junit-ran.php, the MariaDB lock-order job's guard (#7192).
 * Each fixture is a reduced <testcase>. The checker reads its name, class and
 * assertions attributes and any <skipped/>, <failure> or <error> child. The
 * classname and time attributes are also present, as PHPUnit writes both, but
 * the checker does not read them. PHPUnit's file and line attributes are left
 * out, and nested <testsuite> levels are flattened to one. Name, class,
 * assertions and the <skipped/> child of a skipped test were measured with
 * PHPUnit 11.5's --log-junit.
 */
class CiJunitRanCheckTest extends TestCase
{
    private const MOVE = 'Tests\Feature\Prepay\MoveEntryContractTest::test_m4_lock_order_native_mariadb';

    private const TRANSFER = 'Tests\Feature\Prepay\TransferLockOrderTest::test_reverse_transfer_native_mariadb';

    private static function testcase(string $id, int $assertions, string $child = ''): string
    {
        [$class, $name] = explode('::', $id);

        return '<testcase name="'.$name.'" class="'.$class.'" classname="'.str_replace('\\', '.', $class).'" assertions="'.$assertions.'" time="0.1">'.$child.'</testcase>';
    }

    /** @return array{int, string} exit code and stderr */
    private static function check(string $xml, array $expected = [self::MOVE, self::TRANSFER]): array
    {
        $log = tempnam(sys_get_temp_dir(), 'junit');
        file_put_contents($log, '<?xml version="1.0" encoding="UTF-8"?><testsuites><testsuite name="Feature">'.$xml.'</testsuite></testsuites>');
        $cmd = array_merge([PHP_BINARY, dirname(__DIR__, 2).'/scripts/ci/check-junit-ran.php', $log], $expected);
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $code = proc_close($process);
        unlink($log);

        return [$code, $stderr];
    }

    public function test_both_legs_ran_and_asserted_passes(): void
    {
        [$code, $stderr] = self::check(self::testcase(self::MOVE, 14).self::testcase(self::TRANSFER, 7));

        $this->assertSame(0, $code, 'Both expected tests ran once with assertions, so the check must pass: '.$stderr);
    }

    #[DataProvider('failingLogs')]
    public function test_a_log_that_breaks_a_rule_fails_and_names_it(string $xml, string $reason): void
    {
        [$code, $stderr] = self::check($xml);

        $this->assertSame(1, $code, 'The check must exit 1.');
        $this->assertStringContainsString($reason, $stderr, 'The check must name why it failed.');
    }

    public static function failingLogs(): array
    {
        return [
            'one leg not selected' => [self::testcase(self::MOVE, 14), self::TRANSFER.' ran 0 times'],
            'no leg selected' => ['', self::MOVE.' ran 0 times'],
            'a leg skipped' => [self::testcase(self::MOVE, 14).self::testcase(self::TRANSFER, 0, '<skipped/>'), self::TRANSFER.' skipped or incomplete'],
            'a leg with no assertion' => [self::testcase(self::MOVE, 0).self::testcase(self::TRANSFER, 7), self::MOVE.' made no assertion'],
            'a leg failed' => [self::testcase(self::MOVE, 3, '<failure type="x">x</failure>').self::testcase(self::TRANSFER, 7), self::MOVE.' failure'],
            'a leg errored' => [self::testcase(self::MOVE, 14).self::testcase(self::TRANSFER, 0, '<error type="x">x</error>'), self::TRANSFER.' error'],
            'another test selected' => [self::testcase(self::MOVE, 14).self::testcase(self::TRANSFER, 7).self::testcase('Tests\Feature\Prepay\MoveEntryContractTest::test_m4_lock_order_native_mariadb_extra', 1), 'unexpected test ran: Tests\Feature\Prepay\MoveEntryContractTest::test_m4_lock_order_native_mariadb_extra'],
            'a leg run twice' => [self::testcase(self::MOVE, 14).self::testcase(self::MOVE, 14).self::testcase(self::TRANSFER, 7), self::MOVE.' ran 2 times'],
        ];
    }

    public function test_an_unreadable_log_fails(): void
    {
        [$code, $stderr] = self::check('<testcase');

        $this->assertSame(1, $code, 'An unreadable log must fail the check.');
        $this->assertStringContainsString('cannot read JUnit log', $stderr, 'The check must say the log could not be read.');
    }
}
