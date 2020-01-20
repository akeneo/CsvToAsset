<?php

declare(strict_types=1);

namespace App\Command;

use Akeneo\PimEnterprise\ApiClient\AkeneoPimEnterpriseClientBuilder;
use Akeneo\PimEnterprise\ApiClient\AkeneoPimEnterpriseClientInterface;
use App\Reader\CredentialReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CreateFamilyCommand extends Command
{
    private const LOCALIZABLE = 'localizable';
    private const NON_LOCALIZABLE = 'non-localizable';
    private const BOTH = 'both';

    private const ATTRIBUTE_REFERENCE = 'reference';
    private const ATTRIBUTE_REFERENCE_LOCALIZABLE = 'reference_localizable';
    private const CATEGORIES = 'categories';

    private const YES = 'yes';
    private const NO = 'no';

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
            ->addArgument('asset-family-code', InputArgument::REQUIRED, 'The asset family code to create')
            ->addOption('reference-type', null, InputOption::VALUE_OPTIONAL,
                sprintf(
                    'Enable if image reference is localizable or not. Allowed values: %s|%s|%s',
                    self::LOCALIZABLE,
                    self::NON_LOCALIZABLE,
                    self::BOTH
                ),
                self::BOTH
            )
            ->addOption('with-categories', null, InputOption::VALUE_OPTIONAL,
                sprintf('Create %s field or not. Allowed values: %s|%s',
                    self::CATEGORIES,
                    self::YES,
                    self::NO
                ),
                self::YES
            )
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->assetFamilyCode = $input->getArgument('asset-family-code');

        $referenceType = $input->getOption('reference-type');
        ArgumentChecker::assertOptionIsAllowed($referenceType, 'reference-type', [self::LOCALIZABLE, self::NON_LOCALIZABLE, self::BOTH]);

        $withCategories = $input->getOption('with-categories');
        ArgumentChecker::assertOptionIsAllowed($withCategories, 'with-categories', [self::YES, self::NO]);

        $credentials = CredentialReader::read();
        $this->client = $this->clientBuilder->buildAuthenticatedByPassword(
            $credentials['clientId'],
            $credentials['secret'],
            $credentials['username'],
            $credentials['password']
        );

        $this->io->title(sprintf('Creation of asset family "%s"...', $this->assetFamilyCode));

        $this->client->getAssetFamilyApi()->upsert($this->assetFamilyCode, [
            'code' => $this->assetFamilyCode,
            'labels' => ['en_US' => $this->assetFamilyCode], // TODO AST-239 Need to set at least 1 label, else the UI fail :/
        ]);

        if ($referenceType === self::NON_LOCALIZABLE) {
            $this->createNonLocalizableAttributes();
        };

        if ($referenceType === self::LOCALIZABLE) {
            $this->createLocalizableAttributes();
        }

        if ($referenceType === self::BOTH) {
            $this->createNonLocalizableAttributes();
            $this->createLocalizableAttributes();
        }

        $this->createAttribute('description', 'text', false, false, false);

        if ($withCategories === self::YES) {
            $this->createAttribute(self::CATEGORIES, 'text', false, false, false);
        } else {
            $this->io->writeln(sprintf('Skip creation of attribute "%s"...', self::CATEGORIES));
        }

        $this->createAttribute('tags', 'text', false, false, false);
        $this->createAttribute('end_of_use', 'text', false, false, false);

        $attributeAsMainMedia = $referenceType === self::NON_LOCALIZABLE || $referenceType === self::BOTH ?
            self::ATTRIBUTE_REFERENCE :
            self::ATTRIBUTE_REFERENCE_LOCALIZABLE;
        $this->io->writeln(sprintf('Update "%s" attribute as main media...', $attributeAsMainMedia));

        $this->client->getAssetFamilyApi()->upsert($this->assetFamilyCode, [
            'code' => $this->assetFamilyCode,
            'attribute_as_main_media' => $attributeAsMainMedia,
        ]);

        $this->io->success(sprintf('Family "%s" created!', $this->assetFamilyCode));
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

    private function createNonLocalizableAttributes()
    {
        $this->createAttribute(self::ATTRIBUTE_REFERENCE, 'media_file', false, true, false);
        $this->createAttribute('variation_scopable', 'media_file', false, true, false);
    }

    private function createLocalizableAttributes()
    {
        $this->createAttribute(self::ATTRIBUTE_REFERENCE_LOCALIZABLE, 'media_file', true, true, false);
        $this->createAttribute('variation_localizable_scopable', 'media_file', true, true, false);
    }
}
