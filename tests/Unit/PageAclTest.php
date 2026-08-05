<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\PageAcl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the per-page ACL resolver behind getgrav/grav-plugin-admin2#150.
 *
 * The rules a page can carry in `header.permissions` were inert on Admin-Next:
 * a grant didn't let a reader save, a deny didn't stop a writer. These tests pin
 * both directions, plus the inheritance and precedence rules the resolver
 * borrows from core's Flex pages (deny beats grant, `inherit: false` stops the
 * walk, the `authors` and `defaults` pseudo-groups).
 */
#[CoversClass(PageAcl::class)]
class PageAclTest extends TestCase
{
    private function user(string $username, array $groups = []): UserInterface
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('get')->willReturnCallback(
            static fn(string $key) => match ($key) {
                'username' => $username,
                'groups' => $groups,
                default => null,
            }
        );

        return $user;
    }

    /**
     * @param array<string, mixed> $header
     */
    private function page(array $header, ?PageInterface $parent = null): PageInterface
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('header')->willReturn(json_decode((string) json_encode($header)));
        $page->method('parent')->willReturn($parent);
        $page->method('path')->willReturn('/pages/' . spl_object_id($page));
        $page->method('template')->willReturn('default');
        $page->method('language')->willReturn(null);

        return $page;
    }

    #[Test]
    public function page_with_no_rules_has_no_opinion(): void
    {
        $page = $this->page(['title' => 'Plain']);

        $this->assertNull((new PageAcl())->authorize($page, $this->user('jane', ['editors']), 'update'));
    }

    #[Test]
    public function group_grant_allows_the_action(): void
    {
        $page = $this->page([
            'permissions' => ['groups' => ['editors' => 'ud']],
        ]);

        $acl = new PageAcl();
        $user = $this->user('jane', ['editors']);

        $this->assertTrue($acl->authorize($page, $user, 'update'));
        $this->assertTrue($acl->authorize($page, $user, 'delete'));
        // 'ud' says nothing about creating, so the account permission decides.
        $this->assertNull($acl->authorize($page, $user, 'create'));
    }

    #[Test]
    public function group_deny_refuses_the_action(): void
    {
        $page = $this->page([
            'permissions' => ['groups' => ['editors' => ['update' => false]]],
        ]);

        $this->assertFalse((new PageAcl())->authorize($page, $this->user('jane', ['editors']), 'update'));
    }

    #[Test]
    public function rules_only_apply_to_the_groups_the_user_is_in(): void
    {
        $page = $this->page([
            'permissions' => ['groups' => ['editors' => 'ud']],
        ]);

        $this->assertNull((new PageAcl())->authorize($page, $this->user('bob', ['authors']), 'update'));
    }

    #[Test]
    public function a_deny_from_one_group_beats_a_grant_from_another(): void
    {
        $page = $this->page([
            'permissions' => [
                'groups' => [
                    'editors' => 'u',
                    'interns' => ['update' => false],
                ],
            ],
        ]);

        $this->assertFalse(
            (new PageAcl())->authorize($page, $this->user('jane', ['editors', 'interns']), 'update')
        );
    }

    #[Test]
    public function defaults_group_applies_to_every_signed_in_user(): void
    {
        $page = $this->page([
            'permissions' => ['groups' => ['defaults' => ['delete' => false]]],
        ]);

        $this->assertFalse((new PageAcl())->authorize($page, $this->user('nobody'), 'delete'));
    }

    #[Test]
    public function authors_group_applies_only_to_listed_authors(): void
    {
        $header = [
            'permissions' => [
                'authors' => ['jane'],
                'groups' => ['authors' => 'ud'],
            ],
        ];

        $acl = new PageAcl();

        $this->assertTrue($acl->authorize($this->page($header), $this->user('jane'), 'update'));
        $this->assertNull($acl->authorize($this->page($header), $this->user('bob'), 'update'));
    }

    #[Test]
    public function a_child_inherits_its_parents_rules(): void
    {
        $parent = $this->page(['permissions' => ['groups' => ['editors' => 'ud']]]);
        $child = $this->page(['title' => 'Child'], $parent);

        $this->assertTrue((new PageAcl())->authorize($child, $this->user('jane', ['editors']), 'update'));
    }

    #[Test]
    public function inherit_false_stops_the_walk_at_the_page(): void
    {
        $parent = $this->page(['permissions' => ['groups' => ['editors' => 'ud']]]);
        $child = $this->page(['permissions' => ['inherit' => false]], $parent);

        $this->assertNull((new PageAcl())->authorize($child, $this->user('jane', ['editors']), 'update'));
    }

    #[Test]
    public function the_pages_own_rule_wins_over_the_parents(): void
    {
        $parent = $this->page(['permissions' => ['groups' => ['editors' => 'ud']]]);
        $child = $this->page(['permissions' => ['groups' => ['editors' => ['update' => false]]]], $parent);

        $this->assertFalse((new PageAcl())->authorize($child, $this->user('jane', ['editors']), 'update'));
    }

    #[Test]
    public function capabilities_fall_back_to_the_account_permissions(): void
    {
        $page = $this->page(['permissions' => ['groups' => ['editors' => 'ud']]]);

        $capabilities = (new PageAcl())->capabilities(
            $page,
            $this->user('jane', ['editors']),
            ['create' => false, 'read' => true, 'update' => false, 'delete' => false, 'publish' => false, 'list' => true],
        );

        $this->assertSame(
            // update/delete come from the page; the rest from the account.
            ['create' => false, 'read' => true, 'update' => true, 'delete' => true, 'publish' => true, 'list' => true],
            $capabilities,
        );
    }

    #[Test]
    public function publish_follows_a_denied_update_when_no_publish_rule_exists(): void
    {
        $page = $this->page(['permissions' => ['groups' => ['editors' => ['update' => false]]]]);

        $capabilities = (new PageAcl())->capabilities(
            $page,
            $this->user('jane', ['editors']),
            array_fill_keys(PageAcl::ACTIONS, true),
        );

        $this->assertFalse($capabilities['update']);
        $this->assertFalse($capabilities['publish']);
        $this->assertTrue($capabilities['read']);
    }

    #[Test]
    public function has_rules_reports_whether_the_chain_carries_any(): void
    {
        $acl = new PageAcl();

        $this->assertFalse($acl->hasRules($this->page(['title' => 'Plain'])));

        $parent = $this->page(['permissions' => ['groups' => ['editors' => 'r']]]);
        $this->assertTrue($acl->hasRules($this->page(['title' => 'Child'], $parent)));
    }
}
