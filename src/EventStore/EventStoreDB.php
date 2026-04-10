<?php
namespace SDPMlab\ZtEventGateway\EventStore;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Ramsey\Uuid\Uuid;

class EventStoreDB
{
    private Client $httpClient;
    private string $eventStoreUrl;

    public function __construct(string $host, int $port, string $username, string $password)
    {
        $this->eventStoreUrl = "http://{$host}:{$port}";
        $this->httpClient = new Client([
            'base_uri' => $this->eventStoreUrl,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'auth' => [$username, $password],
        ]);
    }

    /**
     * 儲存事件
     *
     * 將 metadata 與呼叫端傳入的 metadata 合併（如 SPIFFE、LSVID 資訊），
     * 並自動加入 timestamp。
     *
     * @param string $streamName 事件流名稱
     * @param array  $eventData  事件內容，包含 eventType, data, metadata (optional)
     * @param int    $expectedVersion 預期版本號（-2=any, -1=no stream, >=0 精確版本）
     * @return bool
     */
    public function appendEvent(string $streamName, array $eventData, int $expectedVersion = -2): bool
    {
        $eventId = Uuid::uuid4()->toString();
        $timestamp = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');

        // 合併呼叫端的 metadata 與自動產生的 timestamp
        $incomingMetadata = $eventData['metadata'] ?? [];
        $metadata = array_merge($incomingMetadata, [
            'timestamp' => $timestamp,
        ]);

        $payload = [
            'eventId' => $eventId,
            'eventType' => $eventData['eventType'],
            'data' => $eventData['data'],
            'metadata' => $metadata,
        ];

        try {
            $response = $this->httpClient->post("/streams/{$streamName}", [
                'json' => [$payload],
                'headers' => [
                    'Content-Type' => 'application/vnd.eventstore.events+json',
                    'Accept' => 'application/json',
                    'ES-EventType' => $eventData['eventType'],
                    'ES-EventId' => $eventId,
                    'ES-ExpectedVersion' => (string) $expectedVersion,
                ]
            ]);

            return $response->getStatusCode() === 201;
        } catch (RequestException $e) {
            $this->logError('appendEvent', $streamName, $e);
            return false;
        }
    }

    /**
     * 批量儲存多個事件到同一個 stream（原子操作）
     *
     * @param string $streamName 事件流名稱
     * @param array  $events     事件陣列，每個元素包含 eventType, data, metadata
     * @param int    $expectedVersion 預期版本號
     * @return bool
     */
    public function appendEvents(string $streamName, array $events, int $expectedVersion = -2): bool
    {
        $payloads = [];
        foreach ($events as $eventData) {
            $eventId = Uuid::uuid4()->toString();
            $timestamp = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');

            $metadata = array_merge($eventData['metadata'] ?? [], [
                'timestamp' => $timestamp,
            ]);

            $payloads[] = [
                'eventId' => $eventId,
                'eventType' => $eventData['eventType'],
                'data' => $eventData['data'],
                'metadata' => $metadata,
            ];
        }

        try {
            $response = $this->httpClient->post("/streams/{$streamName}", [
                'json' => $payloads,
                'headers' => [
                    'Content-Type' => 'application/vnd.eventstore.events+json',
                    'Accept' => 'application/json',
                    'ES-ExpectedVersion' => (string) $expectedVersion,
                ]
            ]);

            return $response->getStatusCode() === 201;
        } catch (RequestException $e) {
            $this->logError('appendEvents', $streamName, $e);
            return false;
        }
    }

