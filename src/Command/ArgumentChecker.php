<?php

declare(strict_types=1);

namespace App\Command;

class ArgumentChecker
{
    /**
     * This function checks if a value is allowed, and throws an exception if not.
     *
     * @param string $value
     * @param string $argumentName
     * @param array $allowedValues
     */
    public static function assertOptionIsAllowed(string $value, string $argumentName, array $allowedValues)
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
