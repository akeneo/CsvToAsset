<?php

declare(strict_types=1);

namespace App\Command;

use Akeneo\PimEnterprise\ApiClient\AkeneoPimEnterpriseClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class UpdateFormerAttributesCommand extends Command
{
    protected static $defaultName = 'app:update-former-attributes';

    /** @var AkeneoPimEnterpriseClientInterface */
    private $client;

    /** @var string */
    private $assetFamilyCode;

    /** @var SymfonyStyle */
    private $io;

    public function __construct(AkeneoPimEnterpriseClientInterface $client)
    {
        parent::__construct($this::$defaultName);

        $this->client = $client;
    }

    protected function configure()
    {
        $this
            ->setDescription('Update the former PIM attributes from PAM to Assets')
            ->addArgument('assetFamilyCode', InputArgument::REQUIRED, 'The asset family code to link to')
            ->addOption('attributeCodes', null, InputOption::VALUE_OPTIONAL, 'List of attribute codes to update the attributes')
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $attributeCodes = $input->getOption('attributeCodes');
        if (null !== $attributeCodes) {
            $attributeCodes = preg_split('/ *, */', $attributeCodes);
        }

        $this->io = new SymfonyStyle($input, $output);
        $this->assetFamilyCode = $input->getArgument('assetFamilyCode');

        $count = 0;
        $attributes = $this->client->getAttributeApi()->all();
        foreach ($attributes as $attribute) {
            if (($attributeCodes === null || in_array($attribute['code'], $attributeCodes)) &&
//                $attribute['type'] === 'pim_assets_collection') {
                true) {
                $this->updateAttribute($attribute['code']);
                $count++;
            }
        }

        $this->io->success(sprintf('Success! %d former attributes updated', $count));
    }

    private function updateAttribute(string $attributeCode)
    {
        $this->io->writeln(sprintf('Update attribute "%s"', $attributeCode));
        $query = sprintf(
            "
UPDATE pim_catalog_attribute 
SET attribute_type='pim_catalog_asset_collection', properties='%s'
WHERE code='%s'
LIMIT 1;",
            serialize(['reference_data_name' => $this->assetFamilyCode]),
            $attributeCode
        );
        var_dump($query); // TODO
    }
}
