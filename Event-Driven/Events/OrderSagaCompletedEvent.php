<?php

namespace App\Events;

class OrderSagaCompletedEvent
{
    public string $orderId;
    public string $userKey;
    public int $total;
    public string $status;

    public function __construct(string $orderId, string $userKey, int $total, string $status = 'completed')
    {
        $this->orderId = $orderId;
        $this->userKey = $userKey;
        $this->total = $total;
        $this->status = $status;
    }
}
