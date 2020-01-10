<?php

declare(strict_types=1);

namespace App\Processor\Converter;

use Akeneo\PimEnterprise\ApiClient\AkeneoPimEnterpriseClientBuilder;
use Akeneo\PimEnterprise\ApiClient\AkeneoPimEnterpriseClientInterface;
use App\Reader\CredentialReader;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @author    Samir Boulil <samir.boulil@akeneo.com>
 * @copyright 2019 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class MediaAttributeConverter implements DataConverterInterface
{
    private const IMAGE_ATTRIBUTE_TYPE = 'media_file';

    /** @var AkeneoPimEnterpriseClientBuilder */
    private $clientBuilder;

    /** @var AkeneoPimEnterpriseClientInterface */
    private $pimClient;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(AkeneoPimEnterpriseClientBuilder $clientBuilder, Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
        $this->clientBuilder = $clientBuilder;


    }

    public function support(array $attribute): bool
    {
        return self::IMAGE_ATTRIBUTE_TYPE === $attribute['type'];
    }

    public function convert(array $attribute, string $data, array $context)
    {
        return $data;
    }
}
