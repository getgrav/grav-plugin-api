<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserCollectionInterface;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Api\Auth\ApiKeyManager;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
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
            $keyId = self::askForKey($helper, $this->input, $this->output, $keys);
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

    /**
     * Let the user pick a key from a list and return its ID.
     *
     * The choices are keyed by key ID, so Symfony hands back the ID rather than
     * the label. It hands back the label instead when every ID is numeric (PHP
     * turns those keys into integers, which makes the list non-associative), so
     * both are accepted.
     *
     * @param array<int, array<string, mixed>> $keys
     */
    public static function askForKey(QuestionHelper $helper, InputInterface $input, OutputInterface $output, array $keys): ?string
    {
        $choices = [];
        foreach ($keys as $key) {
            $choices[$key['id']] = sprintf('%s (%s) - %s', $key['name'], $key['prefix'], $key['id']);
        }

        $question = new ChoiceQuestion('Select the key to revoke:', $choices);
        $selected = (string) $helper->ask($input, $output, $question);

        foreach ($choices as $id => $label) {
            if ($selected === (string) $id || $selected === $label) {
                return (string) $id;
            }
        }

        return null;
    }
}
