<?php
namespace App\Events;

class RollbackInventoryEvent
{
    public string $orderId;
    public string $userKey;
    public array $successfulDeductions;
    public bool $paymentCompleted;
    public int $total;

    public function __construct(
        string $orderId,
        string $userKey,
        array $successfulDeductions,
        bool $paymentCompleted = false,
        int $total = 0
    ) {
        $this->orderId = $orderId;
        $this->userKey = $userKey;
        $this->successfulDeductions = $successfulDeductions;
        $this->paymentCompleted = $paymentCompleted;
        $this->total = $total;
    }
}
