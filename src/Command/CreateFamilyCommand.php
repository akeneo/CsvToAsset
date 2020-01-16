<?php

declare(strict_types=1);

namespace App\Command;

use Akeneo\PimEnterprise\ApiClient\AkeneoPimEnterpriseClientBuilder;
use Akeneo\PimEnterprise\ApiClient\AkeneoPimEnterpriseClientInterface;
use App\Reader\CredentialReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CreateFamilyCommand extends Command
{
    protected static $defaultName = 'app:create-family';

    /** @var AkeneoPimEnterpriseClientBuilder */
    private $clientBuilder;

    /** @var string */
    private $assetFamilyCode;

    /** @var SymfonyStyle */
    private $io;

    /** @var AkeneoPimEnterpriseClientInterface */
    private $client;

    public function __construct(AkeneoPimEnterpriseClientBuilder $clientBuilder)
    {
        parent::__construct($this::$defaultName);

        $this->clientBuilder = $clientBuilder;
    }

    protected function configure()
    {
        $this
            ->setDescription('Create an Asset Family')
            ->addArgument('assetFamilyCode', InputArgument::REQUIRED, 'The asset family code to create')
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->assetFamilyCode = $input->getArgument('assetFamilyCode');

        $credentials = CredentialReader::read();
        $this->client = $this->clientBuilder->buildAuthenticatedByPassword(
            $credentials['clientId'],
            $credentials['secret'],
            $credentials['username'],
            $credentials['password']
        );

        $this->io->writeln(sprintf('Creation of asset family "%s"...', $this->assetFamilyCode));
        $this->client->getAssetFamilyApi()->upsert($this->assetFamilyCode, [
            'code' => $this->assetFamilyCode,
            'labels' => ['en_US' => $this->assetFamilyCode], // TODO AST-239 Need to set at least 1 label, else the UI fail :/
        ]);

        $this->createAttribute('reference', 'media_file', false, true, false);
        $this->createAttribute('reference_localizable', 'media_file', true, true, false);
        $this->createAttribute('variation_scopable', 'media_file', false, true, false);
        $this->createAttribute('variation_localizable_scopable', 'media_file', true, true, false);
        $this->createAttribute('description', 'text', false, false, false);
        $this->createAttribute('categories', 'text', false, false, false);
        $this->createAttribute('tags', 'text', false, false, false);
        $this->createAttribute('end_of_use', 'text', false, false, false);

        $this->io->writeln('Update attribute as main media...');
        $this->client->getAssetFamilyApi()->upsert($this->assetFamilyCode, [
            'code' => $this->assetFamilyCode,
            'attribute_as_main_media' => 'reference',
        ]);

        $this->io->success(sprintf('Family "%s" created', $this->assetFamilyCode));
    }

    private function createAttribute(string $attributeCode, string $type, bool $localizable, bool $scopable, bool $required)
    {
        $this->io->writeln(sprintf('Creation of attribute "%s"...', $attributeCode));

        $data = [
            'code' => $attributeCode,
            'type' => $type,
            'value_per_locale' => $localizable,
            'value_per_channel' => $scopable,
            'is_required_for_completeness' => $required,
        ];
        if ($type === 'media_file') {
            $data['media_type'] = 'image';
        }

        $this->client->getAssetAttributeApi()->upsert($this->assetFamilyCode, $attributeCode, $data);
    }
}
