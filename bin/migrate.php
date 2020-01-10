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
use App\Reader\CredentialReader;

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

function executeCommand($arguments, $path, $callback)
{
    $io = new SymfonyStyle(new ArgvInput(), new ConsoleOutput());

    $process = new Process($arguments, $path);
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
        $callback($output);
    }
}

executeCommand(
    ['bin/console', sprintf('--env=%s', $_SERVER['APP_ENV']), 'pimee:migrate-pam-assets:export-assets', '/tmp'],
    $eePath,
    function ($output) { }
);

const CLIENT_LABEL = 'supertoolmigrateasset';

$credentials = CredentialReader::read();
if (null === $credentials) {
    executeCommand(
        ['bin/console', sprintf('--env=%s', $_SERVER['APP_ENV']), 'pim:oauth-server:create-client', CLIENT_LABEL],
        $eePath,
        function ($output) {
            $outputLines = preg_split("/\n/", $output);
            $credentials['clientId'] = preg_split('/: /', $outputLines[1])[1]; // client_id: 6_myx2xemkac0ckgw00cwk08wwg0s040oowk0ck4k48o4goc0wo
            $credentials['secret'] = preg_split('/: /', $outputLines[2])[1]; // secret: 4q7dghrwghkwk00k0kc00g4c8g4c8c8owogwco0k04k4cso8wk
            $credentials['username'] = 'admin'; // TODO
            $credentials['password'] = 'admin'; // TODO

            CredentialReader::write(
                $credentials['clientId'],
                $credentials['secret'],
                $credentials['username'] ,
                $credentials['password']
            );
        }
    );

    $credentials = CredentialReader::read();
} else {
    $io->success(sprintf('Credentials already existing in file "%s".', CredentialReader::FILENAME));
}


executeCommand(
    ['bin/console', 'app:migrate', $assetFamilyCode],
    null,
    function ($output) { }
);

executeCommand(
    ['bin/console', sprintf('--env=%s', $_SERVER['APP_ENV']), 'pimee:assets:migrate:migrate-pam-attributes', $assetFamilyCode],
    $eePath,
    function ($output) { }
);

$io->success('Asset were fully migrated!');
$io->warning(sprintf('Don\'t forget to remove the API credential file located in "%s". It contains sensitive data to connect to your PIM instance.', CredentialReader::FILENAME));
