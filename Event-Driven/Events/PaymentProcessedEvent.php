<?php

namespace App\Events;

class PaymentProcessedEvent
{
    public string $orderId;
    public string $userKey;
    public int $total;
    public array $productList;
    public bool $success;

    public function __construct(
        string $orderId,
        bool $success,
        string $userKey = '',
        int $total = 0,
        array $productList = [],
    ) {
        $this->orderId = $orderId;
        $this->success = $success;
        $this->userKey = $userKey;
        $this->total = $total;
        $this->productList = $productList;
    }
}
