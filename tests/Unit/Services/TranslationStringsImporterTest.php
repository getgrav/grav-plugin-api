<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Grav;
use Grav\Common\Yaml;
use Grav\Plugin\Api\Services\TranslationOverrideStore;
use Grav\Plugin\Api\Services\TranslationSourceIndex;
use Grav\Plugin\Api\Services\TranslationStringsImporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationStringsImporter::class)]
class TranslationStringsImporterTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/grav-i18nimport-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/system/languages', 0777, true);
        mkdir($this->tmp . '/user/languages', 0777, true);
        mkdir($this->tmp . '/user/config/plugins', 0777, true);
        mkdir($this->tmp . '/user/plugins', 0777, true);
        mkdir($this->tmp . '/user/themes', 0777, true);
        mkdir($this->tmp . '/cache/compiled/languages', 0777, true);
    }

    protected function tearDown(): void
    {
        I18nTestFixture::rrmdir($this->tmp);
        Grav::resetInstance();
    }

    // ─── reading both storage shapes ──────────────────────────────────

    #[Test]
    public function it_reads_the_list_shape_with_yaml_string_content(): void
    {
        // What the plugin's own CodeMirror field produces: each language is a
        // list entry whose `content` is YAML *text*, not parsed structure.
        $importer = $this->importer([
            ['code' => 'fr', 'content' => "THEME_QUARK:\n  NAV: Menu principal\n"],
        ]);

        $this->assertSame(['fr' => ['THEME_QUARK.NAV' => 'Menu principal']], $importer->read());
    }

    #[Test]
    public function it_reads_the_list_shape_with_already_parsed_content(): void
    {
        // What the config file actually holds after the plugin has saved itself
        // once: same list, but `content` expanded from text into structure.
        $importer = $this->importer([
            ['code' => 'fr', 'content' => ['THEME_QUARK' => ['NAV' => 'Menu principal']]],
        ]);

        $this->assertSame(['fr' => ['THEME_QUARK.NAV' => 'Menu principal']], $importer->read());
    }

    #[Test]
    public function it_reads_the_map_shape(): void
    {
        $importer = $this->importer(['fr' => ['THEME_QUARK' => ['NAV' => 'Menu principal']]]);

        $this->assertSame(['fr' => ['THEME_QUARK.NAV' => 'Menu principal']], $importer->read());
    }

    #[Test]
    public function language_codes_are_normalized(): void
    {
        $importer = $this->importer([
            ['code' => '  FR  ', 'content' => "A: un\n"],
        ]);

        $this->assertSame(['fr' => ['A' => 'un']], $importer->read());
    }

    #[Test]
    public function one_unparseable_language_does_not_lose_the_others(): void
    {
        $importer = $this->importer([
            ['code' => 'fr', 'content' => "A: un\n"],
            ['code' => 'de', 'content' => "\tthis: is: not: yaml\n"],
            ['code' => 'es', 'content' => "A: uno\n"],
        ]);

        $read = $importer->read();

        $this->assertSame(['A' => 'un'], $read['fr']);
        $this->assertSame(['A' => 'uno'], $read['es']);
        $this->assertArrayNotHasKey('de', $read, 'the broken entry is skipped, not fatal');
    }

    #[Test]
    public function it_falls_back_to_the_config_file_when_the_config_service_is_silent(): void
    {
        // A 1.7 → 2.0 migration under the "skip" policy deletes the plugin
        // directory but keeps its config, which is exactly when relying on the
        // config service is least safe.
        file_put_contents(
            $this->tmp . '/user/config/plugins/translation-strings.yaml',
            Yaml::dump(['enabled' => true, 'languages' => [['code' => 'fr', 'content' => "A: un\n"]]])
        );

        $importer = $this->importer(null);

        $this->assertSame(['fr' => ['A' => 'un']], $importer->read());
    }

    #[Test]
    public function nothing_configured_reports_absent(): void
    {
        $report = $this->importer(null)->report();

        $this->assertFalse($report['present']);
        $this->assertSame(0, $report['pending']);
        $this->assertSame([], $report['languages']);
    }

    // ─── classification ───────────────────────────────────────────────

    #[Test]
    public function it_classifies_each_key_against_the_current_state(): void
    {
        $this->shipTheme([
            'A' => 'shipped a',
            'B' => 'shipped b',
            'C' => 'shipped c',
            'D' => 'shipped d',
        ]);
        // Already overridden by hand: B matches what the plugin says, C does not.
        $this->store()->patch('en', ['B' => 'plugin b', 'C' => 'mine c']);

        $importer = $this->importer([
            ['code' => 'en', 'content' => Yaml::dump([
                'A' => 'plugin a',     // new
                'B' => 'plugin b',     // already
                'C' => 'plugin c',     // conflict
                'D' => 'shipped d',    // equals shipped, dropped as a no-op
                'E' => 'plugin e',     // unknown: no source ships E
            ])],
        ]);

        $language = $importer->report()['languages'][0];

        $this->assertSame(2, $language['new'], 'A and E; unknown-ness is orthogonal to status');
        $this->assertSame(1, $language['already']);
        $this->assertSame(1, $language['conflict']);
        $this->assertSame(1, $language['shipped']);
        $this->assertSame(1, $language['unknown'], 'E only');

        $byKey = array_column($language['keys'], null, 'key');
        $this->assertSame(TranslationStringsImporter::NEW, $byKey['A']['status']);
        $this->assertSame(TranslationStringsImporter::ALREADY, $byKey['B']['status']);
        $this->assertSame(TranslationStringsImporter::CONFLICT, $byKey['C']['status']);
        $this->assertSame(TranslationStringsImporter::SHIPPED, $byKey['D']['status']);
        $this->assertSame('mine c', $byKey['C']['current'], 'the conflict names what it would replace');
        $this->assertTrue($byKey['E']['unknown']);
    }

    #[Test]
    public function pending_counts_only_what_importing_would_change(): void
    {
        // Whether there is anything left to do is derived rather than stored, so
        // "already imported" has to fall out of the classification itself.
        $this->shipTheme(['A' => 'shipped a', 'B' => 'shipped b']);
        $importer = $this->importer([
            ['code' => 'en', 'content' => Yaml::dump(['A' => 'plugin a', 'B' => 'shipped b'])],
        ]);

        $this->assertSame(1, $importer->report()['pending']);

        $importer->import();

        $this->assertSame(
            0,
            $this->importer([
                ['code' => 'en', 'content' => Yaml::dump(['A' => 'plugin a', 'B' => 'shipped b'])],
            ])->report()['pending'],
            'a second run has nothing left to do'
        );
    }

    // ─── importing ────────────────────────────────────────────────────

    #[Test]
    public function it_merges_rather_than_replacing_hand_written_overrides(): void
    {
        $this->shipTheme(['A' => 'shipped a', 'KEEP' => 'shipped keep']);
        $store = $this->store();
        $store->patch('en', ['KEEP' => 'hand written']);

        $this->importer([['code' => 'en', 'content' => Yaml::dump(['A' => 'plugin a'])]])->import();

        $this->assertSame(
            ['A' => 'plugin a', 'KEEP' => 'hand written'],
            $this->store()->overrides('en'),
            'an import must never delete overrides it did not put there'
        );
    }

    #[Test]
    public function a_conflict_resolves_to_the_plugin_value(): void
    {
        // The plugin merges last and so is what the site currently renders. An
        // import that changed what visitors see would be the surprise.
        $this->shipTheme(['A' => 'shipped a']);
        $this->store()->patch('en', ['A' => 'mine']);

        $this->importer([['code' => 'en', 'content' => Yaml::dump(['A' => 'theirs'])]])->import();

        $this->assertSame(['A' => 'theirs'], $this->store()->overrides('en'));
    }

    #[Test]
    public function values_equal_to_the_shipped_string_are_not_stored(): void
    {
        $this->shipTheme(['A' => 'shipped a', 'B' => 'shipped b']);

        $result = $this->importer([
            ['code' => 'en', 'content' => Yaml::dump(['A' => 'shipped a', 'B' => 'changed'])],
        ])->import();

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['reverted']);
        $this->assertSame(['B' => 'changed'], $this->store()->overrides('en'));
    }

    #[Test]
    public function unknown_keys_are_imported_and_reported(): void
    {
        // They are kept: a key nothing currently ships may belong to a plugin
        // that is merely disabled right now. But the site owner is told.
        $this->shipTheme(['A' => 'shipped a']);

        $result = $this->importer([
            ['code' => 'en', 'content' => Yaml::dump(['A' => 'mine', 'GONE.AWAY' => 'stale'])],
        ])->import();

        $this->assertSame(['GONE.AWAY'], $result['unknown']);
        $this->assertArrayHasKey('GONE.AWAY', $this->store()->overrides('en'));
    }

    #[Test]
    public function every_configured_language_is_imported(): void
    {
        $this->shipTheme(['A' => 'shipped a']);
        $this->shipTheme(['A' => 'expedie a'], 'fr');

        $result = $this->importer([
            ['code' => 'en', 'content' => Yaml::dump(['A' => 'mine en'])],
            ['code' => 'fr', 'content' => Yaml::dump(['A' => 'mine fr'])],
        ])->import();

        $this->assertSame(2, $result['imported']);
        $this->assertSame(['A' => 'mine en'], $this->store()->overrides('en'));
        $this->assertSame(['A' => 'mine fr'], $this->store()->overrides('fr'));
    }

    // ─── the plugin's own state ───────────────────────────────────────

    #[Test]
    public function it_reports_whether_the_plugin_still_wins(): void
    {
        // While the plugin is enabled it merges after this store and overrides
        // it, so a clean import still leaves the editor unable to change the
        // site. The report has to carry that.
        $this->shipTheme(['A' => 'shipped a']);
        $config = [['code' => 'en', 'content' => Yaml::dump(['A' => 'mine'])]];

        $this->assertTrue($this->importer($config, true)->report()['plugin_enabled']);
        $this->assertFalse($this->importer($config, false)->report()['plugin_enabled']);
    }

    #[Test]
    public function the_plugin_config_is_never_touched(): void
    {
        $path = $this->tmp . '/user/config/plugins/translation-strings.yaml';
        $original = Yaml::dump(['enabled' => true, 'languages' => [['code' => 'en', 'content' => "A: mine\n"]]]);
        file_put_contents($path, $original);
        $this->shipTheme(['A' => 'shipped a']);

        $this->importer(null)->import();

        $this->assertSame($original, file_get_contents($path), 'the move stays reversible');
    }

    // ─── fixture helpers ──────────────────────────────────────────────

    /** @param array<mixed>|null $configured */
    private function importer(?array $configured, bool $enabled = true): TranslationStringsImporter
    {
        $grav = $this->grav($configured, $enabled);
        $sources = new TranslationSourceIndex($grav);

        return new TranslationStringsImporter($grav, $sources, new TranslationOverrideStore($grav, $sources));
    }

    private function store(): TranslationOverrideStore
    {
        $grav = $this->grav(null, false);

        return new TranslationOverrideStore($grav, new TranslationSourceIndex($grav));
    }

    /** @param array<mixed>|null $configured */
    private function grav(?array $configured, bool $enabled): Grav
    {
        Grav::resetInstance();
        $grav = Grav::instance();
        $grav['locator'] = new I18nFakeLocator($this->tmp);
        $grav['config'] = new I18nFakeConfig(
            ['translation-strings' => $enabled],
            'quark',
            [
                'system.languages.default_lang' => 'en',
                'plugins.translation-strings.languages' => $configured,
            ]
        );
        $grav['cache'] = new I18nFakeCache();
        $grav['setup'] = new I18nFakeSetup('localhost');
        $grav['language'] = new I18nFakeLanguage('en');
        $grav['languages'] = new I18nFakeLanguages();

        return $grav;
    }

    /** @param array<mixed> $data */
    private function shipTheme(array $data, string $lang = 'en'): void
    {
        $dir = $this->tmp . '/user/themes/quark/languages';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents("{$dir}/{$lang}.yaml", Yaml::dump($data));
        file_put_contents(dirname($dir) . '/blueprints.yaml', "name: Quark\n");
    }
}
