<?php

namespace App\Reader;

class CredentialReader
{
    const FILENAME = 'credentials';

    static function read(): ?array
    {
        if (!file_exists(self::FILENAME)) {
            return null;
        }

        $handle = fopen(self::FILENAME, 'r');
        $credentials = [];
        $keys = ['clientId', 'secret', 'username', 'password'];
        $i = 0;
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $credentials[$keys[$i]] = trim($line);
                $i++;
            }

            fclose($handle);

            return $credentials;
        } else {
            return null;
        }
    }

    public static function write($clientId, $secret, $username, $password)
    {
        $file = fopen(self::FILENAME, 'w');
        fwrite($file, $clientId. PHP_EOL);
        fwrite($file, $secret . PHP_EOL);
        fwrite($file, $username . PHP_EOL);
        fwrite($file, $password . PHP_EOL);
    }
}
