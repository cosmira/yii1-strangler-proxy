<?php

declare(strict_types=1);

final class DemoIdentity extends CUserIdentity
{
    public function authenticate()
    {
        $this->errorCode = self::ERROR_NONE;

        return true;
    }

    public function getId()
    {
        return $this->username;
    }
}

final class SessionController extends CController
{
    public function actionLogin(): void
    {
        if (Yii::app()->request->getRequestType() !== 'POST') {
            throw new CHttpException(405, 'method_not_allowed');
        }
        $body = json_decode(Yii::app()->request->getRawBody(), true);
        $user = is_array($body) ? ($body['user'] ?? '') : '';
        if (! in_array($user, ['alice', 'bob'], true)) {
            throw new CHttpException(400, 'unknown_demo_user');
        }
        $identity = new DemoIdentity($user, '');
        $identity->authenticate();
        Yii::app()->user->login($identity);
        // Tenant is derived from this server-owned identity map, never request headers.
        $tenant = $user === 'alice' ? 'alpha' : 'beta';
        Yii::app()->user->setState('tenant', $tenant);
        $csrf = bin2hex(random_bytes(24));
        Yii::app()->user->setState('csrf', $csrf);
        catalogJson(200, ['csrf' => $csrf, 'user_id' => $user, 'tenant' => $tenant]);
    }
}

final class CatalogController extends CController
{
    public string $inputFormat = 'json';
    public array $validatedInput = [];
    public ?string $itemId = null;

    public function filters()
    {
        return array_merge(
            ['authenticate', 'validateInput', 'csrf', 'resourceAccess', 'trustedContext'],
            catalogProxyFilters(),
        );
    }

    public function filterAuthenticate(CFilterChain $chain): void
    {
        if (Yii::app()->user->isGuest) {
            throw new CHttpException(401, 'authentication_required');
        }
        $chain->run();
    }

    public function filterValidateInput(CFilterChain $chain): void
    {
        $action = $chain->action->id;
        $expected = in_array($action, ['create', 'update'], true) ? 'POST' : 'GET';
        if (Yii::app()->request->getRequestType() !== $expected) {
            throw new CHttpException(405, 'method_not_allowed');
        }
        if ($expected === 'POST') {
            if ($this->inputFormat === 'form') {
                $payload = $_POST['item'] ?? null;
            } else {
                try {
                    $payload = json_decode(Yii::app()->request->getRawBody(), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                } catch (JsonException) {
                    throw new CHttpException(400, 'invalid_payload');
                }
            }
            if (! is_array($payload) || array_is_list($payload)) {
                throw new CHttpException(400, 'invalid_payload');
            }
            if (! isset($payload['NAME'], $payload['ACTIVE'], $payload['CATEGORY_ID'])
                || ! is_string($payload['NAME']) || ! is_scalar($payload['CATEGORY_ID'])
                || ! in_array($payload['ACTIVE'], [true, false, 0, 1, '0', '1'], true)) {
                throw new CHttpException(400, 'invalid_fields');
            }
            $payload['ACTIVE'] = in_array($payload['ACTIVE'], [true, 1, '1'], true);
            $payload['CATEGORY_ID'] = (string) $payload['CATEGORY_ID'];
            $this->validatedInput = $payload;
        }
        // Use Yii's identical request-id precedence for ACL and proxy substitution,
        // including a top-level form id rather than only the URL query.
        $queryId = Yii::app()->request->getParam('id');
        $bodyId = $this->validatedInput['ID'] ?? null;
        foreach ([$queryId, $bodyId] as $id) {
            if ($id !== null && (! is_scalar($id) || preg_match('/^[0-9]+$/', (string) $id) !== 1)) {
                throw new CHttpException(400, 'invalid_identifier');
            }
        }
        if ($queryId !== null && $bodyId !== null && (string) $queryId !== (string) $bodyId) {
            throw new CHttpException(400, 'conflicting_identifiers');
        }
        $this->itemId = $queryId === null ? ($bodyId === null ? null : (string) $bodyId) : (string) $queryId;
        if (in_array($action, ['view', 'update'], true) && $this->itemId === null) {
            throw new CHttpException(400, 'missing_identifier');
        }
        foreach (['start' => 0, 'limit' => 20] as $field => $default) {
            $value = $_GET[$field] ?? $default;
            if (! is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false
                || (int) $value < ($field === 'limit' ? 1 : 0) || ($field === 'limit' && (int) $value > 100)) {
                throw new CHttpException(400, 'invalid_pagination');
            }
        }
        $chain->run();
    }

    public function filterCsrf(CFilterChain $chain): void
    {
        if (in_array($chain->action->id, ['create', 'update'], true)) {
            $expected = Yii::app()->user->getState('csrf', '');
            $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if ($expected === '' || ! hash_equals($expected, $provided)) {
                throw new CHttpException(403, 'csrf_rejected');
            }
        }
        $chain->run();
    }

    public function filterResourceAccess(CFilterChain $chain): void
    {
        if ($this->itemId !== null && in_array($chain->action->id, ['view', 'update'], true)) {
            $item = catalogFind(catalogDatabase(), $this->itemId);
            if ($item === null) {
                throw new CHttpException(404, 'item_not_found');
            }
            if ($item['tenant'] !== Yii::app()->user->getState('tenant') || $item['owner'] !== Yii::app()->user->id) {
                throw new CHttpException(403, 'access_denied');
            }
        }
        $chain->run();
    }

    public function filterTrustedContext(CFilterChain $chain): void
    {
        $tenant = Yii::app()->user->getState('tenant', '');
        if ($tenant === '') {
            throw new CHttpException(403, 'tenant_required');
        }
        $settings = Yii::app()->params['strangler'];
        $settings['headers']['X-Tenant-Id'] = $tenant;
        Yii::app()->params['strangler'] = $settings;
        $chain->run();
    }

    public function actionView(): void
    {
        catalogEvent('legacy', ['action' => 'view']);
        catalogJson(200, ['success' => true, 'item' => catalogLegacyItem(catalogFind(catalogDatabase(), $this->itemId))]);
    }

    public function actionGet(): void
    {
        catalogEvent('legacy', ['action' => 'get']);
        $result = catalogList(catalogDatabase(), Yii::app()->user->getState('tenant'), Yii::app()->user->id, (int) ($_GET['start'] ?? 0), (int) ($_GET['limit'] ?? 20));
        catalogJson(200, ['success' => true, 'items' => array_map('catalogLegacyItem', $result['items']), 'total' => $result['total']]);
    }

    public function actionCreate(): void
    {
        $this->write(null, 201);
    }

    public function actionUpdate(): void
    {
        $this->write($this->itemId, 200);
    }

    private function write(?string $id, int $status): void
    {
        catalogEvent('legacy', ['action' => $id === null ? 'create' : 'update']);
        if (trim($this->validatedInput['NAME']) === '') {
            catalogJson(422, catalogErrorBody('validation_failed', ['NAME' => ['Required']]));

            return;
        }
        $database = catalogDatabase();
        $database->beginTransaction();
        $item = catalogSave($database, Yii::app()->user->getState('tenant'), Yii::app()->user->id, $this->validatedInput, $id);
        $database->commit();
        catalogJson($status, ['success' => true, 'item' => catalogLegacyItem($item)]);
    }
}

final class ApiErrorController extends CController
{
    public function actionError(): void
    {
        $error = Yii::app()->errorHandler->error;
        $status = (int) ($error['code'] ?? 500);
        catalogJson($status, catalogErrorBody($status >= 500 ? 'internal_error' : ($error['message'] ?? 'request_rejected')));
    }
}
