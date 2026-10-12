<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use CFilter;
use CFilterChain;
use GuzzleHttp\ClientInterface;

/**
 * @phpstan-import-type Config from Strangler
 */
final class StranglerFilter extends CFilter
{
    /**
     * HTTP client override for the Yii filter.
     */
    public ?ClientInterface $client = null;

    /**
     * Feature settings, routes and callbacks for this Yii filter.
     *
     * @var Config
     */
    public array $config = [];

    /**
     * Forward an enabled action or continue the Yii filter chain.
     *
     * @param CFilterChain $filterChain
     */
    protected function preFilter(mixed $filterChain): bool
    {
        $actionId = $filterChain->action->getId();

        if ($this->config === [] || ! StranglerProxy::shouldHandle($this->config, $actionId)) {
            return true;
        }

        return ! (new StranglerProxy($this->client))->handle(
            $filterChain->controller,
            $this->config,
            $actionId,
        );
    }
}
