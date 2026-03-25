<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserCollectionInterface;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Api\Auth\ApiKeyManager;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

class KeysListCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('keys:list')
            ->setAliases(['keys:ls'])
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'The username to list keys for')
            ->setDescription('List API keys for a user')
            ->setHelp('The <info>keys:list</info> command shows all API keys for the specified user.');
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $io = new SymfonyStyle($this->input, $this->output);
        $grav = Grav::instance();

        $this->initializePlugins();

        /** @var UserCollectionInterface $accounts */
        $accounts = $grav['accounts'];

        // Get username
        $username = $this->input->getOption('user');
        if (!$username) {
            $helper = $this->getHelper('question');
            $question = new Question('Enter the <yellow>username</yellow>: ');
            $username = $helper->ask($this->input, $this->output, $question);
        }

        $user = $accounts->load($username);
        if (!$user->exists()) {
            $io->error("User '{$username}' does not exist.");
            return 1;
        }

        $manager = new ApiKeyManager();
        $keys = $manager->listKeys($user);

        if (empty($keys)) {
            $io->writeln("No API keys found for user '<cyan>{$username}</cyan>'.");
            return 0;
        }

        $io->writeln("API keys for user '<cyan>{$username}</cyan>':");
        $io->newLine();

        $rows = [];
        foreach ($keys as $key) {
            $expires = 'Never';
            if ($key['expires']) {
                $expires = $key['expires'] < time()
                    ? '<red>Expired ' . date('Y-m-d', $key['expires']) . '</red>'
                    : date('Y-m-d', $key['expires']);
            }

            $rows[] = [
                $key['id'],
                $key['name'],
                $key['prefix'],
                $key['active'] ? '<green>Active</green>' : '<red>Inactive</red>',
                $expires,
                $key['created'] ? date('Y-m-d H:i', $key['created']) : 'N/A',
                $key['last_used'] ? date('Y-m-d H:i', $key['last_used']) : 'Never',
            ];
        }

        $io->table(
            ['ID', 'Name', 'Prefix', 'Status', 'Expires', 'Created', 'Last Used'],
            $rows
        );

        return 0;
    }
}
