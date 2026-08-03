<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\SystemController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DELETE /system/logs truncates the file and leaves one marker entry naming
 * whoever cleared it. The marker is the part with a contract: the log viewer
 * parses `[datetime] channel.LEVEL: message [] []`, so a malformed line would
 * silently vanish from the very view that shows the clear happened. The channel
 * has to follow the file (grav.log is `grav`, security.log is `grav-security`),
 * which buildClearedMarker() reads back off the log's own first line.
 */
class SystemControllerClearLogTest extends TestCase
{
    private string $tmpFile = '';

    protected function tearDown(): void
    {
        if ($this->tmpFile !== '' && file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        Grav::resetInstance();
    }

    #[Test]
    public function marker_parses_the_same_way_the_viewer_parses_a_log_line(): void
    {
        $marker = $this->buildMarker("[2026-08-01T09:15:00.123456+00:00] grav.WARNING: something [] []\n", 'andy');

        // Same shape LogsTab renders from: date, logger, level, message.
        self::assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+\-]\d{2}:\d{2}\] grav\.NOTICE: Log cleared by andy \[\] \[\]\R$/',
            $marker,
        );
    }

    #[Test]
    public function marker_reuses_the_channel_of_the_log_it_clears(): void
    {
        $marker = $this->buildMarker("[2026-08-01T09:15:00.123456+00:00] grav-security.ALERT: blocked [] []\n", 'andy');

        self::assertStringContainsString('grav-security.NOTICE: Log cleared by andy', $marker);
    }

    #[Test]
    public function marker_cannot_be_forged_through_the_username(): void
    {
        $marker = $this->buildMarker('', "andy\n[2026-01-01T00:00:00.000000+00:00] grav.INFO: nothing to see here");

        // One line, so no injected second entry.
        self::assertSame(1, substr_count($marker, "\n"));
        self::assertStringNotContainsString('nothing to see here [] []', $marker);
    }

    #[Test]
    public function marker_falls_back_to_the_grav_channel_for_an_empty_or_unparseable_log(): void
    {
        self::assertStringContainsString('grav.NOTICE:', $this->buildMarker('', 'andy'));
        self::assertStringContainsString('grav.NOTICE:', $this->buildMarker("not a log line at all\n", 'andy'));
    }

    /**
     * Write $contents to a temp file, then run the controller's private
     * buildClearedMarker() against it.
     */
    private function buildMarker(string $contents, string $username): string
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'grav-api-log-') ?: '';
        file_put_contents($this->tmpFile, $contents);

        $controller = (new \ReflectionClass(SystemController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SystemController::class, 'buildClearedMarker');

        return (string) $method->invoke($controller, $this->tmpFile, $username);
    }
}
