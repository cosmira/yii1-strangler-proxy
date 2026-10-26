# Закрытие замечаний независимых ревью

Карта относится к [ревью выразительности API](otwell-lens.md) (L1–L32) и
[ревью простоты и соглашений](dhh-lens.md) (R1–R34). Исторические отчеты сохранены:
они описывают состояние до исправлений. Здесь отдельно отмечены исправления,
сохраненные решения и условия, которые выполняет приложение.

«Сохранено» означает проверенное удачное решение, а не пропущенное замечание.
«Условие интеграции» означает, что пакет дает конкретную инструкцию, но не может
сам назначить владельца данных, правила доступа или процедуру deployment.

## Выразительность API: 32 пункта

| Пункт | Решение | Подтверждение |
| --- | --- | --- |
| L1 — назначение | Сохранено: фичи мигрируют по одной, старый клиентский контракт сохраняет адаптер. | [README](../../README.md#yii-strangler-proxy) |
| L2 — аудитория | Уточнены PHP 8.2+ / Yii 1.1.31+ и необходимость предварительного upgrade. | [Требования](../../README.md#yii-strangler-proxy) |
| L3 — scope | Указаны JSON/form→JSON и границы uploads, binary и streaming. | [Scope](../../README.md#yii-strangler-proxy), [payloads](../integration.md#headers-tls-and-payloads) |
| L4 — имена пакета | Сохранены имя и entry point; объяснение назначения стало явнее. | [README](../../README.md#yii-strangler-proxy) |
| L5 — установка | Добавлены команды, полный path repository и version override. | [Getting started](../../README.md#getting-started) |
| L6 — первый запрос | Один существующий GET, curl, ожидаемый body/marker и проверка выключенного flag. | [Getting started](../../README.md#getting-started) |
| L7 — builder | Сохранена fluent-цепочка, возвращающая нативный Yii filter array. | [Strangler](../../src/Strangler.php) |
| L8 — направление методов | Incoming method проверяет Yii; builder выбирает outgoing method. | [Подключение](../api-adaptation.md#keep-routing-and-security-explicit) |
| L9 — HTTP methods | Добавлены `patch()` и `head()` без raw-config обхода. | [Strangler](../../src/Strangler.php) |
| L10 — missing path | Неразрешенный placeholder возвращает 400 до HTTP request. | [Proxy](../../src/StranglerProxy.php), [URL contract](../api-adaptation.md#keep-routing-and-security-explicit) |
| L11 — route collision | Duplicate action declaration отклоняется; normalization сохранена. | [Strangler](../../src/Strangler.php) |
| L12 — modifier intent | В документации «API contract adapter»; public PHP имя сохранено для совместимости. | [Adapter](../api-adaptation.md#define-and-connect-one-feature) |
| L13 — цена адаптера | Добавлен `AbstractStranglerModifier` с identity-реализацией: переопределяются только нужные методы. | [Базовый адаптер](../../src/AbstractStranglerModifier.php) |
| L14 — form reader | Сохранен `payloadUsing()` и обязательная валидация submitted structure. | [Form fields](../api-adaptation.md#keep-routing-and-security-explicit) |
| L15 — invalid JSON | Пустое тело допустимо; malformed и non-array JSON дают 400 до forwarding. | [Proxy](../../src/StranglerProxy.php), [payload policy](../integration.md#headers-tls-and-payloads) |
| L16 — identity source | Original body ID сохраняется до mapping; request ID имеет precedence. | [URL contract](../api-adaptation.md#keep-routing-and-security-explicit) |
| L17 — response contract | Serialized body сохранен; пример явно задает response headers после rewrite. | [Response mapping](../api-adaptation.md#define-and-connect-one-feature) |
| L18 — errors | Backend 422 и package 503 проходят один response adapter; показаны exact envelopes. | [Error mapping](../api-adaptation.md#define-and-connect-one-feature), [Proxy](../../src/StranglerProxy.php) |
| L19 — pagination | Некратный offset явно остается в Yii через bypass до HTTP request. | [Mapping and bypass](../api-adaptation.md#keep-routing-and-security-explicit) |
| L20 — security order | Сохранено last-filter правило; добавлены verbs, CSRF и resource checks. | [Access checks](../integration.md#access-checks-and-yii-hooks) |
| L21 — Yii lifecycle | Явно описаны пропуск beforeAction/afterAction/postFilter и перенос обязательных checks в preFilter. | [Yii hooks](../integration.md#access-checks-and-yii-hooks) |
| L22 — user context | Server-owned identity сохранена; guest, tenant и resource permissions объяснены. | [Context](../../README.md#sessions-and-user-context) |
| L23 — trusted headers | Allowlist, reserved client headers, server-owned values и executable backend token guard. | [HttpHeaders](../../src/HttpHeaders.php), [Trusted channel](../integration.md#trusted-channel) |
| L24 — TLS | Verification true по умолчанию, поддержан CA-bundle path. | [Proxy](../../src/StranglerProxy.php), [TLS](../integration.md#headers-tls-and-payloads) |
| L25 — flags / rollback | Default-off сохранен, только boolean true включает. Reload и совместимость данных — явные условия приложения. | [Rollout](../integration.md#rollout-and-rollback) |
| L26 — retries | No retry/no fallback сохранены; описана ответственность client/backend за idempotency. | [Errors](../../README.md#feature-flags-and-errors), [Rollout](../integration.md#rollout-and-rollback) |
| L27 — timeouts | Даны production values и positive finite policy; invalid/zero используют defaults. | [Settings](../integration.md#headers-tls-and-payloads), [Proxy](../../src/StranglerProxy.php) |
| L28 — diagnostics | Marker, duration и category сохранены; existing Request ID разрешен, bypass troubleshooting описан. | [Diagnostics](../../README.md#quick-diagnostics), [Logging](../integration.md#logging-and-callbacks) |
| L29 — application errors | Exceptions адаптера/hooks не маскируются под transport failure; описан риск ошибки после write. | [Callbacks](../integration.md#logging-and-callbacks), [Mapping](../api-adaptation.md#define-and-connect-one-feature) |
| L30 — test guarantees | Пример guide исполняется как contract fixture; quality gates не обещают бизнес-совместимость. | [Guide tests](../../tests/ApiAdaptationGuideTest.php), [README badges](../../README.md#yii-strangler-proxy) |
| L31 — docs structure | Все badges сохранены и сгруппированы; README ведет к двум пользовательским инструкциям. | [README](../../README.md) |
| L32 — composition | Сохранена небольшая orchestration; выделена только общая политика HTTP headers, без container/event framework. | [Proxy](../../src/StranglerProxy.php), [HttpHeaders](../../src/HttpHeaders.php) |

## Простота и соглашения: 34 пункта

| Пункт | Решение | Подтверждение |
| --- | --- | --- |
| R1 — смысл | Сохранено короткое объяснение постепенной миграции. | [README](../../README.md#yii-strangler-proxy) |
| R2 — prerequisites | Минимальные версии и upgrade legacy runtime указаны в начале. | [README](../../README.md#yii-strangler-proxy) |
| R3 — ответственность | Приложение владеет API schema и trusted channel; есть конкретный recipe. | [Adapter guide](../api-adaptation.md), [Trusted channel](../integration.md#trusted-channel) |
| R4 — абстракции | Нет новой платформы: Yii filter, существующий adapter contract, маленький identity base и одна header policy. | [Source](../../src/StranglerFilter.php), [Базовый адаптер](../../src/AbstractStranglerModifier.php) |
| R5 — имена | Adapter intent отражен в prose; публичный Modifier сохранен. | [Adapter](../api-adaptation.md#define-and-connect-one-feature) |
| R6 — Yii conventions | Filter array и component configuration сохранены. | [Builder](../../src/Strangler.php), [Proxy](../../src/StranglerProxy.php) |
| R7 — первый запуск | Полный Composer path recipe и проверяемый GET. | [Getting started](../../README.md#getting-started) |
| R8 — воспроизводимость | Записать reviewed commit, deploy same checkout + lock; pre-release stability не обещается. | [Installation](../../README.md#getting-started), [Deployment](../integration.md#rollout-and-rollback) |
| R9 — public API | Короткая feature→route→build цепочка сохранена. | [README example](../../README.md#getting-started) |
| R10 — methods | PATCH и HEAD добавлены без отдельного DSL. | [Builder](../../src/Strangler.php) |
| R11 — incoming/outgoing | Направление метода явно объяснено рядом с configuration. | [Routes](../api-adaptation.md#keep-routing-and-security-explicit) |
| R12 — route identifier | 400 до отправки при незаполненном placeholder. | [Proxy](../../src/StranglerProxy.php) |
| R13 — transformed query | Path учитывает transformed query перед original query; body/ID precedence документированы. | [URL contract](../api-adaptation.md#keep-routing-and-security-explicit), [Proxy](../../src/StranglerProxy.php) |
| R14 — query whitelist | Adapter строит только разрешенные backend fields. | [Catalog adapter](../api-adaptation.md#define-and-connect-one-feature) |
| R15 — invalid JSON | Пустой body отделен от malformed/non-array JSON. | [Payload policy](../integration.md#headers-tls-and-payloads) |
| R16 — forms | Explicit reader сохранен; без угадывания multipart и file forwarding. | [Forms](../api-adaptation.md#keep-routing-and-security-explicit) |
| R17 — payload twice | Filter использует один payload snapshot для bypass и forwarding. | [Filter](../../src/StranglerFilter.php), [Payload policy](../integration.md#headers-tls-and-payloads) |
| R18 — both API directions | Полный request/list/item response mapping сохранен. | [Catalog adapter](../api-adaptation.md#define-and-connect-one-feature) |
| R19 — mapper failures | Не скрыты: application error handler, негативный сценарий guide, запрет replay writes. | [Mapping](../api-adaptation.md#define-and-connect-one-feature), [Callbacks](../integration.md#logging-and-callbacks) |
| R20 — pagination | Explicit bypass несовместимого offset вместо округления набора данных. | [Bypass](../api-adaptation.md#keep-routing-and-security-explicit) |
| R21 — error envelope | Один существующий `transformResponse()` для upstream и package errors; новый error framework не добавлен. | [Proxy](../../src/StranglerProxy.php), [422/503 examples](../api-adaptation.md#define-and-connect-one-feature) |
| R22 — flags | Strict boolean true; env parsing показан явно. | [Trusted-channel config](../integration.md#trusted-channel), [Proxy](../../src/StranglerProxy.php) |
| R23 — data rollback | Условие интеграции: flag не возвращает схему/данные; требуется договор владельца записи. | [Rollout](../integration.md#rollout-and-rollback) |
| R24 — no fallback | Сохранено, включая transport failure; idempotency принадлежит приложению. | [Errors](../../README.md#feature-flags-and-errors) |
| R25 — authorization | Все auth/access/resource checks до filter, identity не равна permission. | [Access checks](../integration.md#access-checks-and-yii-hooks) |
| R26 — verbs / CSRF | Явные prerequisites для write actions; пакет не подменяет действующие Yii security filters. | [Access checks](../integration.md#access-checks-and-yii-hooks) |
| R27 — TLS | True default и CA path; deliberate local-only opt-out. | [TLS](../integration.md#headers-tls-and-payloads) |
| R28 — service identity | Config-owned token, hash_equals + nonempty secret backend example; other trusted channels допустимы. | [Trusted channel](../integration.md#trusted-channel) |
| R29 — credentials / cookies | Client Cookie/Authorization исключены по умолчанию, explicit opt-in документирован; backend Set-Cookie всегда исключен. | [HttpHeaders](../../src/HttpHeaders.php), [Sessions](../../README.md#sessions-and-user-context) |
| R30 — HTTP boundary | Одна политика исключает hop headers, включая Connection-nominated fields; adapter задает headers переписанного body. | [HttpHeaders](../../src/HttpHeaders.php), [Adapter](../api-adaptation.md#define-and-connect-one-feature) |
| R31 — logs | Конкретная CLogRouter/CFileLogRoute config и причины ухода в legacy; existing Request ID. | [Logging](../integration.md#logging-and-callbacks) |
| R32 — callback | Имя сохранено для compatibility, exact timing/failure semantics описаны; event framework не добавлен. | [Callbacks](../integration.md#logging-and-callbacks) |
| R33 — metrics | Quality gates сохранены; executable guide tests и tests user-visible contracts дополняют source metrics. | [Guide tests](../../tests/ApiAdaptationGuideTest.php), [README](../../README.md#yii-strangler-proxy) |
| R34 — cohesion / docs | Header policy выделена, orchestration не раздроблена; badges regrouped и user guides разделены. | [HttpHeaders](../../src/HttpHeaders.php), [README](../../README.md) |

## Проверки

Финальный локальный прогон после исправлений:

| Проверка | Результат |
| --- | --- |
| PHPUnit | 140 тестов, 650 проверок |
| Coverage | 100%: 6 классов, 55 методов, 392 исполняемые строки |
| Infection | MSI 100%: 404/404 мутации уничтожены тестами; нет пропущенных, исключенных, непокрытых или завершившихся по таймауту |
| PHPStan | Уровень max, ошибок нет |
| Soda | 0 замечаний |
| Rector | Dry run без изменений |
| Pint | Стиль проходит |
| Composer | Строгая валидация проходит |
| Markdownlint / Typos | Документация и написание проходят |

Команды: `composer test:coverage`, `composer test:mutation -- --threads=1 --no-progress`,
`php vendor/bin/phpstan analyse --debug --no-progress --memory-limit=1G`,
`php vendor/bin/rector process --dry-run --debug --no-progress-bar`, `php vendor/bin/pint --test src tests`,
`composer validate --strict --no-check-lock` и Soda с конфигурацией `soda.php`.

Локальные PHP-проверки выполнены на PHP 8.5. В тестах остаются четыре deprecation из установленного Yii;
они не относятся к коду пакета. Матрица CI настроена на PHP 8.2–8.5; результаты нового CI здесь не заявляются.
