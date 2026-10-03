<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit;

use Grav\Common\Grav;

/**
 * A Grav instance whose page registry and Twig answer the questions
 * ModularTemplates asks, for the tests of getgrav/grav-plugin-api#55.
 */
final class ModularSite
{
    /**
     * @param array<string, string>|null $modular the registered modular types, null for a site whose registry cannot be asked
     * @param list<string> $twigTemplates templates only Twig knows (a plugin's Twig path)
     * @param bool $twigThrows a Twig that cannot be built
     * @return array{grav: Grav, twig: object} `twig` counts `inits` and the names it was asked for (`asked`)
     */
    public static function create(?array $modular = ['modular/hero' => 'Hero', 'modular/text' => 'Text'], array $twigTemplates = [], bool $twigThrows = false): array
    {
        $pages = $modular === null
            ? new class {}
            : new class ($modular) {
                /** @var array<string, string> */
                private static array $modular = [];

                /** @param array<string, string> $modular */
                public function __construct(array $modular)
                {
                    self::$modular = $modular;
                }

                // Static, as on core's Pages.
                public static function types(): array
                {
                    return ['blog' => 'Blog', 'default' => 'Default', 'modular' => 'Modular'];
                }

                public static function modularTypes(): array
                {
                    return self::$modular;
                }
            };

        $twig = new class ($twigTemplates, $twigThrows) {
            public int $inits = 0;

            /** @var list<string> */
            public array $asked = [];

            /** @param list<string> $templates */
            public function __construct(private readonly array $templates, private readonly bool $throws) {}

            public function init(): void
            {
                $this->inits++;
                if ($this->throws) {
                    throw new \RuntimeException('Twig could not be built');
                }
            }

            public function twig(): object
            {
                return $this;
            }

            public function getLoader(): object
            {
                return $this;
            }

            public function exists(string $name): bool
            {
                $this->asked[] = $name;

                return in_array($name, $this->templates, true);
            }
        };

        return ['grav' => TestHelper::createMockGrav(['pages' => $pages, 'twig' => $twig]), 'twig' => $twig];
    }
}
