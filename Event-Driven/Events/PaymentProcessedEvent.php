<?php

namespace App\Events;

class PaymentProcessedEvent
{
    public string $orderId;
    public bool $success; 
    
    public function __construct(string $orderId, bool $success)
    {
        $this->orderId = $orderId;
        $this->success = $success; 
    }
}
