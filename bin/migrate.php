#!/usr/bin/env php
<?php

/**
*For PAAS & on-premise, there is a php file:*

- in the PIM : Run 'export-pam-assets' and put them int tmp folder
- in the PIM : Run 'create-api-credentials' and store them
- Use the CSVToAsset tool (and the API credentials + CSV files) to run "make migration" and a default asset-family-code
    - In the CSVToAsset: run create asset family
    - In the CSVToAsset: run merge 2 CSV files in 1
    - In the CSVToAsset: run import assets into the PIM through API
- In the PIM : run 'migrate-pam-attributes' command
*/

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Output\ConsoleOutput;

set_time_limit(0);

require dirname(__DIR__).'/vendor/autoload.php';
$io = new SymfonyStyle(new ArgvInput(), new ConsoleOutput());

$inputDefinition = new InputDefinition([
    new InputArgument('asset-family-code', InputArgument::REQUIRED),
    new InputArgument('ee-path', InputArgument::REQUIRED)
]);

try {
    $input = new ArgvInput(null, $inputDefinition);
    $input->validate();
} catch (\Exception $e) {
    $io->error(sprintf("Input format error: please use %s %s", 'migrate.php', $inputDefinition->getSynopsis(true)));

    throw $e;
}

$assetFamilyCode = $input->getArgument('asset-family-code');
$eePath = $input->getArgument('ee-path');

require dirname(__DIR__).'/config/bootstrap.php';


// Run Export assets
$process = new Process(
    ['bin/console', sprintf('--env=%s', $_SERVER['APP_ENV']), 'pimee:migrate-pam-assets:export-assets', '/tmp'],
    $eePath,
);
$process->run();

if ($process->getExitCode() > 0) {
    $io->error('An error occured during migration');
    if ($process->getErrorOutput() !== '') {
        $io->error($process->getErrorOutput());
    }
    $io->warning($process->getOutput());

    die($process->getExitCode());
} else {
    $io->write($process->getOutput());

    $io->success('Assets exported');
}

const CLIENT_LABEL = 'supertoolmigrateasset';

// Run client create
$process = new Process(
    ['bin/console', sprintf('--env=%s', $_SERVER['APP_ENV']), 'pim:oauth-server:create-client', CLIENT_LABEL],
    $eePath,
);
$process->run();

if ($process->getExitCode() > 0) {
    $io->error('An error occured during migration');
    if ($process->getErrorOutput() !== '') {
        $io->error($process->getErrorOutput());
    }
    $io->warning($process->getOutput());

    die($process->getExitCode());
} else {
    $output = $process->getOutput();
    $io->write($output);
    $outputLines = preg_split("/\n/", $output);
    $clientId = preg_split('/: /', $outputLines[1])[1]; // client_id: 6_myx2xemkac0ckgw00cwk08wwg0s040oowk0ck4k48o4goc0wo
    $secret = preg_split('/: /', $outputLines[2])[1]; // secret: 4q7dghrwghkwk00k0kc00g4c8g4c8c8owogwco0k04k4cso8wk
    $username = 'admin'; // TODO
    $password = 'admin'; // TODO

    $io->success(sprintf('Client created (%s, %s, %s, %s)', $clientId, $secret, $username, $password));
}

// Run migration
$process = new Process(
    ['bin/console', 'app:migrate', $assetFamilyCode]
);

$process->run();
if ($process->getExitCode() > 0) {
    $io->error('An error occured during migration');
    if ($process->getErrorOutput() !== '') {
        $io->error($process->getErrorOutput());
    }
    $io->warning($process->getOutput());

    die($process->getExitCode());
} else {
    $output = $process->getOutput();
    $io->write($output);

    $io->success('Asset migrated');
}



// pimee_product_asset_asset pimee_product_asset_asset_category pimee_product_asset_asset_tag pimee_product_asset_category pimee_product_asset_category_translation pimee_product_asset_channel_variation_configuration pimee_product_asset_file_metadata pimee_product_asset_reference pimee_product_asset_tag pimee_product_asset_variation