    /**
     * 讀取事件（正向，支援分頁）
     *
     * @param string $streamName 事件流名稱
     * @param int    $start      起始事件編號（0-based）
     * @param int    $count      讀取數量
     * @return array 事件陣列
     */
    public function readEventsForward(string $streamName, int $start = 0, int $count = 20): array
    {
        try {
            $response = $this->httpClient->get("/streams/{$streamName}/{$start}/forward/{$count}", [
                'headers' => [
                    'Accept' => 'application/vnd.eventstore.atom+json',
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                $body = json_decode($response->getBody()->getContents(), true);
                return $this->parseAtomEntries($body);
            }
        } catch (RequestException $e) {
            $this->logError('readEventsForward', $streamName, $e);
        }

        return [];
    }

    /**
     * 讀取事件（反向，最新的在前）
     *
     * @param string $streamName 事件流名稱
     * @param int    $count      讀取數量
     * @return array 事件陣列（最新在前）
     */
    public function readEventsBackward(string $streamName, int $count = 20): array
    {
        try {
            $response = $this->httpClient->get("/streams/{$streamName}/head/backward/{$count}", [
                'headers' => [
                    'Accept' => 'application/vnd.eventstore.atom+json',
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                $body = json_decode($response->getBody()->getContents(), true);
                return $this->parseAtomEntries($body);
            }
        } catch (RequestException $e) {
            $this->logError('readEventsBackward', $streamName, $e);
        }

        return [];
    }

    /**
     * 讀取事件流中的所有事件
     *
     * @param string $streamName 事件流名稱
     * @return array 事件陣列
     */
    public function readEvents(string $streamName): array
    {
        try {
            $response = $this->httpClient->get("/streams/{$streamName}", [
                'headers' => ['Accept' => 'application/vnd.eventstore.atom+json']
            ]);

            if ($response->getStatusCode() === 200) {
                $body = json_decode($response->getBody()->getContents(), true);
                return $this->parseAtomEntries($body);
            }
        } catch (RequestException $e) {
            $this->logError('readEvents', $streamName, $e);
        }

        return [];
    }

    /**
     * 讀取最新一筆事件（優化：僅讀取最後一筆）
     *
     * @param string $streamName
     * @return array|null
     */
    public function readLastEvent(string $streamName): ?array
    {
        $events = $this->readEventsBackward($streamName, 1);
        return !empty($events) ? $events[0] : null;
    }

    /**
     * 讀取指定事件編號的單一事件
     *
     * @param string $streamName
     * @param int    $eventNumber
     * @return array|null
     */
    public function readEvent(string $streamName, int $eventNumber): ?array
    {
        try {
            $response = $this->httpClient->get("/streams/{$streamName}/{$eventNumber}", [
                'headers' => ['Accept' => 'application/json']
            ]);

            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody()->getContents(), true);
            }
        } catch (RequestException $e) {
            $this->logError('readEvent', $streamName, $e);
        }

        return null;
    }

    /**
     * 檢查事件流是否存在
     *
     * @param string $streamName
     * @return bool
     */
    public function streamExists(string $streamName): bool
    {
        try {
            $response = $this->httpClient->head("/streams/{$streamName}", [
                'headers' => ['Accept' => 'application/json'],
            ]);
            return $response->getStatusCode() === 200;
        } catch (RequestException $e) {
            $statusCode = $e->getResponse()?->getStatusCode();
            if ($statusCode === 404) {
                return false;
            }
            $this->logError('streamExists', $streamName, $e);
            return false;
        }
    }

    /**
     * 刪除事件流（soft delete，可恢復）
     *
     * @param string $streamName
     * @return bool
     */
    public function deleteStream(string $streamName): bool
    {
        try {
            $response = $this->httpClient->delete("/streams/{$streamName}", [
                'headers' => [
                    'ES-ExpectedVersion' => '-2',
                ],
            ]);
            return in_array($response->getStatusCode(), [204, 200]);
        } catch (RequestException $e) {
            $this->logError('deleteStream', $streamName, $e);
            return false;
        }
    }

    /**
     * 硬刪除事件流（不可恢復）
     *
     * @param string $streamName
     * @return bool
     */
    public function hardDeleteStream(string $streamName): bool
    {
        try {
            $response = $this->httpClient->delete("/streams/{$streamName}", [
                'headers' => [
                    'ES-HardDelete' => 'true',
                    'ES-ExpectedVersion' => '-2',
                ],
            ]);
            return in_array($response->getStatusCode(), [204, 200]);
        } catch (RequestException $e) {
            $this->logError('hardDeleteStream', $streamName, $e);
            return false;
        }
    }

    /**
     * 取得事件流的 metadata（含事件數量等資訊）
     *
     * @param string $streamName
     * @return array|null
     */
    public function getStreamMetadata(string $streamName): ?array
    {
        try {
            $response = $this->httpClient->get("/streams/{$streamName}/metadata", [
                'headers' => ['Accept' => 'application/json'],
            ]);

            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody()->getContents(), true);
            }
        } catch (RequestException $e) {
            $this->logError('getStreamMetadata', $streamName, $e);
        }

        return null;
    }

