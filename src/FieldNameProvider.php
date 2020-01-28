<?php

declare(strict_types=1);

namespace App;

class FieldNameProvider
{
    public const REFERENCE = 'reference';
    public const REFERENCE_LOCALIZABLE = 'reference_localizable';
    public const VARIATION_SCOPABLE = 'variation_scopable';
    public const VARIATION_LOCALIZABLE_SCOPABLE = 'variation_localizable_scopable';
    public const CATEGORIES = 'categories';
    public const TAGS = 'tags';
    public const DESCRIPTION = 'description';
    public const END_OF_USE = 'end_of_use';

    /** @var array */
    private $mapping;

    public function __construct(?string $mappingPath)
    {
        $this->mapping = [
            self::REFERENCE => 'reference',
            self::REFERENCE_LOCALIZABLE => 'reference_localizable',
            self::VARIATION_SCOPABLE => 'variation_scopable',
            self::VARIATION_LOCALIZABLE_SCOPABLE => 'variation_localizable_scopable',
            self::CATEGORIES => 'categories',
            self::TAGS => 'tags',
            self::DESCRIPTION => 'description',
            self::END_OF_USE => 'end_of_use'
        ];

        if (null !== $mappingPath) {
            $fileContents = @file_get_contents($mappingPath);
            if (false === $fileContents) {
                throw new \InvalidArgumentException(sprintf('Unable to open "%s"', $mappingPath));
            }
            $json = json_decode($fileContents, true);
            foreach ($json as $key => $value) {
                if (!in_array($key, [
                    self::REFERENCE,
                    self::REFERENCE_LOCALIZABLE,
                    self::VARIATION_SCOPABLE,
                    self::VARIATION_LOCALIZABLE_SCOPABLE,
                    self::CATEGORIES,
                    self::TAGS,
                    self::DESCRIPTION,
                    self::END_OF_USE
                ])) {
                    throw new \InvalidArgumentException(sprintf('Key "%s" is not a valid mapping', $key));
                }

                $this->mapping[$key] = $value;
            }
        }
    }

    public function get(string $key): string {
        if (!isset($this->mapping[$key])) {
            throw new \InvalidArgumentException(sprintf('Unknown field "%s"', $key));
        }

        return $this->mapping[$key];
    }
}
