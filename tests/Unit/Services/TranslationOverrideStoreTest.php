<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Grav;
use Grav\Common\Yaml;
use Grav\Plugin\Api\Services\TranslationOverrideStore;
use Grav\Plugin\Api\Services\TranslationSourceIndex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationOverrideStore::class)]
class TranslationOverrideStoreTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/grav-i18nstore-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/system/languages', 0777, true);
        mkdir($this->tmp . '/user/languages', 0777, true);
        mkdir($this->tmp . '/user/plugins', 0777, true);
        mkdir($this->tmp . '/user/themes', 0777, true);
        mkdir($this->tmp . '/cache/compiled/languages', 0777, true);
    }

    protected function tearDown(): void
    {
        I18nTestFixture::rrmdir($this->tmp);
        Grav::resetInstance();
    }

    #[Test]
    public function it_writes_an_override_as_nested_yaml(): void
    {
        $this->shipTheme(['THEME_QUARK' => ['NAV' => 'Primary']]);
        $store = $this->store();

        $result = $store->patch('en', ['THEME_QUARK.NAV' => 'Main menu']);

        $this->assertSame(['THEME_QUARK.NAV' => 'Main menu'], $result['written']);
        $this->assertSame(
            ['THEME_QUARK' => ['NAV' => 'Main menu']],
            Yaml::parse($store->raw('en')),
            'stored in the same nested shape as any other Grav language file'
        );
    }

    #[Test]
    public function setting_a_value_equal_to_the_shipped_one_reverts_instead_of_storing(): void
    {
        // Storing an identical value would silently stop tracking the source's
        // future wording changes, so it is treated as a revert.
        $this->shipTheme(['THEME_QUARK' => ['NAV' => 'Primary']]);
        $store = $this->store();
        $store->patch('en', ['THEME_QUARK.NAV' => 'Main menu']);

        $result = $store->patch('en', ['THEME_QUARK.NAV' => 'Primary']);

        $this->assertSame([], $result['written']);
        $this->assertSame(['THEME_QUARK.NAV'], $result['reverted']);
        $this->assertSame(['THEME_QUARK.NAV'], $result['removed']);
        $this->assertSame([], $store->overrides('en'));
    }

    #[Test]
    public function unsetting_a_key_removes_it(): void
    {
        $this->shipTheme(['A' => 'shipped a', 'B' => 'shipped b']);
        $store = $this->store();
        $store->patch('en', ['A' => 'mine a', 'B' => 'mine b']);

        $result = $store->patch('en', [], ['A']);

        $this->assertSame(['A'], $result['removed']);
        $this->assertSame(['B' => 'mine b'], $store->overrides('en'));
    }

    #[Test]
    public function the_file_is_deleted_once_the_last_override_is_removed(): void
    {
        $this->shipTheme(['A' => 'shipped a']);
        $store = $this->store();
        $store->patch('en', ['A' => 'mine']);
        $this->assertFileExists($store->path('en'));

        $store->patch('en', [], ['A']);

        $this->assertFileDoesNotExist($store->path('en'), 'an empty override file is noise, not state');
    }

    #[Test]
    public function it_reports_keys_no_source_ships(): void
    {
        // A typo'd key in the old plugin did nothing, forever, with no feedback.
        $this->shipTheme(['REAL' => 'shipped']);
        $store = $this->store();

        $result = $store->patch('en', ['REAL' => 'mine', 'TYPO.KEY' => 'oops']);

        $this->assertSame(['TYPO.KEY'], $result['unknown']);
        $this->assertArrayHasKey('TYPO.KEY', $result['written'], 'still saved — it is a warning, not a rejection');
    }

    #[Test]
    public function replace_swaps_the_whole_file(): void
    {
        $this->shipTheme(['A' => 'shipped a', 'B' => 'shipped b']);
        $store = $this->store();
        $store->patch('en', ['A' => 'mine a']);

        $report = $store->replace('en', "B: mine b\n");

        $this->assertSame(1, $report['count']);
        $this->assertSame(['B' => 'mine b'], $store->overrides('en'));
    }

    #[Test]
    public function replace_drops_entries_that_match_the_shipped_value(): void
    {
        $this->shipTheme(['A' => 'shipped a', 'B' => 'shipped b']);
        $store = $this->store();

        $report = $store->replace('en', "A: shipped a\nB: mine b\n");

        $this->assertSame(['A'], $report['dropped']);
        $this->assertSame(['B' => 'mine b'], $store->overrides('en'));
    }

    #[Test]
    public function replace_with_empty_input_clears_everything(): void
    {
        $this->shipTheme(['A' => 'shipped a']);
        $store = $this->store();
        $store->patch('en', ['A' => 'mine']);

        $store->replace('en', '   ');

        $this->assertSame([], $store->overrides('en'));
        $this->assertFileDoesNotExist($store->path('en'));
    }

    #[Test]
    public function a_scoped_replace_leaves_everything_outside_the_scope_alone(): void
    {
        // The destructive case: "edit as YAML" on a filtered view must not
        // delete the overrides that view isn't showing.
        $this->shipTheme(['THEME_QUARK' => ['NAV' => 'Primary'], 'PLUGIN_LOGIN' => ['BTN' => 'Sign in']]);
        $store = $this->store();
        $store->patch('en', ['THEME_QUARK.NAV' => 'mine nav', 'PLUGIN_LOGIN.BTN' => 'mine btn']);

        $report = $store->replace('en', "THEME_QUARK:\n  NAV: rewritten\n", 'THEME_QUARK');

        $this->assertSame(
            ['PLUGIN_LOGIN.BTN' => 'mine btn', 'THEME_QUARK.NAV' => 'rewritten'],
            $store->overrides('en')
        );
        $this->assertSame(1, $report['count']);
    }

    #[Test]
    public function an_empty_scoped_replace_clears_only_that_scope(): void
    {
        $this->shipTheme(['THEME_QUARK' => ['NAV' => 'Primary'], 'PLUGIN_LOGIN' => ['BTN' => 'Sign in']]);
        $store = $this->store();
        $store->patch('en', ['THEME_QUARK.NAV' => 'mine nav', 'PLUGIN_LOGIN.BTN' => 'mine btn']);

        $report = $store->replace('en', '', 'THEME_QUARK');

        $this->assertSame(['PLUGIN_LOGIN.BTN' => 'mine btn'], $store->overrides('en'));
        $this->assertSame(1, $report['removed']);
    }

    #[Test]
    public function a_scoped_replace_rejects_keys_from_outside_the_scope(): void
    {
        // Otherwise a scoped editor is a back door to rewriting keys the user
        // isn't looking at.
        $this->shipTheme(['THEME_QUARK' => ['NAV' => 'Primary'], 'PLUGIN_LOGIN' => ['BTN' => 'Sign in']]);
        $store = $this->store();

        $this->expectException(\RuntimeException::class);
        $store->replace('en', "PLUGIN_LOGIN:\n  BTN: sneaky\n", 'THEME_QUARK');
    }

    #[Test]
    public function replace_rejects_unparseable_yaml(): void
    {
        $store = $this->store();

        $this->expectException(\RuntimeException::class);
        $store->replace('en', "\tbroken: [unclosed");
    }

    #[Test]
    public function replace_rejects_yaml_that_is_not_a_mapping(): void
    {
        $store = $this->store();

        $this->expectException(\RuntimeException::class);
        $store->replace('en', "- just\n- a list\n");
    }

    #[Test]
    public function a_language_code_from_the_wire_cannot_escape_the_languages_folder(): void
    {
        $store = $this->store();

        $this->expectException(\InvalidArgumentException::class);
        $store->path('../../config/system');
    }

    #[Test]
    public function writing_drops_both_compiled_language_caches(): void
    {
        // The file-list cache is the one that bites: without deleting it a newly
        // created language file can stay undiscovered behind the check interval.
        $dir = $this->tmp . '/cache/compiled/languages';
        file_put_contents("{$dir}/master-localhost.php", '<?php return [];');
        file_put_contents("{$dir}/filelist-languages-localhost.php", '<?php return [];');

        $this->shipTheme(['A' => 'shipped a']);
        $this->store()->patch('en', ['A' => 'mine']);

        $this->assertFileDoesNotExist("{$dir}/master-localhost.php");
        $this->assertFileDoesNotExist("{$dir}/filelist-languages-localhost.php");
    }

    #[Test]
    public function apply_runtime_merges_the_active_and_default_languages_only(): void
    {
        $this->shipTheme(['A' => 'shipped a']);
        $store = $this->store();
        $store->patch('en', ['A' => 'english override']);
        $store->patch('fr', ['A' => 'french override']);
        $store->patch('de', ['A' => 'german override']);

        $languages = new I18nFakeLanguages();
        $store = $this->store(languages: $languages, activeLang: 'fr');
        $store->applyRuntime();

        $merged = array_merge(...array_map(static fn(array $m): array => array_keys($m), $languages->merged));
        sort($merged);
        $this->assertSame(['en', 'fr'], $merged, 'active language plus the default fallback, not every translation on disk');
    }

    #[Test]
    public function apply_runtime_unflattens_back_into_language_shape(): void
    {
        $this->shipTheme(['THEME_QUARK' => ['NAV' => 'Primary']]);
        $this->store()->patch('en', ['THEME_QUARK.NAV' => 'Main menu']);

        $languages = new I18nFakeLanguages();
        $this->store(languages: $languages, activeLang: 'en')->applyRuntime();

        $this->assertSame(
            [['en' => ['THEME_QUARK' => ['NAV' => 'Main menu']]]],
            $languages->merged
        );
    }

    #[Test]
    public function apply_runtime_is_a_no_op_with_no_overrides(): void
    {
        $languages = new I18nFakeLanguages();
        $this->store(languages: $languages, activeLang: 'en')->applyRuntime();

        $this->assertSame([], $languages->merged);
    }

    // ─── fixture helpers ──────────────────────────────────────────────

    private function store(
        ?I18nFakeLanguages $languages = null,
        string $activeLang = 'en',
    ): TranslationOverrideStore {
        Grav::resetInstance();
        $grav = Grav::instance();
        $grav['locator'] = new I18nFakeLocator($this->tmp);
        $grav['config'] = new I18nFakeConfig([], 'quark', ['system.languages.default_lang' => 'en']);
        $grav['cache'] = new I18nFakeCache();
        $grav['setup'] = new I18nFakeSetup('localhost');
        $grav['language'] = new I18nFakeLanguage($activeLang);
        $grav['languages'] = $languages ?? new I18nFakeLanguages();

        return new TranslationOverrideStore($grav, new TranslationSourceIndex($grav));
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
