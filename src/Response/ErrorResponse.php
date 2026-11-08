<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Response;

use Cosmira\Strangler\Exception\InvalidUpstreamResponse;
use Cosmira\Strangler\Schema\Mapping;
use Psr\Http\Message\ResponseInterface;

/** Translate an upstream error into the configured Yii error vocabulary. */
final readonly class ErrorResponse
{
    /**
     * Yii clients identify failed responses through this envelope discriminator.
     */
    private const ERROR_STATE = 'error';

    /**
     * Retain naming differences independently from HTTP status-to-code conventions.
     *
     * @param array<string, string> $fields
     * @param array<int, string>    $codes
     */
    public function __construct(private array $fields, private array $codes) {}

    /**
     * Validate the upstream object before replacing its envelope or status.
     */
    public function __invoke(mixed $json, ResponseInterface $response): ResponseInterface
    {
        $body = Json::record($json);
        $status = $response->getStatusCode();
        if ($status === 500) {
            $body = [
                'state' => self::ERROR_STATE,
                'error' => $this->codes[500] ?? 'internal_error',
            ];
        } elseif (
            ($body['state'] ?? null) !== self::ERROR_STATE
            || ! is_string($body['error'] ?? null)
        ) {
            $body = $status === 422 && isset($body['errors'])
                ? $this->validation($body['errors'])
                : $this->failure($body, $status);
        }

        return Json::response($response, $body, $this->responseStatus($status));
    }

    /**
     * Restore the conventional Yii validation status without interpreting application error codes.
     */
    private function responseStatus(int $status): int
    {
        return $status === 422 ? 400 : $status;
    }

    /**
     * Keep explicit upstream codes while using configured defaults for ordinary HTTP failures.
     *
     * @param array<array-key, mixed> $body
     *
     * @return array{state: string, error: string}
     */
    private function failure(array $body, int $status): array
    {
        $code = $body['error'] ?? $body['code'] ?? $this->codes[$status]
            ?? $body['message'] ?? 'service_temporarily_unavailable';
        if (! is_string($code)) {
            throw new InvalidUpstreamResponse('Expected an upstream error code string.');
        }

        return ['state' => self::ERROR_STATE, 'error' => $code];
    }

    /**
     * Map dotted validation names while retaining the messages as Yii metadata keys.
     *
     * @return array{state: string, error: string,
     *     meta: array<array-key, array<array-key, array{}>>}
     */
    private function validation(mixed $errors): array
    {
        $messagesByField = Json::record($errors);
        $names = [];
        foreach (array_keys($messagesByField) as $field) {
            if (! is_string($field)) {
                throw new InvalidUpstreamResponse('Expected a validation field name.');
            }
            $names[$field] = implode('.', array_map(
                fn (string $segment): string => $this->fields[$segment] ?? strtoupper($segment),
                explode('.', $field),
            ));
        }
        $meta = array_map(
            static fn (array $messages): array => array_fill_keys($messages, []),
            Mapping::validationMessages($messagesByField, $names),
        );

        return ['state' => self::ERROR_STATE, 'error' => 'validation', 'meta' => $meta];
    }
}
