# Реализация матрицы developer experience

Матрица и критерии сохранены в [плане](README.md). Это реализация согласованных
решений шести сценарных ролей, а не одобрение Taylor Otwell/DHH или реальные интервью.

| ID | Доставленный результат | Доказательство |
| --- | --- | --- |
| DX-01 | [Каталог](../../../examples/catalog/README.md): настоящие Yii и backend, общий SQLite | Два процесса исполняют опубликованные исходники. |
| DX-02 | JSON/form input, method/auth/ACL/CSRF до filters, единый ID и cleanup | Отказы до обоих executors, cleanup один раз, POST преобразуется в PUT. |
| DX-03 | Обратная карта ошибок и `_general` | Известные alias объединяются, неизвестные сообщения сохраняются. |
| DX-04 | Причины кандидатов, статусы и длительность attempt, безопасные ошибки | Runtime tests проверяют шаблон маршрута, отсутствие URI/query/секретов и исключения hooks. |
| DX-05 | Два непересекающихся filters и flags | Все четыре сочетания, create/read/bypass/flag-off на одном наборе данных. |
| DX-06 | Безопасный application callback для metrics | Ошибка metrics сохраняет успешную запись; обязательный hook продолжает бросать исключение. |
| DX-07 | Pure [check.php](../../../examples/catalog/check.php), отдельный `/health`, [runbook](../../operations.md) | Проверка off без сети/storage/callbacks; uncertain write не повторяется автоматически. |
| DX-08 | Server-owned tenant и backend idempotency | Spoofing, stored result replay, conflict, lost response после commit без второй записи. |
| DX-09 | [Contract cases](../../../examples/catalog/contract-cases.php), runner и отдельный CI job | Настоящий HTTP и обязательный JS decoder в CI; пользовательский пилот остаётся открытым. |

## Проверка опубликованного контракта

| Группа cases | Проверяемый результат |
| --- | --- |
| Off/off, on/off, off/on, on/on | Статус, JSON envelope/types, Content-Type и X-Strangler; ровно один executor. |
| Method, guest, CSRF, resource, tenant, conflicting ID | Отказ до legacy/backend write и один cleanup. |
| JSON и `item[...]` | Один validated input, ошибки старых полей, boolean и строковые identifiers. |
| Read-after-write и non-page-aligned list | Одинаковые данные для backend, legacy bypass и flag-off. |
| Большой ID, пустой объект/массив, malformed response | Точный ID через JSON.parse; ожидаемый legacy error contract. |
| Metrics и mandatory hook | Успех при необязательной ошибке; исключение обязательного hook без replay. |
| Lost response и idempotency | 503 после commit; явный повтор возвращает stored outcome; другое тело даёт 409. |

Локальный verifier прошёл 455 проверок с `CATALOG_REQUIRE_NODE=1`, включая чтение
настоящего Yii log и конфликт top-level form ID. Полный PHPUnit: 216 тестов,
1210 assertions; покрытие classes/methods/lines — 100%. Сохраняются четыре
существующих deprecations Yii на PHP 8.5.
Мутационный gate: 534/534 обнаружены, coverage/MSI — 100%, без timeouts.
PHPStan max, Soda, Pint, Rector, Composer validate, markdownlint и typos проходят.
Удалённый CI проверяется после push итогового коммита; локальные результаты его не заменяют.

## Пилот с обоими разработчиками

Пользователь выбрал Yii-разработчика и backend-разработчика для первого подключения.
Пилот нельзя заменить агентами или количеством assertions. Провести его на чистом
checkout после установки зависимостей, без устных подсказок автора:

1. Yii-разработчик следует README, получает первый ответ и переключает read flag.
2. Он отправляет JSON/form write, находит неверный CSRF и конфликт ID.
3. Backend-разработчик находит причину pagination bypass по request ID и журналу.
4. Он воспроизводит lost response и восстанавливает результат с исходным ключом;
   проверяет отсутствие второй записи и конфликты изменённого тела.
5. Оба объясняют, когда flag-off безопасен для данных и когда требуется reconciliation.

Для каждого участника записать время первого успешного запроса, время поиска bypass,
число вопросов автору, ошибки команд и неоднозначные места документации.
Целевые 15 минут после установки зависимостей пока не подтверждены реальными пользователями.
Независимый сценарный walkthrough уже выявил и устранил гонку запуска listener
и неправильное расположение preflight в инструкции. Это вспомогательное review.

Новый validator, observer и dynamic headers оставлены до результатов пилота:
существующий публичный API покрывает пример без второго framework.
