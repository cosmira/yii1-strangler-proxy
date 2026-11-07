<?php

declare(strict_types=1);

use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\ForbiddenDependencies;
use Cosmira\Soda\Rules\Documentation\MultilineConstantPhpDoc;
use Cosmira\Soda\Rules\Documentation\MultilineMethodPhpDoc;
use Cosmira\Soda\Rules\Documentation\MultilinePropertyPhpDoc;

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
        new MultilineMethodPhpDoc(['public', 'protected', 'private']),
        new MultilinePropertyPhpDoc(['public', 'protected', 'private']),
        new MultilineConstantPhpDoc(['public', 'protected', 'private']),
    ]);
