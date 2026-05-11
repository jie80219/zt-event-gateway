<?php

declare(strict_types=1);

namespace ZtEventGateway\Worker;

use PhpAmqpLib\Message\AMQPMessage;
use SDPMlab\ZtEventGateway\EventBus;
use SDPMlab\ZtEventGateway\MessageQueue\UnrecoverableMessageException;
use SDPMlab\LSVID\LSVIDContext;
use SDPMlab\LSVID\LSVIDException;
use SDPMlab\LSVID\LSVIDValidator;
use Keycloak\JwtValidator;
use Keycloak\KeycloakTokenContext;

/**
 * 事件消費者（Event Consumer）
 *
 * 從 RabbitMQ 事件佇列接收已由 Saga 發出的事件，負責：
 *   1. 反序列化 envelope 並檢查基本欄位（type / data）。
 *   2. 驗證事件來源的 SPIFFE 身份是否屬於受信任的 trust domain 前綴。
 *   3. 驗證 envelope 夾帶的 LSVID 巢狀簽章鏈（L0 → L1 → …）。
 *   4. 把通過驗證的 raw LSVID 放進 LSVIDContext（coroutine-local），
 *      讓 Saga handler 透過 EventBus::publish() 發下一個事件時可以
 *      被 MessageBus 自動作為 `nested` claim 擴展成下一層。
 *   5. 把 envelope 還原為事件物件並交由 EventBus 分派至對應的 handler。
 */
final class EventConsumer
{
    /** @var list<string> 允許的 SPIFFE ID 前綴，只有屬於這個 trust domain 的訊息會被放行 */
    private const ALLOWED_SOURCES = [
        'spiffe://zt.local/',
    ];

    /**
     * Time (seconds) a previously-verified LSVID stays cached for.
     * Same shape as SpiffeLsvidFilter's own memoize; 60 s is shorter than
     * any sensible LSVID TTL (1800 s) and bounded by the same saga's
     * publish-to-publish gap.
     */
    private const VERIFIED_TTL = 60.0;

    /** Hard cap to bound memory; cheap drain to half when exceeded. */
    private const VERIFIED_CAP = 1024;

    /**
     * Process-local memoize for `lsvidValidator->validate()`. A saga walks
     * 4–5 events through this consumer; each carries (mostly) the same
     * LSVID chain. ECDSA chain verification dominates per-event CPU, so
     * caching by (rawLsvid, expectedAudience) is a meaningful win.
     *
     * Multi-process worker safety: a saga's events all flow through the
     * same AMQP consumer process (a consumer doesn't migrate mid-chain
     * unless it crashes), so per-process cache is sufficient.
     *
     * @var array<string, array{at: float, parsed: object}>
     */
    private array $verifiedTokens = [];

    /**
     * 建構子。
     *
     * 支援 SPIFFE/LSVID 與 Keycloak 兩種身份驗證並存或單獨運作 — 兩邊
     * 各自的 validator 可選，並由對應的 require 旗標決定強制與否。
     *
     * @param EventBus            $eventBus               事件總線，負責把事件分派給已註冊的 handler。
     * @param LSVIDValidator|null $lsvidValidator         LSVID 驗證器；為 null 時代表此環境未啟用 LSVID，
     *                                                   envelope 若夾帶 lsvid 會被視為 wiring error。
     * @param bool                $lsvidRequired          是否強制要求 envelope 必須帶有 LSVID（fail-closed）。
     * @param bool                $requireSpiffeIdentity  是否啟用 SPIFFE 來源前綴檢查與 LSVID 驗證流程。
     * @param JwtValidator|null   $jwtValidator           Keycloak JWT 驗證器；為 null 表示未啟用。
     * @param string              $selfAudience           本服務在 Keycloak realm 中的 audience（client_id）。
     * @param bool                $requireAuthorization   是否強制要求 envelope 必須帶有合法 JWT。
     */
    public function __construct(
        private readonly EventBus $eventBus,
        private readonly ?LSVIDValidator $lsvidValidator = null,
        private readonly bool $lsvidRequired = false,
        private readonly bool $requireSpiffeIdentity = true,
        private readonly ?JwtValidator $jwtValidator = null,
        private readonly string $selfAudience = '',
        private readonly bool $requireAuthorization = false,
    ) {
    }

