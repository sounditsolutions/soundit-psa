<?php

namespace Tests\Unit;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class GcVerifyParallelSuppressionTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = sys_get_temp_dir().'/gc-suppression-'.bin2hex(random_bytes(8));
        mkdir($this->fixture, 0700);
        (new Process(['git', 'init', '--quiet', $this->fixture]))->mustRun();
        file_put_contents($this->fixture.'/.env', 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', '--', $this->fixture]))->mustRun();
        parent::tearDown();
    }

    private function repositorySource(): DOMElement
    {
        $document = new DOMDocument;
        self::assertTrue($document->load(dirname(__DIR__, 2).'/phpunit.xml'));
        $source = $document->getElementsByTagName('source')->item(0);
        self::assertInstanceOf(DOMElement::class, $source);

        return $source;
    }

    public function test_repository_enables_both_warning_suppression_overrides(): void
    {
        $source = $this->repositorySource();
        self::assertSame('true', $source->getAttribute('ignoreSuppressionOfWarnings'));
        self::assertSame('true', $source->getAttribute('ignoreSuppressionOfPhpWarnings'));
    }

    public static function cases(): array
    {
        $cases = [];
        foreach (['parallel' => '2', 'serial' => false] as $mode => $processes) {
            $cases[$mode.' user warning'] = [$processes, '@trigger_error("deliberate suppressed warning", E_USER_WARNING);', 1];
            $cases[$mode.' PHP warning'] = [$processes, '@file_get_contents(__DIR__."/missing-file");', 1];
            $cases[$mode.' clean control'] = [$processes, '', 0];
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_gate_handles_suppressed_warnings(string|false $processes, string $warning, int $warnings): void
    {
        $root = dirname(__DIR__, 2);
        file_put_contents($this->fixture.'/GateProbeTest.php', '<?php
class GateProbeTest extends \\PHPUnit\\Framework\\TestCase {
    public function test_probe(): void { '.$warning.' self::assertTrue(true); }
}
');
        // Copy source attributes, not hard-coded values: removing either repository
        // switch must reach the real runner, independently for each warning class.
        $document = new DOMDocument;
        $document->loadXML('<phpunit cacheResult="false" colors="false"><testsuites><testsuite name="probe"><file>GateProbeTest.php</file></testsuite></testsuites></phpunit>');
        $source = $document->createElement('source');
        foreach ($this->repositorySource()->attributes as $attribute) {
            $source->setAttribute($attribute->name, $attribute->value);
        }
        $document->documentElement->appendChild($source);
        $document->save($this->fixture.'/phpunit.xml');

        // Only artisan's dispatch is adapted. Both runners and every warning-policy
        // argument are real; no application boot or nested repository suite is needed.
        $vendor = var_export($root.'/vendor/bin/', true);
        file_put_contents($this->fixture.'/artisan', '<?php
if ($argv[1] === "config:clear") { exit(0); }
if ($argv[1] !== "test") { exit(99); }
$args = array_slice($argv, 2);
$parallel = array_search("--parallel", $args, true);
$runner = $parallel === false ? "phpunit" : "paratest";
if ($parallel !== false) { unset($args[$parallel]); }
$args = array_merge([PHP_BINARY, '.$vendor.'.$runner, "--configuration", "phpunit.xml", "--colors=never"], array_values($args));
passthru(implode(" ", array_map("escapeshellarg", $args)), $status);
exit($status);
');
        $run = new Process(['bash', $root.'/scripts/gc-verify.sh'], $this->fixture, [
            'GC_VERIFY_PARALLEL' => $processes,
            'GC_VERIFY_JUNIT' => false,
        ]);
        $run->setTimeout(60);
        $run->run();
        $output = $run->getOutput().$run->getErrorOutput();
        self::assertStringContainsString($processes === false ? 'PHPUnit ' : 'ParaTest ', $output);
        if ($warnings > 0) {
            self::assertStringContainsString('Tests: 1, Assertions: 1, Warnings: 1.', $output);
            self::assertNotSame(0, $run->getExitCode(), $output);
            self::assertStringContainsString('gc-verify: FAIL', $output);
            self::assertStringNotContainsString('gc-verify: PASS', $output);
            self::assertStringNotContainsString('[2/3]', $output);
        } else {
            self::assertStringContainsString('OK (1 test, 1 assertion)', $output);
            self::assertSame(0, $run->getExitCode(), $output);
            self::assertStringContainsString('gc-verify: PASS', $output);
            self::assertStringNotContainsString('gc-verify: FAIL', $output);
        }
    }
}
