<?php

namespace App\Controllers\v1;

use CodeIgniter\API\ResponseTrait;

use App\Controllers\v1\BaseController;
use App\Models\v1\InventoryModel;
use App\Models\v1\BusinessLogic\HistoryBusinessLogic;
use App\Models\v1\BusinessLogic\InventoryBusinessLogic;

class InventoryController extends BaseController
{
    use ResponseTrait;

    /**
     * [POST] /api/v1/inventory/reduceInventory
     * 減少庫存
     *
     */
    public function reduceInventory()
    {
        $p_key        = $this->request->getPost("p_key");
        $o_key        = $this->request->getPost("o_key");
        $reduceAmount = $this->request->getPost("reduceAmount");
        $type         = "create";
        
        if(is_null($p_key) || is_null($o_key) || is_null($reduceAmount) || is_null($type)) return $this->fail("請確認傳入值是否完整", 404);

        $productionResult = InventoryBusinessLogic::verfiyProductKey($p_key);
        if(is_null($productionResult)) return $this->fail("查無此商品 key",404);

        $verifyTypeResult = HistoryBusinessLogic::verfiyType($p_key,$o_key,$type);
        $verifyCompensateResult = HistoryBusinessLogic::verfiyType($p_key,$o_key,'compensate');
        if($verifyTypeResult && !$verifyCompensateResult){
            return $this->respond([
                "msg" => "OK"
            ]);
        }
        
        $inventoryModel = new InventoryModel();
        
        $inventoryEntity = $inventoryModel->find($p_key);
        
        if(is_null($inventoryEntity)){
            return $this->fail("找不到庫存資料", 404);
        }

        if($inventoryEntity->amount < $reduceAmount){
            return $this->fail("庫存數量不夠", 400);
        }

        $inventoryCreatedResult = $inventoryModel->reduceInventoryTranscation($p_key, $o_key, $reduceAmount, $inventoryEntity->amount);
        if (is_null($inventoryCreatedResult)) return $this->fail("庫存或流水帳新增失敗", 400);

        return $this->respond([
            "msg" => "OK"
        ]);
    }

    /**
     * [POST] /api/v1/inventory/addInventory
     * 庫存補償
     * 
     */
    public function addInventory()
    {
        $p_key     = $this->request->getPost("p_key");
        $o_key     = $this->request->getPost("o_key");
        $addAmount = $this->request->getPost("addAmount");
        $type      = $this->request->getPost("type");

        if(is_null($p_key) || is_null($o_key) || is_null($addAmount) || is_null($type)) return $this->fail("請確認傳入值是否完整", 404);

        $productionResult = InventoryBusinessLogic::verfiyProductKey($p_key);
        if(is_null($productionResult)) return $this->fail("查無此商品 key", 404);

        $verfiyTypeResult = HistoryBusinessLogic::verfiyType($p_key,$o_key,$type);
        if($verfiyTypeResult){
            return $this->respond([
                "msg" => "OK"
            ]);    
        }

        if($type == "compensate"){
            $verfiyCompensateResult = HistoryBusinessLogic::verfiyType($p_key,$o_key, 'create');
            if(is_null($verfiyCompensateResult)){
                return $this->respond([
                    "msg" => "這個訂單的庫存扣款未被成立"
                ]);
            }
        }

        $inventoryModel = new InventoryModel();
        $inventoryEntity = $inventoryModel->find($p_key);
        
        if($inventoryEntity){
            $nowAmount = $inventoryEntity->amount;
        }else{
            return $this->fail("查無此訂單庫存", 404);
        }

        $inventoryCreatedResult = $inventoryModel->addInventoryTranscation($p_key,$o_key,$addAmount,$nowAmount,$type);
        if(!$inventoryCreatedResult) return $this->fail("庫存或流水帳新增失敗", 400);

        return $this->respond([
            "msg" => "OK"
        ]);
    }

}
