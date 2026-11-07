<?php

declare(strict_types=1);

use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\ForbiddenDependencies;
use Cosmira\Soda\Rules\Architecture\NoStaticMutableProperties;
use Cosmira\Soda\Rules\Documentation\NoVacuousDocblocks;

return Soda::configure()
    ->withPaths([__DIR__.'/src'])
    ->with([
        ...RuleCatalog::standard(),
        new ForbiddenDependencies([
            'Cosmira\\Strangler' => [
                'App', 'strangler', 'Illuminate', 'Symfony', 'Cosmira\\Strangler\\Tests',
            ],
            'Cosmira\\Strangler\\Schema' => [
                'GuzzleHttp', 'Psr\\Http', 'Cosmira\\Strangler\\Internal',
            ],
            'Cosmira\\Strangler\\Internal\\Payload' => [
                'GuzzleHttp', 'Psr\\Http',
            ],
        ]),
        new NoStaticMutableProperties(),
        new NoVacuousDocblocks(),
    ]);
