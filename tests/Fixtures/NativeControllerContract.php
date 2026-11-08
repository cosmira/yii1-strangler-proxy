<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests\Fixtures;

use CActiveRecord;
use CController;
use Cosmira\Strangler\ControllerContract;
use Psr\Http\Message\ResponseInterface;

final class NativeControllerContract extends ControllerContract
{
    public int $reads = 0;

    public int $models = 0;

    public ?ResponseInterface $validated = null;

    public function __construct(private readonly CActiveRecord $native) {}

    public function model(CController $controller): CActiveRecord
    {
        $this->models++;

        return $this->native;
    }

    public function payload(CController $controller): array
    {
        $this->reads++;

        return ['DISPLAY_NAME' => 'Name'];
    }

    public function validateResponse(ResponseInterface $response): void
    {
        $this->validated = $response;
    }
}
