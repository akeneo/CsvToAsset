<?php

declare(strict_types=1);

namespace App\Command;

use Akeneo\PimEnterprise\ApiClient\AkeneoPimEnterpriseClientBuilder;
use Akeneo\PimEnterprise\ApiClient\AkeneoPimEnterpriseClientInterface;
use App\FieldNameProvider;
use App\Reader\CredentialReader;
use RuntimeException;
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

    private const YES = 'yes';
    private const NO = 'no';

    protected static $defaultName = 'app:create-family';

    /** @var AkeneoPimEnterpriseClientBuilder */
    private $clientBuilder;

    /** @var string */
    private $assetFamilyCode;

    /** @var SymfonyStyle */
    private $io;

    /** @var FieldNameProvider */
    private $fieldNameProvider;

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
                    'Enable if media reference is localizable or not. Allowed values: %s|%s|%s',
                    self::LOCALIZABLE,
                    self::NON_LOCALIZABLE,
                    self::BOTH
                ),
                self::BOTH
            )
            ->addOption('with-categories', null, InputOption::VALUE_OPTIONAL,
                sprintf('Create %s field or not. Allowed values: %s|%s',
                    FieldNameProvider::CATEGORIES,
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
            ->addOption('with-end-of-use', null, InputOption::VALUE_OPTIONAL,
                sprintf('Create end_of_use field or not. Allowed values: %s|%s',
                    self::YES,
                    self::NO
                ),
            self::YES
            )
            ->addOption('category-options', null, InputOption::VALUE_OPTIONAL,
                sprintf('Create %s field as a "multiple_options" attribute with these options (comma-separated) instead of text attributes', FieldNameProvider::CATEGORIES),
            )
            ->addOption('tag-options', null, InputOption::VALUE_OPTIONAL,
                sprintf('Create %s field as a "multiple_options" attribute with these options (comma-separated)', FieldNameProvider::TAGS),
            )
            ->addOption('mapping', null, InputOption::VALUE_OPTIONAL, 'Use this file for your fields mapping', null)
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

        $withEndOfUse = $input->getOption('with-end-of-use');
        ArgumentChecker::assertOptionIsAllowed($withEndOfUse, 'with-end-of-use', [self::YES, self::NO]);

        $this->fieldNameProvider = new FieldNameProvider($input->getOption('mapping'));

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

        $attributeAsMainMedia = $referenceType === self::NON_LOCALIZABLE || $referenceType === self::BOTH ?
            $this->fieldNameProvider->get(FieldNameProvider::REFERENCE) :
            $this->fieldNameProvider->get(FieldNameProvider::VARIATION_LOCALIZABLE_SCOPABLE);

        $credentials = CredentialReader::read();
        if (null === $credentials) {
            throw new RuntimeException('No credentials found. See the README.md to create your credentials file.');
        }
        $this->client = $this->clientBuilder->buildAuthenticatedByPassword(
            $credentials['clientId'],
            $credentials['secret'],
            $credentials['username'],
            $credentials['password']
        );

        $this->io->title(sprintf('Creation of asset family "%s"...', $this->assetFamilyCode));

        $this->displayMessage($referenceType, $withVariations, $withCategories, $withEndOfUse, $categoryOptions, $tagOptions, $attributeAsMainMedia);

        $this->io->newLine();

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

        $this->createAttribute($this->fieldNameProvider->get(FieldNameProvider::DESCRIPTION), 'text', false, false, false);

        if ($withCategories === self::YES) {
            if ($categoryOptions === null) {
                $this->createAttribute($this->fieldNameProvider->get(FieldNameProvider::CATEGORIES), 'text', false, false, false);
            } else {
                $this->createAttribute($this->fieldNameProvider->get(FieldNameProvider::CATEGORIES), 'multiple_options', false, false, false);
                $this->createCategoryOptions($categoryOptions);
            }
        } else {
            $this->io->writeln(sprintf('Skip creation of attribute "%s"...', $this->fieldNameProvider->get(FieldNameProvider::CATEGORIES)));
        }

        if ($tagOptions === null) {
            $this->createAttribute($this->fieldNameProvider->get(FieldNameProvider::TAGS), 'text', false, false, false);
        } else {
            $this->createAttribute($this->fieldNameProvider->get(FieldNameProvider::TAGS), 'multiple_options', false, false, false);
            $this->createTagOptions($tagOptions);
        }

        if ($withEndOfUse === self::YES) {
            $this->createAttribute($this->fieldNameProvider->get(FieldNameProvider::END_OF_USE), 'text', false, false, false);
        } else {
            $this->io->writeln(sprintf('Skip creation of attribute "%s"...', $this->fieldNameProvider->get(FieldNameProvider::END_OF_USE)));
        }

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
        $this->createAttribute($this->fieldNameProvider->get(FieldNameProvider::REFERENCE), 'media_file', false, true, false);
        if ($withVariations === self::YES) {
            $this->createAttribute($this->fieldNameProvider->get(FieldNameProvider::VARIATION_SCOPABLE), 'media_file', false, true, false);
        } else {
            $this->io->writeln(sprintf('Skip creation of attribute "%s"...', $this->fieldNameProvider->get(FieldNameProvider::VARIATION_SCOPABLE)));
        }
    }

    private function createLocalizableAttributes(string $withVariations): void
    {
        $this->createAttribute($this->fieldNameProvider->get(FieldNameProvider::REFERENCE_LOCALIZABLE), 'media_file', true, true, false);
        if ($withVariations === self::YES) {
            $this->createAttribute($this->fieldNameProvider->get(FieldNameProvider::VARIATION_LOCALIZABLE_SCOPABLE), 'media_file', true, true, false);
        } else {
            $this->io->writeln(sprintf('Skip creation of attribute "%s"...', $this->fieldNameProvider->get(FieldNameProvider::VARIATION_LOCALIZABLE_SCOPABLE)));
        }
    }

    private function createCategoryOptions(array $categoryOptions)
    {
        foreach ($categoryOptions as $categoryOption) {
            if (!empty($categoryOption)) {
                $this->io->writeln(sprintf('Creation of attribute option "%s"...', $categoryOption));
                $this->client->getAssetAttributeOptionApi()->upsert($this->assetFamilyCode, $this->fieldNameProvider->get(FieldNameProvider::CATEGORIES), $categoryOption, [
                    'code' => $categoryOption
                ]);
            }
        }
    }

    private function createTagOptions(array $tagOptions)
    {
        foreach ($tagOptions as $tagOption) {
            $this->io->writeln(sprintf('Creation of tag option "%s"...', $tagOption));
            $this->client->getAssetAttributeOptionApi()->upsert($this->assetFamilyCode, $this->fieldNameProvider->get(FieldNameProvider::TAGS), $tagOption, [
                'code' => $tagOption
            ]);
        }
    }

    private function displayMessage(
        string $referenceType,
        string $withVariations,
        string $withCategories,
        string $withEndOfUse,
        ?array $categoryOptions,
        ?array $tagOptions,
        string $attributeAsMainMedia
    ) {
        $messages = [sprintf('This command will create an asset family "%s" with:', $this->assetFamilyCode)];
        if ($referenceType === self::NON_LOCALIZABLE) {
            $messages[] = sprintf('- An attribute <options=bold>%s</> of type <options=bold>%s</>', $this->fieldNameProvider->get(FieldNameProvider::REFERENCE), 'media_file');
            if ($withVariations === self::YES) {
                $messages[] = sprintf('- An attribute <options=bold>%s</> of type <options=bold>%s</>', $this->fieldNameProvider->get(FieldNameProvider::VARIATION_SCOPABLE), 'media_file');
            }
        }
        if ($referenceType === self::LOCALIZABLE) {
            $messages[] = sprintf('- An attribute <options=bold>%s</> of type <options=bold>%s</>', $this->fieldNameProvider->get(FieldNameProvider::REFERENCE_LOCALIZABLE), 'media_file');
            if ($withVariations === self::YES) {
                $messages[] = sprintf('- An attribute <options=bold>%s</> of type <options=bold>%s</>', $this->fieldNameProvider->get(FieldNameProvider::VARIATION_LOCALIZABLE_SCOPABLE), 'media_file');
            }
        }
        if ($referenceType === self::BOTH) {
            $messages[] = sprintf('- An attribute <options=bold>%s</> of type <options=bold>%s</>', $this->fieldNameProvider->get(FieldNameProvider::REFERENCE), 'media_file');
            if ($withVariations === self::YES) {
                $messages[] = sprintf('- An attribute <options=bold>%s</> of type <options=bold>%s</>', $this->fieldNameProvider->get(FieldNameProvider::VARIATION_SCOPABLE), 'media_file');
            }
            $messages[] = sprintf('- An attribute <options=bold>%s</> of type <options=bold>%s</>', $this->fieldNameProvider->get(FieldNameProvider::REFERENCE_LOCALIZABLE), 'media_file');
            if ($withVariations === self::YES) {
                $messages[] = sprintf('- An attribute <options=bold>%s</> of type <options=bold>%s</>', $this->fieldNameProvider->get(FieldNameProvider::VARIATION_LOCALIZABLE_SCOPABLE), 'media_file');
            }
        }
        $messages[] = sprintf('- An attribute <options=bold>%s</> of type <options=bold>%s</>', $this->fieldNameProvider->get(FieldNameProvider::DESCRIPTION), 'text');
        if ($withCategories === self::YES) {
            if ($categoryOptions === null) {
                $messages[] = sprintf('- An attribute <options=bold>%s</> of type <options=bold>%s</>', $this->fieldNameProvider->get(FieldNameProvider::CATEGORIES), 'text');
            } else {
                $messages[] = sprintf('- An attribute <options=bold>%s</> of type <options=bold>%s</> with these values:', $this->fieldNameProvider->get(FieldNameProvider::CATEGORIES), 'multiple_options');
                foreach ($categoryOptions as $categoryOption) {
                    $messages[] = sprintf('  - %s', $categoryOption);
                }
            }
        }
        if ($tagOptions === null) {
            $messages[] = sprintf('- An attribute <options=bold>%s</> of type <options=bold>%s</>', $this->fieldNameProvider->get(FieldNameProvider::TAGS), 'text');
        } else {
            $messages[] = sprintf('- An attribute <options=bold>%s</> of type <options=bold>%s</> with these values:', $this->fieldNameProvider->get(FieldNameProvider::TAGS), 'multiple_options');
            foreach ($tagOptions as $tagOption) {
                $messages[] = sprintf('  - %s', $tagOption);
            }
        }
        if ($withEndOfUse === self::YES) {
            $messages[] = sprintf('- An attribute <options=bold>%s</> of type <options=bold>%s</>', $this->fieldNameProvider->get(FieldNameProvider::END_OF_USE), 'text');
        }
        $messages[] = '';
        $messages[] = sprintf('The attribute as main media will be <options=bold>%s</>.', $attributeAsMainMedia);

        $this->io->writeln($messages);
    }
}
