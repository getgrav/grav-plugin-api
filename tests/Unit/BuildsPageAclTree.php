<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\User\Interfaces\UserInterface;

/**
 * A mock page tree with page-level permissions, modelled on the intranet in
 * getgrav/grav-plugin-admin2#209: department sections that grant their own
 * editors, deny others, and a few holes in between.
 *
 *     /                            (pages root)
 *     /home
 *     /outils                      redaction-informatique: crupl
 *     /outils/logiciels
 *     /outils/procedures-it        redaction-informatique: '-u'
 *     /outils/procedures-it/sauvegardes
 *     /rh                          redaction-informatique: '-rl', redaction-rh: crupl
 *     /rh/recrutement
 *     /rh/formation
 *     /rh/formation/outils-informatiques   redaction-informatique: crupl
 *     /rh/formation/management
 *     /rh/annuaire                 redaction-informatique: rl
 *     /communication
 *     /communication/charte        redaction-informatique: '-rl'
 *     /communication/newsletter
 *     /communication/newsletter/edition-it   redaction-informatique: crupl
 *     /direction                   defaults: '-rl'
 *     /direction/comite
 *     /direction/public            inherit: false (no rules of its own)
 *     /blog                        authors: [alice], groups: { authors: 'rl-u' }
 *     /blog/post
 */
trait BuildsPageAclTree
{
    /** @var array<string, PageInterface> raw route => page */
    private array $tree = [];

    /** @var array<string, list<PageInterface>> */
    private array $treeChildren = [];

    /** @var array<string, PageInterface> */
    private array $treeParents = [];

    /**
     * @return array<string, PageInterface> raw route => page, root under '/'
     */
    private function buildTree(bool $withRules = true): array
    {
        $this->tree = [];
        $this->treeChildren = [];
        $this->treeParents = [];

        $rules = $withRules ? [
            '/outils' => ['groups' => ['redaction-informatique' => 'crupl']],
            '/outils/procedures-it' => ['groups' => ['redaction-informatique' => '-u']],
            '/rh' => ['groups' => ['redaction-informatique' => '-rl', 'redaction-rh' => 'crupl']],
            '/rh/formation/outils-informatiques' => ['groups' => ['redaction-informatique' => 'crupl']],
            '/rh/annuaire' => ['groups' => ['redaction-informatique' => 'rl']],
            '/communication/charte' => ['groups' => ['redaction-informatique' => '-rl']],
            '/communication/newsletter/edition-it' => ['groups' => ['redaction-informatique' => 'crupl']],
            '/direction' => ['groups' => ['defaults' => '-rl']],
            '/direction/public' => ['inherit' => false],
            '/blog' => ['authors' => ['alice'], 'groups' => ['authors' => 'rl-u']],
        ] : [];

        $routes = [
            '/home', '/outils', '/outils/logiciels', '/outils/procedures-it', '/outils/procedures-it/sauvegardes',
            '/rh', '/rh/recrutement', '/rh/formation', '/rh/formation/outils-informatiques',
            '/rh/formation/management', '/rh/annuaire', '/communication', '/communication/charte',
            '/communication/newsletter', '/communication/newsletter/edition-it', '/direction', '/direction/comite', '/direction/public',
            '/blog', '/blog/post',
        ];

        $this->treePage('/', [], true);
        foreach ($routes as $route) {
            $header = ['title' => $route];
            if (isset($rules[$route])) {
                $header['permissions'] = $rules[$route];
            }
            $this->treePage($route, $header);
        }

        return $this->tree;
    }

    /**
     * @param array<string, mixed> $header
     */
    private function treePage(string $route, array $header, bool $root = false): PageInterface
    {
        $parentRoute = $root ? null : (substr_count($route, '/') === 1 ? '/' : substr($route, 0, (int) strrpos($route, '/')));
        $order = count($this->treeChildren[$parentRoute ?? ''] ?? []) + 1;

        $page = $this->createMock(PageInterface::class);
        $page->method('header')->willReturn(json_decode((string) json_encode($header ?: ['title' => 'root'])));
        $page->method('rawRoute')->willReturn($route);
        $page->method('route')->willReturn($route);
        $page->method('path')->willReturn('/p' . ($root ? '' : $route));
        $page->method('root')->willReturn($root);
        $page->method('exists')->willReturn(!$root);
        $page->method('template')->willReturn('default');
        $page->method('language')->willReturn(null);
        $page->method('order')->willReturn($order);
        $page->method('slug')->willReturn($root ? '' : basename($route));
        $page->method('parent')->willReturnCallback(fn () => $this->treeParents[$route] ?? null);
        $page->method('children')->willReturnCallback(fn () => new \ArrayIterator($this->treeChildren[$route] ?? []));

        $this->tree[$route] = $page;
        if ($parentRoute !== null) {
            $this->treeParents[$route] = $this->tree[$parentRoute];
            $this->treeChildren[$parentRoute][] = $page;
        }

        return $page;
    }

    /**
     * @param list<string> $groups
     * @param array<string, mixed> $access
     */
    private function treeUser(string $username, array $groups, array $access = []): UserInterface
    {
        return TestHelper::createMockUser($username, [
            'username' => $username,
            'groups' => $groups,
            'access' => $access ?: ['api' => ['access' => true, 'pages' => ['read' => true]]],
        ]);
    }

    /**
     * @param iterable<PageInterface> $pages
     * @return list<string>
     */
    private static function routesOf(iterable $pages): array
    {
        $routes = [];
        foreach ($pages as $page) {
            $routes[] = (string) $page->rawRoute();
        }

        return $routes;
    }
}
