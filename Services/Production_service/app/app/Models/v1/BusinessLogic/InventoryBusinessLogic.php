<?php

namespace App\Models\v1\BusinessLogic;

use App\Models\v1\ProductionModel;
use App\Entities\v1\ProductionEntity;

class InventoryBusinessLogic
{
    /**
     * 驗證商品 key 是否存在
     *
     * @param integer $p_key
     * @return ProductionEntity | null
     */
    static function verfiyProductKey(int $p_key): ?ProductionEntity
    {
        $productionEntity = new ProductionEntity();
        $productionModel  = new ProductionModel();

        $productionEntity = $productionModel->find($p_key);

        return $productionEntity;
    }

}
