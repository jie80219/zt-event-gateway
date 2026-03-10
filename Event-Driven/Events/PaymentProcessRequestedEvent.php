<?php

namespace App\Events;

class PaymentProcessRequestedEvent
{
    public string $orderId;
    public string $userKey;
    public int $total;

    public function __construct(string $orderId, string $userKey, int $total)
    {
        $this->orderId = $orderId;
        $this->userKey = $userKey;
        $this->total = $total;
    }
}
