<?php

declare(strict_types=1);

/*
 * This file is part of the Akeneo PIM Enterprise Edition.
 *
 * (c) 2020 Akeneo SAS (http://www.akeneo.com)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

class ArgumentChecker
{
    /**
     * This function checks if a value is allowed, and throw an exception if not.
     *
     * @param string $value
     * @param string $argumentName
     * @param array $allowedValues
     */
    public static function check(string $value, string $argumentName, array $allowedValues)
    {
        if (!in_array($value, $allowedValues)) {
            $firstFields = join(', ', array_map(function (string $allowedValue) {
                return sprintf('"%s"', $allowedValue);
            }, array_slice($allowedValues, 0, count($allowedValues)-1)));
            throw new \InvalidArgumentException(sprintf(
                'Argument "%s" should be %s or "%s", "%s" given.',
                $argumentName,
                $firstFields,
                end($allowedValues),
                $value
            ));
        }
    }
}
