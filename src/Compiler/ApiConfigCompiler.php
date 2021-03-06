<?php

namespace MattJanssen\ApiResponseBundle\Compiler;

use MattJanssen\ApiResponseBundle\Annotation\ApiResponse;
use MattJanssen\ApiResponseBundle\Model\ApiConfig;
use MattJanssen\ApiResponseBundle\Model\ApiConfigInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * API Config Compiler
 *
 * @author Matt Janssen <matt@mattjanssen.com>
 */
class ApiConfigCompiler
{
    /**
     * Default Config
     *
     * @var ApiConfig
     */
    private $defaultConfig;

    /**
     * API Path Configurations
     *
     * Each relative URL path has its own CORS configuration settings in this array.
     *
     * @var ApiConfig[]
     */
    private $pathConfigs;

    /**
     * Constructor
     *
     * @param array $defaultConfigArray
     * @param array[] $pathConfigArrays
     */
    public function __construct(
        array $defaultConfigArray,
        array $pathConfigArrays
    ) {
        $defaultConfig = $this->generateApiConfig($defaultConfigArray);

        $pathConfigs = [];
        foreach ($pathConfigArrays as $path => $configArray) {
            $pathConfigs[$path] = $this->generateApiConfig($configArray);
        }

        $this->defaultConfig = $defaultConfig;
        $this->pathConfigs = $pathConfigs;
    }

    /**
     * Generate an API Config for this Request
     *
     * Based on the following, with highest priority first:
     * 1) @ApiResponse() annotation.
     * 2) Matched path config (config.yml).
     * 3) Default config (config.yml).
     *
     * @param Request $request
     *
     * @return ApiConfigInterface
     */
    public function compileApiConfig(Request $request)
    {
        // This boolean is flipped only if the request matches a path as specified in config.yml,
        // or if its controller action has the @ApiResponse() annotation.
        $pathServed = false;

        // Start with a copy of the default config.
        $compiledConfig = clone $this->defaultConfig;

        // Try to match the request origin to a path in the config.yml.
        $originPath = $request->getPathInfo();
        foreach ($this->pathConfigs as $pathRegex => $pathConfig) {
            if (!preg_match('#' . str_replace('#', '\#', $pathRegex) . '#', $originPath)) {
                // No path match.
                continue;
            }

            // Merge any path-specified configs over the defaults.
            $pathServed = true;
            $this->mergeConfig($compiledConfig, $pathConfig);

            // After the first path match, don't process the rest.
            break;
        }

        /** @var ApiResponse $attribute */
        $attribute = $request->attributes->get('_' . ApiResponse::ALIAS_NAME);

        // Check if the matching controller action has an @ApiResponse annotation.
        if (null !== $attribute) {
            $pathServed = true;

            // Merge any annotation-specified configs over the defaults.
            $this->mergeConfig($compiledConfig, $attribute);
        }

        if (!$pathServed) {
            // If there was neither a path match nor an @ApiResponse annotation, then don't handle an API response.
            return null;
        }

        return $compiledConfig;
    }

    /**
     * Merge Non-null Options from a Config into Another Config
     *
     * @param ApiConfig $compiledConfig
     * @param ApiConfigInterface $configToMerge
     */
    private function mergeConfig($compiledConfig, $configToMerge)
    {
        if (null !== $configToMerge->getCorsAllowOriginRegex()) {
            $compiledConfig->setCorsAllowOriginRegex($configToMerge->getCorsAllowOriginRegex());
        }
        if (null !== $configToMerge->getCorsAllowHeaders()) {
            $compiledConfig->setCorsAllowHeaders($configToMerge->getCorsAllowHeaders());
        }
        if (null !== $configToMerge->getCorsMaxAge()) {
            $compiledConfig->setCorsMaxAge($configToMerge->getCorsMaxAge());
        }
    }

    /**
     * @param array $configArray
     *
     * @return ApiConfig $this
     */
    private function generateApiConfig(array $configArray)
    {
        // @TODO Use PHP 7 null coalescing operator.
        return (new ApiConfig())
            ->setSerializer(isset($configArray['serializer']) ? $configArray['serializer'] : null)
            ->setGroups(isset($configArray['serialize_groups']) ? $configArray['serialize_groups'] : null)
            ->setCorsAllowHeaders(isset($configArray['cors_allow_headers']) ? $configArray['cors_allow_headers'] : null)
            ->setCorsAllowOriginRegex(isset($configArray['cors_allow_origin_regex']) ? $configArray['cors_allow_origin_regex'] : null)
            ->setCorsMaxAge(isset($configArray['cors_max_age']) ? $configArray['cors_max_age'] : null)
            ;
    }
}
