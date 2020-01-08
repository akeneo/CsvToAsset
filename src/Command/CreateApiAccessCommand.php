<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Command to create an API access for the migration tool.
 * This command also updates the .env file with the credentials.
 *
 * @author    Adrien Pétremann <adrien.petremann@akeneo.com>
 * @copyright 2020 Akeneo SAS (https://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class CreateApiAccessCommand extends Command
{
    protected static $defaultName = 'app:create-api-access';

    private const PHP='docker-compose run -u www-data --rm php php';
    private const API_ACCESS_LABEL='pam-to-asset-manager';

    /** @var string */
    private $PATH_TO_PIM;

    /** @var SymfonyStyle */
    private $io;

    /** @var ParameterBagInterface */
    private $params;

    public function __construct(ParameterBagInterface $params)
    {
        parent::__construct(static::$defaultName);
        $this->params = $params;
    }

    protected function configure()
    {
        $this
            ->setDescription('Create an API access for the migration tool')
            ->addArgument('filePath', InputArgument::REQUIRED, 'The filePath to the PIM.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);

        $this->PATH_TO_PIM = $input->getArgument('filePath');

        $apiCredentials = $this->getApiCredentialsFromPim();
        if ($apiCredentials === null) {
            $this->createApiCredentials();
            $apiCredentials = $this->getApiCredentialsFromPim();
        }

        if ($apiCredentials === null) {
            throw new \Exception("Can't fetch API credentials");
        }

        $this->saveApiCredentialsToEnvFile($apiCredentials);
    }

    /**
     * Get API credentials with label "pam-to-asset-manager" from the targeted PIM.
     * Return null if no credentials exist.
     */
    private function getApiCredentialsFromPim(): ?array
    {
        $this->io->text('Retrieving API credentials...');

        $listCommand = sprintf(
            "cd %s && %s bin/console pim:oauth-server:list-clients | grep '%s'",
            $this->PATH_TO_PIM,
            self::PHP,
            self::API_ACCESS_LABEL
        );

        exec($listCommand, $credentials);

        if (empty($credentials)) {
            $this->io->text('no credentials found.');

            return null;
        }

        $credentials = $credentials[0];
        $credentials = array_values(array_filter(explode('|', $credentials)));

        $this->io->success('API credentials found!');

        return [
            'client_id' => trim($credentials[0]),
            'secret' => trim($credentials[1]),
        ];
    }

    /**
     * Create API credentials with label "pam-to-asset-manager" on the targeted PIM.
     */
    private function createApiCredentials(): void
    {
        $this->io->text('Creating new API credentials...');

        $creationCommand = sprintf(
            'cd %s && %s bin/console pim:oauth-server:create-client %s --grant_type="password" --grant_type="refresh_token" --env=prod',
            $this->PATH_TO_PIM,
            self::PHP,
            self::API_ACCESS_LABEL
        );

        exec($creationCommand);

        $this->io->success('API client created!');
    }

    /**
     * Save given API credentials to the .env file of the migration tool.
     */
    private function saveApiCredentialsToEnvFile(array $apiCredentials): void
    {
        $this->io->text('Saving API credentials to .env file...');

        $dotEnvFileContent = file_get_contents(
            sprintf('%s/.env', $this->params->get('kernel.project_dir'))
        );

        $dotEnvFileContentAsArray = explode("\n", $dotEnvFileContent);

        foreach ($dotEnvFileContentAsArray as $k => $line) {
            if (strpos($line, 'AKENEO_API_CLIENT_ID') !== false) {
                $dotEnvFileContentAsArray[$k] = sprintf('AKENEO_API_CLIENT_ID=%s', $apiCredentials['client_id']);
            }

            if (strpos($line, 'AKENEO_API_CLIENT_SECRET') !== false) {
                $dotEnvFileContentAsArray[$k] = sprintf('AKENEO_API_CLIENT_SECRET=%s', $apiCredentials['secret']);
            }
        }

        $newDotEnvFileContent = implode("\n", $dotEnvFileContentAsArray);

        file_put_contents(
            sprintf('%s/../.env', __DIR__),
            $newDotEnvFileContent
        );

        $this->io->success('.env file updated!');
    }
}
