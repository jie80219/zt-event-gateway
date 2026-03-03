<?php
namespace App\Events;

class RollbackInventoryEvent
{
    public string $orderId;
    public string $userKey;
    public array $successfulDeductions;

    public function __construct(string $orderId, string $userKey,array $successfulDeductions)
    {
        $this->orderId = $orderId;
        $this->userKey = $userKey;
        $this->successfulDeductions = $successfulDeductions;
    }
}
