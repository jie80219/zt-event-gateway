<?php
namespace SDPMlab\AnserEDA\EventStore;

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
     * ✅ **儲存事件**
     * 
     * @param string $streamName - 事件流名稱 (類似表名)
     * @param array $eventData - 事件內容 (要存入的數據)
     * @return bool
     */
    public function appendEvent(string $streamName, array $eventData): bool
    {
        $eventId = Uuid::uuid4()->toString();
        
        $timestamp = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z'); // 設定 ISO 8601 時間戳記

        $payload = [
            'eventId' => $eventId,
            'eventType' => $eventData['eventType'],
            'data' => $eventData['data'],
            'metadata' => [
                'timestamp' => $timestamp
            ]
        ];
        
        try {
            $response = $this->httpClient->post("{$this->eventStoreUrl}/streams/{$streamName}", [
                'json' => [$payload],
                'headers' => [
                    'Content-Type' => 'application/vnd.eventstore.events+json',
                    'Accept' => 'application/json',
                    'ES-EventType' => $eventData['eventType'],
                    'ES-EventId' => $eventId,
                    'ES-ExpectedVersion' => '-2'
                ]
            ]);
    
            if ($response->getStatusCode() === 201) {
                //echo "✅ 事件成功寫入 EventStoreDB！\n";
                //echo "🔹 狀態碼：" . $response->getStatusCode() . "\n";
                //echo "🔹 事件內容：" . json_encode($payload, JSON_PRETTY_PRINT) . "\n";
                return true;
            }
        } catch (RequestException $e) {
            echo "❌ 發送事件失敗：" . $e->getMessage() . "\n";
            return false;
        }
        return false;
    }
    

    /**
     * ✅ **讀取事件**
     * 
     * @param string $streamName - 事件流名稱
     * @return array - 讀取到的事件陣列
     */
    public function readEvents(string $streamName): array
    {
        try {
            $response = $this->httpClient->get("{$this->eventStoreUrl}/streams/{$streamName}", [
                'headers' => ['Accept' => 'application/json']
            ]);

            if ($response->getStatusCode() === 200) {
                $events = json_decode($response->getBody()->getContents(), true);
                return $events;
            }
        } catch (RequestException $e) {
            echo "❌ 讀取事件失敗：" . $e->getMessage() . "\n";
        }

        return [];
    }

    /**
     * ✅ **讀取最新事件**
     *
     * @param string $streamName
     * @return array|null
     */
    public function readLastEvent(string $streamName): ?array
    {
        $events = $this->readEvents($streamName);
        return $events ? end($events) : null;
    }

    public function createProjection()
    {
        $projectionQuery = '
            fromStream("order_events")
            .when({
                "App\\\\Events\\\\OrderCreatedEvent": function(state, event) {
                    state[event.data.orderId] = { createdAt: event.metadata.timestamp };
                },
                "App\\\\Events\\\\OrderCompletedEvent": function(state, event) {
                    if (state[event.data.orderId]) {
                        let startTime = new Date(state[event.data.orderId].createdAt).getTime();
                        let endTime = new Date(event.metadata.timestamp).getTime();
                        let processingTime = (endTime - startTime) / 1000;
                        emit("order_processing_times", "OrderProcessingTimeCalculated", {
                            orderId: event.data.orderId,
                            processingTime: processingTime
                        });
                    }
                }
            });
        ';
    
        try {
            $response = $this->httpClient->post("{$this->eventStoreUrl}/projections/continuous/OrderProcessingTimeProjection", [
                'json' => [
                    'name' => 'OrderProcessingTimeProjection',
                    'query' => $projectionQuery,
                    'mode' => 'continuous'
                ]
            ]);
    
            if ($response->getStatusCode() === 201 || $response->getStatusCode() === 409) {
                echo "✅ Projection 已建立或已存在\n";
                return true;
            }
        } catch (RequestException $e) {
            echo "❌ 創建 Projection 失敗：" . $e->getMessage() . "\n";
        }
    
        return false;
    }

}
