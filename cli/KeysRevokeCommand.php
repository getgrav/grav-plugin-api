<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserCollectionInterface;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Api\Auth\ApiKeyManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

class KeysRevokeCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('keys:revoke')
            ->setAliases(['keys:remove', 'keys:delete', 'keys:rm'])
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'The username')
            ->addArgument('key-id', InputArgument::OPTIONAL, 'The key ID to revoke')
            ->setDescription('Revoke an API key')
            ->setHelp('The <info>keys:revoke</info> command revokes an API key for the specified user. If no key ID is given, you can select from a list.');
    }

    protected function serve(): int
    {
        include __DIR__ . '/../vendor/autoload.php';

        $io = new SymfonyStyle($this->input, $this->output);
        $grav = Grav::instance();

        $this->initializePlugins();

        /** @var UserCollectionInterface $accounts */
        $accounts = $grav['accounts'];
        $helper = $this->getHelper('question');

        // Get username
        $username = $this->input->getOption('user');
        if (!$username) {
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

        // Get key ID
        $keyId = $this->input->getArgument('key-id');
        if (!$keyId) {
            // Let user pick from a list
            $choices = [];
            foreach ($keys as $key) {
                $choices[$key['id']] = sprintf('%s (%s) - %s', $key['name'], $key['prefix'], $key['id']);
            }

            $question = new ChoiceQuestion(
                'Select the key to revoke:',
                $choices
            );
            $selected = $helper->ask($this->input, $this->output, $question);

            // Extract the key ID from the selected choice
            foreach ($keys as $key) {
                $label = sprintf('%s (%s) - %s', $key['name'], $key['prefix'], $key['id']);
                if ($label === $selected) {
                    $keyId = $key['id'];
                    break;
                }
            }
        }

        if (!$keyId) {
            $io->error('No key ID provided.');
            return 1;
        }

        // Find key name for confirmation
        $keyName = $keyId;
        foreach ($keys as $key) {
            if ($key['id'] === $keyId) {
                $keyName = sprintf('%s (%s)', $key['name'], $key['prefix']);
                break;
            }
        }

        $confirm = new ConfirmationQuestion(
            "Revoke key <yellow>{$keyName}</yellow> for user <cyan>{$username}</cyan>? [y/N] ",
            false
        );

        if (!$helper->ask($this->input, $this->output, $confirm)) {
            $io->writeln('Cancelled.');
            return 0;
        }

        $revoked = $manager->revokeKey($user, $keyId);

        if ($revoked) {
            $io->success("API key '{$keyId}' revoked for user '{$username}'.");
        } else {
            $io->error("API key '{$keyId}' not found for user '{$username}'.");
            return 1;
        }

        return 0;
    }
}