    /**
     * 處理單一 AMQP 事件訊息。
     *
     * 此方法是整個事件流程的防線：任何不符合 schema、trust domain 或
     * LSVID 驗證的訊息都會以 {@see UnrecoverableMessageException} 中斷
     * 處理，讓上層 Consumer 直接 ack / dead-letter 而不是 requeue，
     * 避免惡意或壞訊息造成 requeue storm。
     */
    public function process(AMQPMessage $message): void
    {
        // 1. 解析 envelope 的 JSON 主體。
        //    非法 JSON 一律視為結構錯誤，直接丟出 unrecoverable 例外。
        $payload = json_decode($message->getBody(), true);
        if (!is_array($payload)) {
            throw new UnrecoverableMessageException('Invalid event payload.');
        }

        // 2. 檢查最基本的必填欄位：事件類別與事件資料。
        //    schema_version 等更細節的欄位會在反序列化成事件物件時被檢驗。
        $eventType = $payload['type'] ?? null;
        $eventData = $payload['data'] ?? null;

        if (!is_string($eventType) || !is_array($eventData)) {
            throw new UnrecoverableMessageException('Missing event type or data.');
        }

        // ── SPIFFE 身份驗證 ─────────────────────────────────
        //   envelope 頂層會攜帶：
        //     - spiffe_id   : 發送此事件的「當前」workload ID
        //     - spiffe_path : 累積的身份鏈（明文 trace；真正的密碼學驗證交給 LSVID）
        //   這裡只做 trust domain 前綴檢查，過濾掉非 zt.local 域的流量。
        //   當 requireSpiffeIdentity=false（SPIFFE_ENABLED=0）時整段跳過。
        $sourceSpiffeId = $payload['spiffe_id'] ?? '';
        $spiffePath = $payload['spiffe_path'] ?? [];

        if ($this->requireSpiffeIdentity) {
            if ($sourceSpiffeId !== '') {
                // 有帶 SPIFFE ID：必須通過 ALLOWED_SOURCES 前綴白名單。
                $this->verifySpiffeSource($sourceSpiffeId);
                fwrite(STDOUT, sprintf(
                    "[event-consumer] source=%s path=[%s] event=%s\n",
                    $sourceSpiffeId,
                    implode(' → ', $spiffePath),
                    substr(strrchr($eventType, '\\') ?: $eventType, 1),
                ));
            } else {
                // 沒帶 SPIFFE ID：不阻斷，但要留下告警紀錄，
                // 方便在 log 中追蹤舊版或未接入 SPIFFE 的上游。
                fwrite(STDOUT, sprintf(
                    "[event-consumer] WARNING: no SPIFFE identity on event=%s\n",
                    $eventType,
                ));
            }
        }

        // ── 巢狀 LSVID 驗證 ─────────────────────────────────
        //   envelope 可能帶有 lsvid：這是由上游（Worker 自己的 MessageBus 或
        //   其他服務）透過 LSVIDSigner::extend() 簽出來的「最新一層」token，
        //   其 nested claim 一路包覆到 L0。
        //
        //   驗證通過後要把 raw token 放進 LSVIDContext（coroutine-local），
        //   這樣之後 Saga handler 呼叫 $this->publish() 時：
        //     EventBus::publish() → LSVIDContext::current() → MessageBus::publishEvent()
        //     → lsvidSigner->extend($priorLsvid, ...) 產生下一層 token。
        //   整個身份鏈就能一路延續下去。
        $rawLsvid = is_string($payload['lsvid'] ?? null) ? (string) $payload['lsvid'] : null;

        if (!$this->requireSpiffeIdentity) {
            // Master SPIFFE toggle off — skip LSVID chain validation.
            // rawLsvid (if any) is intentionally ignored; LSVIDContext is
            // not populated so MessageBus downstream publishes will also
            // skip extension (the signer itself is null in this mode).
            $rawLsvid = null;
        } elseif ($rawLsvid !== null) {
            // envelope 帶 lsvid → validator 必須已設定。
            // 如果 envelope 有 lsvid 卻沒有 validator，代表 worker 的 wiring
            // 出錯（例如 SVID 不可用卻仍有上游簽章），一律 fail-closed。
            if ($this->lsvidValidator === null) {
                throw new UnrecoverableMessageException(
                    'LSVID present on event envelope but no LSVIDValidator is configured.',
                );
            }

            try {
                // expectedAudience 設為「本 worker 的 SPIFFE ID」，
                // 確保 token 確實是「給我」的，而不是別的服務的 token
                // 被誤送進這個佇列。
                $workerSpiffeId = getenv('SPIFFE_ID') ?: null;

                // Memoize: same token+audience → skip full ECDSA chain
                // verification within VERIFIED_TTL. Token itself still
                // carries an exp claim; once that expires, the next miss
                // will re-validate and reject. Cache key bakes in the
                // audience so token-for-A can't satisfy a check for B.
                $cacheKey = hash('sha256', $rawLsvid . '|' . ($workerSpiffeId ?? ''));
                $cached = $this->verifiedTokens[$cacheKey] ?? null;
                if ($cached !== null && (microtime(true) - $cached['at']) <= self::VERIFIED_TTL) {
                    $parsed = $cached['parsed'];
                } else {
                    $parsed = $this->lsvidValidator->validate(
                        $rawLsvid,
                        expectedAudience: $workerSpiffeId,
                    );
                    if (count($this->verifiedTokens) >= self::VERIFIED_CAP) {
                        $this->verifiedTokens = array_slice(
                            $this->verifiedTokens,
                            (int) (self::VERIFIED_CAP / 2),
                            null,
                            true,
                        );
                    }
                    $this->verifiedTokens[$cacheKey] = [
                        'at'     => microtime(true),
                        'parsed' => $parsed,
                    ];
                }

                // 驗證成功：把整條鏈的 issuer 依序印出，方便追蹤
                // L0 → L1 → L2 的身份來源。
                fwrite(STDOUT, sprintf(
                    "[event-consumer] LSVID chain L0..L%d verified: %s\n",
                    $parsed->level(),
                    implode(
                        ' -> ',
                        array_map(static fn($l) => $l->issuer(), $parsed->chain()),
                    ),
                ));
                if (getenv('LSVID_LOG_PAYLOAD') === '1') {
                    foreach ($parsed->chain() as $i => $lvl) {
                        fwrite(STDOUT, sprintf(
                            "[event-consumer] LSVID L%d payload=%s\n",
                            $i,
                            json_encode($lvl->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ));
                    }
                }
            } catch (LSVIDException $e) {
                // 任何驗證失敗（簽章、CA、過期、trust domain、chain broken、replay）
                // 都轉成 unrecoverable 例外，讓這包訊息直接被丟棄。
                throw new UnrecoverableMessageException('LSVID validation failed: ' . $e->getMessage());
            }
        } elseif ($this->lsvidRequired) {
            // 沒帶 lsvid 且設定為 fail-closed：拒收。
            // 遷移期（LSVID_REQUIRED=0）才會允許缺少 lsvid 的訊息通過。
            throw new UnrecoverableMessageException(
                'LSVID required but event envelope carries none.',
            );
        }

        // ── Keycloak JWT 驗證 ──────────────────────────────────
        //   envelope 若帶有 authorization.jwt，且 requireAuthorization=true，
        //   就以本服務的 selfAudience 驗證 token 並把 claims 放進
        //   KeycloakTokenContext 供下游 publish 使用。SPIFFE 與 Keycloak
        //   兩條驗證軸是獨立的 — 若某條 flag 關閉，對應驗證直接跳過。
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

        // 3. 把 envelope 還原為對應的事件物件。
        //    若類別不存在（例如路由到未知的事件類別），直接當成結構錯誤丟棄。
        $event = $this->buildEventInstance($eventType, $eventData);
        if ($event === null) {
            throw new UnrecoverableMessageException(sprintf('Unknown event class: %s', $eventType));
        }

        // 4. 把 raw LSVID + Keycloak JWT claims 都放進 coroutine-local
        //    context 後分派事件。Saga handler 執行期間呼叫 publish() 時，
        //    EventBus 會從 LSVIDContext + KeycloakTokenContext 抓上下文，
        //    把兩種身份都鏈式傳遞下去。finally 一定會清掉，避免 coroutine
        //    間污染。
        LSVIDContext::set($rawLsvid);
        if ($jwt !== '') {
            KeycloakTokenContext::set($jwt, $kcClientId, $kcClaims);
        }
        try {
            $this->eventBus->dispatch($event);
        } finally {
            LSVIDContext::clear();
            KeycloakTokenContext::clear();
        }

        fwrite(STDOUT, sprintf("[event-consumer] handled event=%s\n", $eventType));
    }

    /**
     * 驗證事件來源的 SPIFFE ID 是否屬於允許的 trust domain 前綴。
     *
     * 這是「最外層」的白名單過濾：LSVID 的密碼學驗證在後面才跑，
     * 此處只擋下明顯不屬於本系統的流量，避免把陌生來源的訊息
     * 丟給更昂貴的驗證器處理。
     */
    private function verifySpiffeSource(string $spiffeId): void
    {
        foreach (self::ALLOWED_SOURCES as $prefix) {
            if (str_starts_with($spiffeId, $prefix)) {
                return;
            }
        }

        // 不符合任何允許的前綴：拒收並附上來源 ID 供 log 追查。
        throw new UnrecoverableMessageException(sprintf(
            'Untrusted SPIFFE source: %s',
            $spiffeId,
        ));
    }

    /**
     * 把事件資料反序列化為具體的事件物件。
     *
     * 支援兩種路徑：
     *   1. 對於已知的特殊事件（目前為 OrderCreateRequestedEvent），直接
     *      依其建構子簽名組裝。
     *   2. 其他事件類別則用反射：把 payload 依參數名稱對應，找不到就
     *      回退到預設值，再找不到則填 null。
     *
     * 回傳 null 表示 $eventClass 不存在（路由到未知類別），呼叫端
     * 會把它轉成 UnrecoverableMessageException。
     */
    private function buildEventInstance(string $eventClass, array $payload): ?object
    {
        // 類別不存在 → 由呼叫端決定如何處理（目前會轉為不可恢復例外）。
        if (!class_exists($eventClass)) {
            return null;
        }

        // 特殊處理：OrderCreateRequestedEvent 的建構子簽名比較特別，
        // 需要把整包 payload 以及可選的 traceId 分開傳入。
        if ($eventClass === \App\Events\OrderCreateRequestedEvent::class) {
            return new $eventClass(
                $payload,
                isset($payload['traceId']) ? (string) $payload['traceId'] : null,
            );
        }

        // 一般事件走反射路徑：逐一對應 constructor 參數。
        $reflection = new \ReflectionClass($eventClass);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            // 無建構子：直接 new。
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            // 依參數名字從 payload 抓值。
            if (array_key_exists($name, $payload)) {
                $args[] = $payload[$name];
                continue;
            }

            // payload 沒有這個鍵 → 嘗試使用參數的預設值。
            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }

            // 既無 payload 值也無預設值 → 填 null，交由事件類別自行處理。
            $args[] = null;
        }

        return $reflection->newInstanceArgs($args);
    }
}
