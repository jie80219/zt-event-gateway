<?php
namespace App\Events;

class OrderCreateRequestedEvent
{
    public array $productList;
    public array $orderData;
    private ?string $traceId;

    public function __construct(array $orderData, ?string $traceId = null)
    {
        $this->orderData = $orderData;
        $this->traceId = $traceId;

        $productList = $orderData['product_list'] ?? $orderData['productList'] ?? $orderData;
        $this->productList = array_values(is_array($productList) ? $productList : []);
    }

    public function getData(): array
    {
        return $this->orderData;
    }

    public function getTraceId(): ?string
    {
        return $this->traceId;
    }
}
