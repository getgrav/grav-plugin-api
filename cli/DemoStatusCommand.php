<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Api\Demo\DemoManager;
use Grav\Plugin\Api\Demo\DemoPolicy;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Show what demo mode allows, when the next reset is due, and what has changed
 * since the baseline.
 * Invoked as: bin/plugin api demo:status
 */
class DemoStatusCommand extends ConsoleCommand
{
    /** Files listed per heading before the rest are only counted. */
    private const LIST_LIMIT = 20;

    protected function configure(): void
    {
        $this
            ->setName('demo:status')
            ->setAliases(['demo-status'])
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'List every changed file, not just the first ' . self::LIST_LIMIT . ' under each heading')
            ->setDescription('Show demo-mode grants, reset timing, and what has changed since the baseline')
            ->setHelp('The <info>demo:status</info> command shows what demo accounts may change, which folders a reset restores, when the last and next reset are, and the files added, changed or removed since <info>demo:baseline</info>.');
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $io = new SymfonyStyle($this->input, $this->output);
        $this->initializePlugins();

        $grav = Grav::instance();
        $config = $grav['config'];
        $manager = new DemoManager($grav, $config);

        // The fine grants only: each shorthand is listed as what it stands for.
        $grants = array_values(array_filter(
            DemoPolicy::expand((array) $config->get('plugins.api.demo.writable', [])),
            static fn (string $grant): bool => !in_array($grant, ['api.pages.write', 'api.media.write', 'api.config.write'], true)
        ));
        $allowRoutes = array_values(array_filter(array_map('strval', (array) $config->get('plugins.api.demo.allow_routes', []))));
        $roots = $manager->writableRoots();

        $io->title('Demo mode');
        $io->definitionList(
            ['Demo accounts may change' => $grants === [] ? 'nothing (read-only)' : implode(', ', $grants)],
            ['Extra routes allowed' => $allowRoutes === [] ? 'none' : implode(', ', $allowRoutes)],
            ['Raw HTML in demo edits' => $config->get('plugins.api.demo.escape_html', true) ? 'shown as text' : 'rendered'],
            ['A reset restores' => $roots === [] ? 'nothing' : implode(', ', array_keys($roots))],
            ['Reset every' => $manager->resetIntervalMinutes() . ' minutes'],
        );

        if (!$manager->baselineExists()) {
            $io->note($roots === []
                ? 'No baseline, and nothing to reset: this demo is read-only.'
                : 'No baseline yet. Run "bin/plugin api demo:baseline" to save the current state; resets start after that.');
            return 0;
        }

        $at = static fn (?int $time): string => $time === null ? 'never' : date('Y-m-d H:i:s', $time);
        $next = $manager->secondsUntilReset();
        $io->definitionList(
            ['Baseline saved' => $at($manager->baselineCapturedAt())],
            ['Last reset' => $at($manager->lastResetAt())],
            ['Next reset' => $next === null ? 'not scheduled' : ($next === 0 ? 'due now' : 'in ' . self::duration($next))],
        );

        $changes = $manager->changes() ?? ['added' => [], 'changed' => [], 'removed' => []];
        $total = count($changes['added']) + count($changes['changed']) + count($changes['removed']);
        if ($total === 0) {
            $io->success('Nothing has changed since the baseline.');
            return 0;
        }

        $all = (bool) $this->input->getOption('all');
        foreach (['changed' => 'Changed', 'added' => 'Added', 'removed' => 'Removed'] as $key => $heading) {
            $files = $changes[$key];
            if ($files === []) {
                continue;
            }
            $io->section($heading . ' (' . count($files) . ')');
            $shown = $all ? $files : array_slice($files, 0, self::LIST_LIMIT);
            $io->listing($shown);
            if (count($files) > count($shown)) {
                $io->writeln('  …and ' . (count($files) - count($shown)) . ' more. Use --all to list them.');
            }
        }

        return 0;
    }

    private static function duration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return $minutes > 0 ? sprintf('%d min %02d s', $minutes, $rest) : $rest . ' s';
    }
}
