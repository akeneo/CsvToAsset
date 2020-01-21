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
    private const VARIATION_SCOPABLE = 'variation_scopable';
    private const VARIATION_LOCALIZABLE_SCOPABLE = 'variation_localizable_scopable';
    private const CATEGORIES = 'categories';
    private const TAGS = 'tags';

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
            ->addOption('with-variations', null, InputOption::VALUE_OPTIONAL,
                sprintf('Create variation field(s) or not. Allowed values: %s|%s',
                    self::YES,
                    self::NO
                ),
                self::YES
            )
            ->addOption('category-options', null, InputOption::VALUE_OPTIONAL,
                sprintf('Create %s field as a "multiple_options" attribute with these options (comma-separated) instead of text attributes', self::CATEGORIES),
            )
            ->addOption('tag-options', null, InputOption::VALUE_OPTIONAL,
                sprintf('Create %s field as a "multiple_options" attribute with these options (comma-separated)', self::TAGS),
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

        $withVariations = $input->getOption('with-variations');
        ArgumentChecker::assertOptionIsAllowed($withVariations, 'with-variations', [self::YES, self::NO]);

        $categoryOptions = $input->getOption('category-options');
        if (!empty($categoryOptions)) {
            $categoryOptions = array_filter(explode(',', $categoryOptions), function (string $categoryOption) {
                return !empty($categoryOption);
            });
        } else {
            $categoryOptions = null;
        }

        $tagOptions = $input->getOption('tag-options');
        if (!empty($tagOptions)) {
            $tagOptions = array_filter(explode(',', $tagOptions), function (string $tagOption) {
                return !empty($tagOption);
            });
        } else {
            $tagOptions = null;
        }

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
            'labels' => new \stdClass(), // TODO AST-239 Need to set at least 1 label, else the UI fail :/
        ]);

        if ($referenceType === self::NON_LOCALIZABLE) {
            $this->createNonLocalizableAttributes($withVariations);
        };

        if ($referenceType === self::LOCALIZABLE) {
            $this->createLocalizableAttributes($withVariations);
        }

        if ($referenceType === self::BOTH) {
            $this->createNonLocalizableAttributes($withVariations);
            $this->createLocalizableAttributes($withVariations);
        }

        $this->createAttribute('description', 'text', false, false, false);

        if ($withCategories === self::YES) {
            if ($categoryOptions === null) {
                $this->createAttribute(self::CATEGORIES, 'text', false, false, false);
            } else {
                $this->createAttribute(self::CATEGORIES, 'multiple_options', false, false, false);
                $this->createCategoryOptions($categoryOptions);
            }
        } else {
            $this->io->writeln(sprintf('Skip creation of attribute "%s"...', self::CATEGORIES));
        }

        if ($tagOptions === null) {
            $this->createAttribute(self::TAGS, 'text', false, false, false);
        } else {
            $this->createAttribute(self::TAGS, 'multiple_options', false, false, false);
            $this->createTagOptions($tagOptions);
        }
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
        $this->io->writeln(sprintf('Creation of %s attribute "%s"...', $type, $attributeCode));

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

    private function createNonLocalizableAttributes(string $withVariations): void
    {
        $this->createAttribute(self::ATTRIBUTE_REFERENCE, 'media_file', false, true, false);
        if ($withVariations === self::YES) {
            $this->createAttribute(self::VARIATION_SCOPABLE, 'media_file', false, true, false);
        } else {
            $this->io->writeln(sprintf('Skip creation of attribute "%s"...', self::VARIATION_SCOPABLE));
        }
    }

    private function createLocalizableAttributes(string $withVariations): void
    {
        $this->createAttribute(self::ATTRIBUTE_REFERENCE_LOCALIZABLE, 'media_file', true, true, false);
        if ($withVariations === self::YES) {
            $this->createAttribute(self::VARIATION_LOCALIZABLE_SCOPABLE, 'media_file', true, true, false);
        } else {
            $this->io->writeln(sprintf('Skip creation of attribute "%s"...', self::VARIATION_LOCALIZABLE_SCOPABLE));
        }
    }

    private function createCategoryOptions(array $categoryOptions)
    {
        foreach ($categoryOptions as $categoryOption) {
            if (!empty($categoryOption)) {
                $this->io->writeln(sprintf('Creation of attribute option "%s"...', $categoryOption));
                $this->client->getAssetAttributeOptionApi()->upsert($this->assetFamilyCode, self::CATEGORIES, $categoryOption, [
                    'code' => $categoryOption
                ]);
            }
        }
    }

    private function createTagOptions(array $tagOptions)
    {
        foreach ($tagOptions as $tagOption) {
            $this->io->writeln(sprintf('Creation of tag option "%s"...', $tagOption));
            $this->client->getAssetAttributeOptionApi()->upsert($this->assetFamilyCode, self::TAGS, $tagOption, [
                'code' => $tagOption
            ]);
        }
    }
}
