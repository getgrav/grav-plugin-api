<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Api\Services\TranslationOverrideStore;
use Grav\Plugin\Api\Services\TranslationSourceIndex;
use Grav\Plugin\Api\Services\TranslationStringsImporter;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import translation overrides from the translation-strings plugin into the
 * built-in editor's store.
 *
 * Invoked as: bin/plugin api i18n:migrate [--dry-run]
 *
 * The work itself lives in {@see TranslationStringsImporter} so this command
 * and the Translations screen in Admin 2.1 cannot drift apart — see that class
 * for why the import must happen before the plugin is disabled, and never the
 * other way round.
 */
class I18nMigrateCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('i18n:migrate')
            ->setDescription('Import overrides from the translation-strings plugin into user/languages')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be written without touching anything')
            ->setHelp('The <info>i18n:migrate</info> command copies translation-strings overrides into the language files used by the Translations editor in Admin 2.1. The plugin config is left untouched so the move can be undone.');
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $io = new SymfonyStyle($this->input, $this->output);
        $this->initializePlugins();

        $grav = Grav::instance();
        $dryRun = (bool) $this->input->getOption('dry-run');

        $sources = new TranslationSourceIndex($grav);
        $store = new TranslationOverrideStore($grav, $sources);
        $importer = new TranslationStringsImporter($grav, $sources, $store);

        $report = $importer->report();

        if (!$report['present']) {
            $io->success('Nothing to migrate — the translation-strings plugin has no overrides configured.');

            return 0;
        }

        foreach ($report['languages'] as $language) {
            $io->section("{$language['code']} — {$language['total']} override(s)");

            if ($language['already'] > 0) {
                $io->writeln("<info>{$language['already']}</info> already present in user/languages");
            }
            if ($language['shipped'] > 0) {
                $io->writeln("<comment>{$language['shipped']} match what the source already ships and will be dropped</comment>");
            }
            if ($language['conflict'] > 0) {
                $io->writeln("<comment>{$language['conflict']} disagree with user/languages; the plugin's value wins:</comment>");
                foreach ($this->pick($language['keys'], TranslationStringsImporter::CONFLICT) as $entry) {
                    $io->writeln("    {$entry['key']}");
                }
            }
            if ($language['unknown'] > 0) {
                $io->writeln("<comment>{$language['unknown']} key(s) no plugin, theme or core file provides:</comment>");
                foreach ($this->pickUnknown($language['keys']) as $entry) {
                    $io->writeln("    {$entry['key']}");
                }
            }

            if ($dryRun) {
                $io->writeln('<info>would write</info> ' . $store->path($language['code']));
            }
        }

        if ($dryRun) {
            $io->note("Dry run — nothing was written. {$report['pending']} override(s) would change. Re-run without --dry-run to apply.");

            return 0;
        }

        $result = $importer->import();

        foreach ($result['languages'] as $language) {
            $io->writeln("<info>wrote</info> {$language['written']} to {$language['path']}");
        }

        $io->success("Migrated {$result['imported']} override(s).");

        if ($result['unknown'] !== []) {
            $io->warning(
                count($result['unknown']) . ' of them name keys nothing provides. They were kept so you can '
                . 'find them under Translations with the "Unknown" filter, but they have no effect.'
            );
        }

        if ($importer->pluginEnabled()) {
            $io->writeln('');
            $io->writeln('<comment>The translation-strings plugin is still enabled, and it merges its strings after this store does, so it still wins.</comment>');
            $io->writeln('Check the site reads correctly, then disable it to finish the move. Its config is untouched, so this is reversible.');
        }

        return 0;
    }

    /**
     * @param array<int, array{key: string, status: string, unknown: bool}> $keys
     * @return array<int, array{key: string, status: string, unknown: bool}>
     */
    private function pick(array $keys, string $status, int $limit = 10): array
    {
        return array_slice(array_values(array_filter($keys, static fn (array $k): bool => $k['status'] === $status)), 0, $limit);
    }

    /**
     * @param array<int, array{key: string, status: string, unknown: bool}> $keys
     * @return array<int, array{key: string, status: string, unknown: bool}>
     */
    private function pickUnknown(array $keys, int $limit = 10): array
    {
        return array_slice(array_values(array_filter($keys, static fn (array $k): bool => $k['unknown'])), 0, $limit);
    }
}
