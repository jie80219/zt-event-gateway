<?php
namespace Services;

use SDPMlab\Anser\Service\ActionInterface;

class InventoryService extends ProductionService
{
    /**
     * Alias for reducing inventory to match saga terminology.
     */
    public function deductAction(int $productId, string $orderId, int $amount): ActionInterface
    {
        return $this->reduceInventory($productId, $orderId, $amount);
    }

    /**
     * Alias for compensating inventory deductions.
     */
    public function restoreAction(int $productId, string $orderId, int $amount): ActionInterface
    {
        return $this->addInventoryCompensateAction($productId, $orderId, $amount);
    }
}
