<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Popularity;

use Grav\Plugin\Api\Popularity\PopularityStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The store keeps page counts only. Earlier versions also wrote a map of
 * hashed visitor IPs that nothing read; it must not be written, imported or
 * left behind (getgrav/grav-plugin-api#44).
 */
#[CoversClass(PopularityStore::class)]
class PopularityStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/api-popularity-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function stored(): array
    {
        return json_decode((string) file_get_contents($this->dir . '/popularity.json'), true);
    }

    #[Test]
    public function a_hit_counts_the_page_without_storing_visitors(): void
    {
        $store = new PopularityStore($this->dir);
        $store->recordHit('/blog', strtotime('2026-09-22 12:00:00'));
        $store->recordHit('/blog', strtotime('2026-09-22 12:05:00'));

        $data = $this->stored();
        $this->assertSame(2, $data['pages']['/blog']);
        $this->assertArrayNotHasKey('visitors', $data);
    }

    #[Test]
    public function existing_visitor_hashes_are_dropped_and_counts_kept(): void
    {
        $today = date('Y-m-d');
        file_put_contents($this->dir . '/popularity.json', json_encode([
            'version' => 2,
            'daily' => [$today => 5],
            'monthly' => [],
            'pages' => ['/blog' => 5],
            'visitors' => ['a1b2c3' => time()],
        ]));

        (new PopularityStore($this->dir))->recordHit('/blog');

        $data = $this->stored();
        $this->assertSame(6, $data['pages']['/blog']);
        $this->assertSame(6, $data['daily'][$today]);
        $this->assertArrayNotHasKey('visitors', $data);
    }

    #[Test]
    public function a_legacy_visitors_file_is_deleted_not_imported(): void
    {
        file_put_contents($this->dir . '/totals.json', json_encode(['/blog' => 3]));
        file_put_contents($this->dir . '/visitors.json', json_encode([sha1('203.0.113.7') => time()]));

        (new PopularityStore($this->dir))->recordHit('/blog');

        $this->assertSame(4, $this->stored()['pages']['/blog']);
        $this->assertArrayNotHasKey('visitors', $this->stored());
        $this->assertFileDoesNotExist($this->dir . '/visitors.json');
        $this->assertFileDoesNotExist($this->dir . '/visitors.json.migrated');
        $this->assertFileExists($this->dir . '/totals.json.migrated');
    }
}
