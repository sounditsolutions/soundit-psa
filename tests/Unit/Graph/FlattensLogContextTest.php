<?php

namespace Tests\Unit\Graph;

use PHPUnit\Framework\TestCase;
use Tests\Support\FlattensLogContext;

/**
 * Controls for the test helper Tests\Support\FlattensLogContext itself (#5832, #5678). Synthetic
 * needles only (G-13).
 */
class FlattensLogContextTest extends TestCase
{
    use FlattensLogContext;

    private const PARENT_NEEDLE = 'users/b4l-parent@example.test';

    /** A synthetic bearer planted in a frame argument; not a credential (G-13). */
    private const BEARER_FIXTURE = 'b4l-synthetic-frame-bearer';

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ignoreArgs = ini_get('zend.exception_ignore_args');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', (string) $this->ignoreArgs);
        parent::tearDown();
    }

    /**
     * #5832: a parent's private property shadowed by a subclass's private property of the same
     * name. Both values are scanned; before #5832 the child's overwrote the parent's.
     */
    public function test_a_shadowed_parent_private_property_is_still_scanned(): void
    {
        $scan = self::flattenForScan(['o' => new FlattensLogContextShadowChild]);

        $this->assertStringContainsString('clean-child-value', $scan, 'positive control: the child property');
        $this->assertStringContainsString(self::PARENT_NEEDLE, $scan, "the parent's private property");
    }

    /** #5832: the same for a parent private property shadowed by a public one. */
    public function test_a_parent_private_property_shadowed_by_a_public_one_is_still_scanned(): void
    {
        $scan = self::flattenForScan(['o' => new FlattensLogContextShadowPublicChild]);

        $this->assertStringContainsString('clean-public-value', $scan, 'positive control: the public property');
        $this->assertStringContainsString(self::PARENT_NEEDLE, $scan, "the parent's private property");
    }

    /**
     * #5678 positive control: a credential planted in a frame's array argument, with
     * zend.exception_ignore_args Off. getTraceAsString() (what the log line prints) shows that
     * argument only as 'Array', and flattenForScan() reads no frame argument; the frame-argument
     * scan sees the credential.
     */
    public function test_the_frame_argument_scan_sees_a_credential_in_an_array_argument(): void
    {
        ini_set('zend.exception_ignore_args', '0');
        $e = self::throwWithOptions(['headers' => ['Authorization' => 'Bearer '.self::BEARER_FIXTURE]]);

        $this->assertStringNotContainsString(self::BEARER_FIXTURE, $e->getTraceAsString(), 'positive control: the trace string prints the array as Array');
        $this->assertStringNotContainsString(self::BEARER_FIXTURE, self::flattenForScan(['exception' => $e]), 'positive control: the context scan reads no frame argument');
        $this->assertStringContainsString(self::BEARER_FIXTURE, self::frameArgumentsForScan($e), 'the frame-argument scan sees it');

        // On a previous link too.
        $outer = new \RuntimeException('outer', 0, $e);
        $this->assertStringContainsString(self::BEARER_FIXTURE, self::frameArgumentsForScan($outer), 'the frame-argument scan walks getPrevious()');
    }

    /**
     * #5678 control: with zend.exception_ignore_args On, getTrace() holds no function argument
     * (PHP still records an include/require frame's file path), so the planted credential is
     * absent and the scan's hit above comes from the Off setting.
     */
    public function test_with_ignore_args_on_the_planted_argument_is_not_held(): void
    {
        ini_set('zend.exception_ignore_args', '1');
        $e = self::throwWithOptions(['headers' => ['Authorization' => 'Bearer '.self::BEARER_FIXTURE]]);

        $this->assertStringNotContainsString(self::BEARER_FIXTURE, self::frameArgumentsForScan($e));
        $this->assertStringNotContainsString('failWith', self::frameArgumentsForScan($e), 'no function frame has an argument');
    }

    /** @param array<string, mixed> $options */
    private static function throwWithOptions(array $options): \Throwable
    {
        try {
            self::failWith($options);
        } catch (\RuntimeException $e) {
            return $e;
        }
        self::fail('expected the planted exception');
    }

    /** @param array<string, mixed> $options */
    private static function failWith(array $options): never
    {
        throw new \RuntimeException('planted');
    }
}

class FlattensLogContextShadowParent
{
    private string $x = 'users/b4l-parent@example.test';

    public function parentValue(): string
    {
        return $this->x;
    }
}

class FlattensLogContextShadowChild extends FlattensLogContextShadowParent
{
    private string $x = 'clean-child-value';

    public function childValue(): string
    {
        return $this->x;
    }
}

class FlattensLogContextShadowPublicChild extends FlattensLogContextShadowParent
{
    public string $x = 'clean-public-value';
}
