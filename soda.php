<?php

declare(strict_types=1);

use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\ForbiddenDependencies;
use Cosmira\Soda\Rules\Architecture\NoStaticMutableProperties;
use Cosmira\Soda\Rules\Documentation\NoVacuousDocblocks;
use Cosmira\Soda\Rules\Structure\MaxPublicMethods;
use Cosmira\Soda\Rules\Usage\NoUnusedParameters;
use Cosmira\Strangler\ControllerContract;

return Soda::configure()
    ->withPaths([__DIR__.'/src'])
    ->with([
        ...array_map(
            static fn ($rule) => $rule instanceof NoUnusedParameters
                ? new NoUnusedParameters(contracts: [
                    ControllerContract::class.'::configure',
                    ControllerContract::class.'::collectionPages',
                    ControllerContract::class.'::nativeRelations',
                    ControllerContract::class.'::responseFields',
                    ControllerContract::class.'::responseAttributes',
                    ControllerContract::class.'::responseAfter',
                    ControllerContract::class.'::responseProjection',
                    ControllerContract::class.'::responseResult',
                    ControllerContract::class.'::validateResponse',
                    ControllerContract::class.'::validationErrors',
                ])
                : $rule,
            array_map(
                // Keep the released fluent API alongside the shared fields() declaration.
                static fn ($rule) => $rule instanceof MaxPublicMethods ? new MaxPublicMethods(21) : $rule,
                RuleCatalog::standard(),
            ),
        ),
        new ForbiddenDependencies([
            'Cosmira\\Strangler' => [
                'App', 'strangler', 'Illuminate', 'Symfony', 'Cosmira\\Strangler\\Tests',
            ],
            'Cosmira\\Strangler\\Schema' => [
                'GuzzleHttp', 'Psr\\Http', 'Cosmira\\Strangler\\Internal',
                'CActiveRecord', 'CController', 'Yii',
                'Cosmira\\Strangler\\ControllerContract',
                'Cosmira\\Strangler\\Strangler',
                'Cosmira\\Strangler\\Query',
            ],
            'Cosmira\\Strangler\\Response' => [
                'CActiveRecord', 'CController', 'Yii',
                'Cosmira\\Strangler\\ControllerContract',
                'Cosmira\\Strangler\\Strangler',
                'Cosmira\\Strangler\\Query',
                'Cosmira\\Strangler\\StranglerProxy',
                'Cosmira\\Strangler\\YiiRuntime',
                'Cosmira\\Strangler\\Internal',
            ],
            'Cosmira\\Strangler\\Internal\\Payload' => [
                'GuzzleHttp', 'Psr\\Http',
            ],
        ]),
        new NoStaticMutableProperties(),
        new NoVacuousDocblocks(),
    ]);
