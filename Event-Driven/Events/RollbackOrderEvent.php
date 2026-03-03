<?php
namespace App\Events;

class RollbackOrderEvent
{
    public string $orderId;
    public string $userKey;

    public function __construct(string $orderId,string $userKey)
    {
        $this->orderId = $orderId;
        $this->userKey = $userKey;
    }
}
