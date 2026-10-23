<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Invitations;

use Grav\Common\Yaml;
use Grav\Plugin\Api\Invitations\InviteStore;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An invite is single use. Each InviteStore instance stands in for a separate
 * request, holding its own snapshot of invites.yaml, so these tests cover two
 * requests working on the same invites at once.
 */
#[CoversClass(InviteStore::class)]
class InviteStoreTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/grav_api_invites_test_' . uniqid();
        @mkdir($this->dataDir, 0775, true);

        $locator = new class ($this->dataDir) {
            public function __construct(private string $dir) {}
            public function findResource(string $uri, bool $absolute = true, bool $first = false): string
            {
                return $this->dir . '/' . preg_replace('~^[a-z-]+://~', '', $uri);
            }
        };

        TestHelper::createMockGrav(['locator' => $locator]);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dataDir);
    }

    private function record(string $token): array
    {
        return ['token' => $token, 'email' => "{$token}@example.test", 'access' => [], 'groups' => [], 'expires' => time() + 3600];
    }

    /** @return array<string, mixed> */
    private function onDisk(): array
    {
        $file = $this->dataDir . '/accounts/invites.yaml';
        return is_file($file) ? (array) Yaml::parse((string) file_get_contents($file)) : [];
    }

    #[Test]
    public function an_invite_can_be_taken_only_once(): void
    {
        $first = new InviteStore();
        $second = new InviteStore();
        $first->add($this->record('aaa'));

        // Both requests have read the invite before either redeems it.
        $this->assertNotNull($first->get('aaa'));
        $this->assertNotNull($second->get('aaa'));

        $this->assertSame('aaa@example.test', $first->take('aaa')['email'] ?? null);
        $this->assertNull($second->take('aaa'));
        $this->assertArrayNotHasKey('aaa', $this->onDisk());
    }

    #[Test]
    public function a_stale_snapshot_never_writes_a_used_invite_back(): void
    {
        $first = new InviteStore();
        $first->add($this->record('aaa'));

        $second = new InviteStore();
        $this->assertNotNull($second->get('aaa'));

        $this->assertTrue($first->remove('aaa'));
        $second->add($this->record('bbb'));

        $onDisk = $this->onDisk();
        $this->assertArrayHasKey('bbb', $onDisk);
        $this->assertArrayNotHasKey('aaa', $onDisk);
    }

    #[Test]
    public function purging_keeps_invites_added_by_another_request(): void
    {
        $first = new InviteStore();
        $second = new InviteStore();
        $expired = ['expires' => time() - 10] + $this->record('old');
        $first->add($expired);
        $this->assertNotNull($second->get('old'));

        $first->add($this->record('new'));

        $this->assertSame(1, $second->purgeExpired());
        $onDisk = $this->onDisk();
        $this->assertArrayHasKey('new', $onDisk);
        $this->assertArrayNotHasKey('old', $onDisk);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmrf($path) : unlink($path);
        }
        rmdir($dir);
    }
}
