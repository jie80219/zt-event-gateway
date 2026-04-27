<?php

namespace App\Controllers\Dtm;

use CodeIgniter\API\ResponseTrait;

use App\Controllers\Dtm\BaseController;
use App\Models\v1\InventoryModel;
use App\Models\v1\BusinessLogic\HistoryBusinessLogic;
use App\Models\v1\BusinessLogic\InventoryBusinessLogic;

class InventoryController extends BaseController
{
    use ResponseTrait;

    /**
     * [POST] /api/vDtm/inventory/addInventory
     * 增加庫存
     * 
     * @return void
     */
    public function addInventory()
    {
        $data = $this->request->getJSON(true);

        $p_key     = $data["p_key"]  ?? null;
        $o_key     = $data["o_key"]  ?? null;
        $addAmount = $data["amount"] ?? null;
        $type      = $data["type"]   ?? null;

        if(is_null($p_key) || is_null($o_key) || is_null($addAmount) || is_null($type)) return $this->fail("請確認傳入值是否完整",404);

        $productionResult = InventoryBusinessLogic::verfiyProductKey($p_key);
        if(is_null($productionResult)) return $this->fail("查無此商品 key",404);

        $verfiyTypeResult = HistoryBusinessLogic::verfiyType($p_key,$o_key,$type);
        if($verfiyTypeResult) return $this->fail("訂單編號與類別重複，可能為重複輸入",400);

        $verfiyCreatedResult = HistoryBusinessLogic::verfiyCreated($o_key);
        if(is_null($verfiyCreatedResult)) {
            return $this->respond([
                "msg" => "OK"
            ]);
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

    /**
     * [POST] /api/vDtm/inventory/reduceInventory
     * 減少庫存
     *
     * @return void
     */

    public function reduceInventory()
    {
        $data = $this->request->getJSON(true);

        $p_key        = $data["p_key"]  ?? null;
        $o_key        = $data["o_key"]  ?? null;
        $reduceAmount = $data["amount"] ?? null;
        $type         = "create";
        
        if(is_null($p_key) || is_null($o_key) || is_null($reduceAmount) || is_null($type)) return $this->fail("請確認傳入值是否完整",404);

        $productionResult = InventoryBusinessLogic::verfiyProductKey($p_key);
        if(is_null($productionResult)) return $this->fail("查無此商品 key",404);

        $verfiyTypeResult = HistoryBusinessLogic::verfiyType($p_key,$o_key,$type);
        if($verfiyTypeResult) return $this->fail("訂單編號與類別重複，可能為重複輸入",400);
        
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
     * [DELETE] /api/vDtm/inventory/detete
     * 刪除庫存
     * 
     * @return void
     */
    public function delete()
    {
        $data = $this->request->getJSON(true);

        $productKey = $data["p_key"] ?? null;

        if(is_null($productKey)) return $this->fail("請輸入刪除庫存 key",404);

        $productionResult = InventoryBusinessLogic::verfiyProductKey($productKey);
        if (is_null($productionResult)) return $this->fail("查無此商品 key", 404);

        $inventoryModel = new InventoryModel();

        $result = $inventoryModel->delete($productKey);
        
        return $this->respond([
            "msg" => "OK",
            "res" => $result
        ]);
    }
}
