<?php

declare(strict_types=1);

namespace App\Processor\Converter;

/**
 * Converter for text attribute data
 *
 * @author    Adrien Pétremann <adrien.petremann@akeneo.com>
 * @copyright 2019 Akeneo SAS (https://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class TextAttributeConverter implements DataConverterInterface
{
    /**
     * {@inheritdoc}
     */
    public function support(array $attribute): bool
    {
        return isset($attribute['type']) && ('text' === $attribute['type'] || 'media_link' === $attribute['type']);
    }

    /**
     * {@inheritdoc}
     */
    public function convert(array $attribute, string $data, array $context)
    {
        return $data;
    }
}
