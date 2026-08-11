<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Architectural guard for the API-key scope cap.
 *
 * Nine separate advisories have now been filed against this plugin for one root
 * cause: an authorization decision that reads super-ness (or a raw permission)
 * off the authenticated ACCOUNT instead of routing through the scope cap, so a
 * scoped key minted on a super-admin account reaches a sink its scopes never
 * covered. GHSA-jqgq-v53x-x99g, GHSA-96xv-p87j-58mx, GHSA-22p9-6fh4-mmf2,
 * GHSA-vq9w-jwj5-wfjg, GHSA-p57v-xhv3-mf2w, GHSA-435x-66r2-jwv2,
 * GHSA-8mjx-xjfv-9c88, GHSA-mcx6-4rvg-7r8v and GHSA-94q7-vrqr-cx5v are all the
 * same bug in a different method.
 *
 * Fixing them one at a time does not converge, because the failure mode is that
 * NEW code keeps reaching for the obvious-looking helper. So this test makes the
 * pattern mechanically unavailable: `$this->isSuperAdmin()` is confined to
 * AbstractApiController (where the chokepoint itself lives), and every other use
 * must carry an explicit written waiver.
 *
 * If this test fails on code you just wrote, the fix is almost always to swap in
 * one of the capped helpers rather than to add a waiver:
 *
 *   - hard gate on super            -> requireSuper($request)
 *   - hard gate on a permission     -> requirePermission($request, $perm)
 *   - need super-ness as a boolean  -> isSuperWithinScope($request)
 *   - soft branch/strip on a perm   -> scopeAllows($request, $perm)
 *
 * A waiver is only correct when the cap genuinely does not apply — the classic
 * case being a check about a TARGET account's privileges rather than about the
 * caller's authority. Write the reason on the marker; "it's fine" is not a
 * reason, and the next person triaging an advisory against this line will read
 * what you wrote.
 */
class ScopeCapChokepointTest extends TestCase
{
    /**
     * The one file allowed to call isSuperAdmin() freely: it defines the helper
     * and implements requirePermission(), which applies the cap BEFORE the super
     * short-circuit. This is the chokepoint every other call site must reach
     * through.
     */
    private const CHOKEPOINT = 'AbstractApiController.php';

    /**
     * Inline waiver marker. Must appear on the offending line or within the few
     * lines above it, together with a written justification.
     */
    private const WAIVER = '@scope-cap-exempt';

    /** How many lines above the call a waiver marker may sit. */
    private const WAIVER_LOOKBEHIND = 6;

    #[Test]
    public function bare_is_super_admin_calls_are_confined_to_the_chokepoint(): void
    {
        $violations = [];

        foreach ($this->sourceFiles() as $path) {
            if (basename($path) === self::CHOKEPOINT) {
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $i => $line) {
                if (!str_contains($line, '$this->isSuperAdmin(')) {
                    continue;
                }
                if ($this->hasWaiverNear($lines, $i)) {
                    continue;
                }

                $violations[] = sprintf(
                    "%s:%d\n    %s",
                    $this->relativePath($path),
                    $i + 1,
                    trim($line),
                );
            }
        }

        $this->assertSame([], $violations, sprintf(
            "Bare isSuperAdmin() outside %s skips the API-key scope cap.\n\n%s\n\n"
            . "Use requireSuper(\$request) / requirePermission(\$request, \$perm) / "
            . "isSuperWithinScope(\$request) / scopeAllows(\$request, \$perm), or add a "
            . "`%s: <reason>` comment if the cap genuinely does not apply.",
            self::CHOKEPOINT,
            implode("\n", $violations),
            self::WAIVER,
        ));
    }

    /**
     * The capped helpers must keep taking the request. Deriving authority from
     * the account alone is precisely what skipped the cap in every advisory
     * listed above, so a refactor that drops the request parameter would quietly
     * reopen the whole family while every other test still passed.
     */
    #[Test]
    public function capped_helpers_still_take_the_request(): void
    {
        $source = file_get_contents($this->chokepointPath());
        $this->assertIsString($source);

        $required = [
            'protected function requirePermission(ServerRequestInterface $request',
            'protected function requireSuper(ServerRequestInterface $request',
            'protected function scopeAllows(ServerRequestInterface $request',
            'protected function isSuperWithinScope(ServerRequestInterface $request',
        ];

        foreach ($required as $signature) {
            $this->assertStringContainsString($signature, $source, sprintf(
                'The capped authorization helper "%s" changed shape. It must keep taking '
                . 'the request: the API-key scope cap lives on the request attribute '
                . '(api_key_scopes), so a helper that only sees the user cannot enforce it.',
                $signature,
            ));
        }
    }

    /**
     * requirePermission() must apply the scope cap BEFORE the super-admin
     * short-circuit. If that order ever inverts, every requireSuper() call in the
     * plugin silently stops capping and the entire family reopens at once.
     */
    #[Test]
    public function scope_cap_is_enforced_before_the_super_short_circuit(): void
    {
        $source = file_get_contents($this->chokepointPath());
        $this->assertIsString($source);

        $start = strpos($source, 'protected function requirePermission(');
        $this->assertNotFalse($start, 'requirePermission() not found.');

        $end = strpos($source, 'protected function requireSuper(', $start);
        $this->assertNotFalse($end, 'requireSuper() not found after requirePermission().');

        $body = substr($source, $start, $end - $start);

        $capAt = strpos($body, "getAttribute('api_key_scopes')");
        $superAt = strpos($body, '$this->isSuperAdmin($user)');

        $this->assertNotFalse($capAt, 'requirePermission() no longer reads the api_key_scopes cap.');
        $this->assertNotFalse($superAt, 'requirePermission() no longer has the super short-circuit.');

        $this->assertLessThan($superAt, $capAt, sprintf(
            'The API-key scope cap must be enforced BEFORE the super-admin short-circuit '
            . 'in requirePermission(). With the order inverted, a scoped key minted on a '
            . 'super-admin account bypasses its own scopes entirely (GHSA-jqgq-v53x-x99g).',
        ));
    }

    private function hasWaiverNear(array $lines, int $index): bool
    {
        $from = max(0, $index - self::WAIVER_LOOKBEHIND);
        for ($i = $from; $i <= $index; $i++) {
            if (str_contains($lines[$i], self::WAIVER)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $root = $this->classesRoot();
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);
        $this->assertNotEmpty($found, 'No PHP sources found under classes/Api — the guard would pass vacuously.');

        return $found;
    }

    private function classesRoot(): string
    {
        return dirname(__DIR__, 3) . '/classes/Api';
    }

    private function chokepointPath(): string
    {
        return $this->classesRoot() . '/Controllers/' . self::CHOKEPOINT;
    }

    private function relativePath(string $path): string
    {
        $base = dirname(__DIR__, 3) . '/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
