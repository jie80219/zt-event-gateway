<?php

namespace App\Models\v1\BusinessLogic;

use App\Models\v1\OrderModel;
use App\Entities\v1\OrderEntity;

class OrderBusinessLogic
{
    /**
     * 取得商品資訊
     *
     * @param string $orderKey
     * @return OrderEntity|null
     */
    static function getOrder(string $orderKey): ?OrderEntity
    {
        $orderModel = new OrderModel();

        $orderEntity = $orderModel->find($orderKey);

        return $orderEntity;
    }

}
