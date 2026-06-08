<?php

declare(strict_types=1);

namespace ZtEventGateway\Worker;

use PhpAmqpLib\Message\AMQPMessage;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\MessageQueue\UnrecoverableMessageException;
use Keycloak\JwtValidator;
use Keycloak\KeycloakTokenContext;

/**
 * 事件消費者（Event Consumer）— SPIFFE+KC 基線（無 LSVID）。
 *
 * 從 RabbitMQ 事件佇列接收 envelope，做：
 *   1. 反序列化與基本欄位檢查。
 *   2. SPIFFE trust-domain 前綴白名單檢查（無巢狀簽章鏈驗證）。
 *   3. Keycloak JWT 驗證（若啟用）。
 *   4. 反射還原為事件物件並透過 EventBus 分派。
 */
final class EventConsumer
{
    private const ALLOWED_SOURCES = [
        'spiffe://zt.local/',
    ];

    public function __construct(
        private readonly EventBus $eventBus,
        private readonly bool $requireSpiffeIdentity = true,
        private readonly ?JwtValidator $jwtValidator = null,
        private readonly string $selfAudience = '',
        private readonly bool $requireAuthorization = false,
    ) {
    }

    public function process(AMQPMessage $message): void
    {
        $payload = json_decode($message->getBody(), true);
        if (!is_array($payload)) {
            throw new UnrecoverableMessageException('Invalid event payload.');
        }

        $eventType = $payload['type'] ?? null;
        $eventData = $payload['data'] ?? null;

        if (!is_string($eventType) || !is_array($eventData)) {
            throw new UnrecoverableMessageException('Missing event type or data.');
        }

        $sourceSpiffeId = $payload['spiffe_id'] ?? '';
        $spiffePath = $payload['spiffe_path'] ?? [];

        if ($this->requireSpiffeIdentity) {
            if ($sourceSpiffeId !== '') {
                $this->verifySpiffeSource($sourceSpiffeId);
                fwrite(STDOUT, sprintf(
                    "[event-consumer] source=%s path=[%s] event=%s\n",
                    $sourceSpiffeId,
                    implode(' → ', $spiffePath),
                    substr(strrchr($eventType, '\\') ?: $eventType, 1),
                ));
            } else {
                fwrite(STDOUT, sprintf(
                    "[event-consumer] WARNING: no SPIFFE identity on event=%s\n",
                    $eventType,
                ));
            }
        }

        // ── Keycloak JWT 驗證 ──────────────────────────────────
        $authorization = $payload['authorization'] ?? null;
        $jwt           = is_array($authorization) && is_string($authorization['jwt'] ?? null)
            ? (string) $authorization['jwt'] : '';
        $kcClientId    = is_array($authorization) && is_string($authorization['client_id'] ?? null)
            ? (string) $authorization['client_id'] : '';
        $kcClaims      = [];

        if ($this->requireAuthorization) {
            if ($jwt === '') {
                throw new UnrecoverableMessageException('Event envelope missing authorization.jwt');
            }
            if ($this->jwtValidator === null) {
                throw new UnrecoverableMessageException(
                    'Authorization required but no JwtValidator is configured (wiring error).',
                );
            }
            try {
                $kcClaims = $this->jwtValidator->validate($jwt, $this->selfAudience);
            } catch (\Throwable $e) {
                throw new UnrecoverableMessageException('JWT validation failed: ' . $e->getMessage());
            }

            $tokenPathLog = is_array($payload['token_path'] ?? null) ? $payload['token_path'] : [];
            fwrite(STDOUT, sprintf(
                "[event-consumer] JWT verified iss=%s azp=%s aud=%s event=%s path=[%s]\n",
                (string) ($kcClaims['iss'] ?? ''),
                (string) ($kcClaims['azp'] ?? $kcClaims['client_id'] ?? ''),
                $this->selfAudience,
                substr(strrchr($eventType, '\\') ?: $eventType, 1),
                implode(' -> ', $tokenPathLog),
            ));
        }

        $event = $this->buildEventInstance($eventType, $eventData);
        if ($event === null) {
            throw new UnrecoverableMessageException(sprintf('Unknown event class: %s', $eventType));
        }

        if ($jwt !== '') {
            KeycloakTokenContext::set($jwt, $kcClientId, $kcClaims);
        }
        try {
            $this->eventBus->dispatch($event);
        } finally {
            KeycloakTokenContext::clear();
        }

        fwrite(STDOUT, sprintf("[event-consumer] handled event=%s\n", $eventType));
    }

    private function verifySpiffeSource(string $spiffeId): void
    {
        foreach (self::ALLOWED_SOURCES as $prefix) {
            if (str_starts_with($spiffeId, $prefix)) {
                return;
            }
        }

        throw new UnrecoverableMessageException(sprintf(
            'Untrusted SPIFFE source: %s',
            $spiffeId,
        ));
    }

    private function buildEventInstance(string $eventClass, array $payload): ?object
    {
        if (!class_exists($eventClass)) {
            return null;
        }

        if ($eventClass === \App\Events\OrderCreateRequestedEvent::class) {
            return new $eventClass(
                $payload,
                isset($payload['traceId']) ? (string) $payload['traceId'] : null,
            );
        }

        static $reflCache = [];

        if (!isset($reflCache[$eventClass])) {
            $reflection = new \ReflectionClass($eventClass);
            $constructor = $reflection->getConstructor();
            $params = [];
            if ($constructor !== null) {
                foreach ($constructor->getParameters() as $parameter) {
                    $hasDefault = $parameter->isDefaultValueAvailable();
                    $params[] = [
                        'name' => $parameter->getName(),
                        'hasDefault' => $hasDefault,
                        'default' => $hasDefault ? $parameter->getDefaultValue() : null,
                    ];
                }
            }
            $reflCache[$eventClass] = [
                'reflection' => $reflection,
                'hasConstructor' => $constructor !== null,
                'params' => $params,
            ];
        }

        $cached = $reflCache[$eventClass];
        $reflection = $cached['reflection'];

        if (!$cached['hasConstructor']) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($cached['params'] as $parameter) {
            $name = $parameter['name'];
            if (array_key_exists($name, $payload)) {
                $args[] = $payload[$name];
                continue;
            }
            if ($parameter['hasDefault']) {
                $args[] = $parameter['default'];
                continue;
            }
            $args[] = null;
        }

        return $reflection->newInstanceArgs($args);
    }
}