    /**
     * 設定事件流的 metadata
     *
     * @param string $streamName
     * @param array  $metadata   例如 ['$maxCount' => 1000, '$maxAge' => 86400]
     * @return bool
     */
    public function setStreamMetadata(string $streamName, array $metadata): bool
    {
        try {
            $response = $this->httpClient->post("/streams/{$streamName}/metadata", [
                'json' => $metadata,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'ES-ExpectedVersion' => '-2',
                ],
            ]);
            return in_array($response->getStatusCode(), [201, 200]);
        } catch (RequestException $e) {
            $this->logError('setStreamMetadata', $streamName, $e);
            return false;
        }
    }

    /**
     * 建立持續性 Projection
     *
     * @param string $name  Projection 名稱
     * @param string $query JavaScript 查詢語法
     * @return bool
     */
    public function createProjection(string $name = 'OrderProcessingTimeProjection', string $query = ''): bool
    {
        if ($query === '') {
            $query = $this->getDefaultProjectionQuery();
        }

        try {
            $response = $this->httpClient->post("/projections/continuous/{$name}?emit=yes&trackemittedstreams=yes", [
                'body' => $query,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            return in_array($response->getStatusCode(), [201, 409]);
        } catch (RequestException $e) {
            $statusCode = $e->getResponse()?->getStatusCode();
            if ($statusCode === 409) {
                // Projection 已存在
                return true;
            }
            $this->logError('createProjection', $name, $e);
            return false;
        }
    }

    /**
     * 取得 Projection 的當前狀態
     *
     * @param string $name Projection 名稱
     * @return array|null
     */
    public function getProjectionState(string $name): ?array
    {
        try {
            $response = $this->httpClient->get("/projection/{$name}/state", [
                'headers' => ['Accept' => 'application/json'],
            ]);

            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody()->getContents(), true);
            }
        } catch (RequestException $e) {
            $this->logError('getProjectionState', $name, $e);
        }

        return null;
    }

    /**
     * 訂閱事件流（長輪詢）
     *
     * 適用於 EventStoreDB HTTP API 的 long-poll 訂閱。
     * 回傳最新事件，並提供 lastEventNumber 用於下次查詢。
     *
     * @param string $streamName
     * @param int    $lastEventNumber 上次讀取到的事件編號
     * @param int    $count           每次讀取的事件數量
     * @param int    $longPollSeconds 長輪詢超時秒數
     * @return array{events: array, lastEventNumber: int}
     */
    public function subscribe(string $streamName, int $lastEventNumber = -1, int $count = 10, int $longPollSeconds = 30): array
    {
        $start = $lastEventNumber + 1;
        try {
            $response = $this->httpClient->get("/streams/{$streamName}/{$start}/forward/{$count}", [
                'headers' => [
                    'Accept' => 'application/vnd.eventstore.atom+json',
                    'ES-LongPoll' => (string) $longPollSeconds,
                ],
                'timeout' => $longPollSeconds + 5,
            ]);

            if ($response->getStatusCode() === 200) {
                $body = json_decode($response->getBody()->getContents(), true);
                $events = $this->parseAtomEntries($body);
                $newLastEventNumber = $lastEventNumber;
                foreach ($events as $event) {
                    if (isset($event['eventNumber']) && $event['eventNumber'] > $newLastEventNumber) {
                        $newLastEventNumber = $event['eventNumber'];
                    }
                }
                return ['events' => $events, 'lastEventNumber' => $newLastEventNumber];
            }
        } catch (RequestException $e) {
            $this->logError('subscribe', $streamName, $e);
        }

        return ['events' => [], 'lastEventNumber' => $lastEventNumber];
    }

    /**
     * 解析 EventStoreDB Atom feed 格式的 entries
     *
     * @param array $body
     * @return array
     */
    private function parseAtomEntries(array $body): array
    {
        $entries = $body['entries'] ?? [];
        $events = [];

        foreach ($entries as $entry) {
            $event = [
                'eventId' => $entry['eventId'] ?? null,
                'eventType' => $entry['eventType'] ?? $entry['summary'] ?? null,
                'eventNumber' => $entry['eventNumber'] ?? $entry['positionEventNumber'] ?? null,
                'data' => isset($entry['data']) ? (is_string($entry['data']) ? json_decode($entry['data'], true) : $entry['data']) : null,
                'metadata' => isset($entry['metaData']) ? (is_string($entry['metaData']) ? json_decode($entry['metaData'], true) : $entry['metaData']) : null,
                'updated' => $entry['updated'] ?? null,
            ];
            $events[] = $event;
        }

        return $events;
    }

    /**
     * 預設的訂單處理時間 Projection 查詢
     */
    private function getDefaultProjectionQuery(): string
    {
        return <<<'JS'
fromStream("Streams")
.when({
    $init: function() { return {}; },
    "OrderCreatedEvent": function(state, event) {
        state[event.data.orderId] = {
            createdAt: event.metadata.timestamp,
            spiffe_id: event.metadata.spiffe_id
        };
    },
    "OrderSagaCompletedEvent": function(state, event) {
        if (state[event.data.orderId]) {
            var startTime = new Date(state[event.data.orderId].createdAt).getTime();
            var endTime = new Date(event.metadata.timestamp).getTime();
            var processingTime = (endTime - startTime) / 1000;
            emit("order_processing_times", "OrderProcessingTimeCalculated", {
                orderId: event.data.orderId,
                processingTime: processingTime,
                spiffe_chain: state[event.data.orderId].spiffe_id
            });
            delete state[event.data.orderId];
        }
    }
});
JS;
    }

    /**
     * 統一錯誤日誌輸出
     */
    private function logError(string $operation, string $target, RequestException $e): void
    {
        fwrite(STDERR, sprintf(
            "[event-store] %s failed on '%s': %s\n",
            $operation,
            $target,
            $e->getMessage(),
        ));
    }
}
