<?php

namespace App\Events;

class OrderCreatedEvent
{
    public string $orderId;
    public string $userKey;
    public array $productList;
    public int $total;

    public function __construct(string $orderId, string $userKey, array $productList,int $total)
    {
        $this->orderId = $orderId;
        $this->userKey = $userKey;
        $this->productList = $productList;
        $this->total = $total;
    }
}