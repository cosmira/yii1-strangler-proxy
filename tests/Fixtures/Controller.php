<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests\Fixtures;

use CController;

final class Controller extends CController
{
    public bool $ran = false;

    public function actionGet(): void
    {
        $this->ran = true;
    }

    public function actionEdit(): void
    {
        $this->ran = true;
    }
}
