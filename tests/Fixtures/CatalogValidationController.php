<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests\Fixtures;

use CController;
use CFilterChain;

class CatalogValidationController extends CController
{
    public string $inputFormat = 'json';

    /** @var array<string, mixed> */
    public array $validatedInput = [];

    public ?string $itemId = null;

    public bool $resourceChecked = false;

    public bool $ran = false;

    public function filterResourceAccess(CFilterChain $chain): void
    {
        $this->resourceChecked = true;
        $chain->run();
    }

    public function actionUpdate(): void
    {
        $this->ran = true;
    }
}
