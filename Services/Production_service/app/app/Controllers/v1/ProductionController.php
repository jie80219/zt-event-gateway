<?php

namespace App\Controllers\v1;

use App\Models\v1\InventoryModel;
use CodeIgniter\API\ResponseTrait;

use App\Controllers\v1\BaseController;
use App\Entities\v1\ProductionEntity;
use App\Models\v1\ProductionModel;

class ProductionController extends BaseController
{
    use ResponseTrait;

    /**
     * [GET] /api/v1/products
     * 取得所有的產品清單
     *
     */
    public function index()
    {
        $limit  = $this->request->getGet("limit") ?? 10;
        $offset = $this->request->getGet("offset") ?? 0;
        $search = $this->request->getGet("search") ?? "";
        $isDesc = $this->request->getGet("isDesc") ?? "desc";

        $productionEntity = new ProductionEntity();
        $productionModel  = new ProductionModel();

        $query = $productionModel->orderBy("p_key",$isDesc ? "DESC" : "ASC");
        if($search !== "") $query->like("name",$search);
        $amount = $query->countAllResults(false);
        $production = $query->findAll($limit,$offset);

        $data = [
            "list"   => [],
            "amount" => $amount
        ];

        if($production){
            foreach ($production as $productionEntity) {
                $productionData = [
                    "id"          => $productionEntity->p_key,
                    "name"        => $productionEntity->name,
                    "price"       => $productionEntity->price,
                    "createdAt"   => $productionEntity->createdAt,
                    "updatedAt"   => $productionEntity->updatedAt
                ];
                $data["list"][] = $productionData;
            }
        }else{
            return $this->fail("無資料",404);
        }
        

        return $this->respond([
            "msg" => "OK",
            "data" => $data
        ]);
    }

    /**
     * [GET] /api/v1/products/{productionKey}
     * 取得單一商品
     *
     */
    public function show($productKey = null)
    {
        if(is_null($productKey)) return $this->fail("無資料",404);

        $productionEntity = new ProductionEntity();
        $productionModel  = new ProductionModel();

        $productionEntity = $productionModel->find($productKey);
        $inventoryModel = new InventoryModel();
        $inventoryEntity = $inventoryModel->find($productionEntity->p_key);

        if($productionEntity){
            $data = [
                "p_key"       => $productionEntity->p_key,
                "name"        => $productionEntity->name,
                "description" => $productionEntity->description,
                "price"       => $productionEntity->price,
                "amount"      => $inventoryEntity->amount,
                "createdAt"   => $productionEntity->createdAt,
                "updatedAt"   => $productionEntity->updatedAt
            ];
        }else{
            return $this->fail("無資料",404);
        }

        return $this->respond([
            "msg" => "OK",
            "data" => $data
        ]);
    }

    /**
     * [POST] /api/v1/products form-data 方式傳入
     * 建立產品
     *
     */
    public function create()
    {
        $name        = $this->request->getPost("name");
        $description = $this->request->getPost("description");
        $price       = $this->request->getPost("price");
        $amount      = $this->request->getPost("amount");

        if(is_null($name) || is_null($description) || is_null($price) || is_null($amount)) return $this->fail("傳入資料錯誤", 400);

        $productionModel = new ProductionModel();

        $productInsertResult = $productionModel->createProductionTranscation($name, $description, $price,$amount);

        if($productInsertResult){
            return $this->respond([
                        "msg" => "OK",
                        "product_id" => $productInsertResult
                    ]);
        }else{
            return $this->fail("新增商品或新增庫存失敗",400);
        }
    }

    /**
     * [PUT] /api/v1/products/{p_key} Json 格式傳入
     * 更新產品資訊
     * 
     */
    public function update($p_key = null)
    {
        $data = $this->request->getJSON(true);

        $name         = $data["name"]         ?? null;
        $description  = $data["description"]  ?? null;
        $price        = $data["price"]        ?? null;

        $productionEntity = new ProductionEntity();
        $productionModel  = new ProductionModel();
        
        if(is_null($p_key)) return $this->fail("請傳入產品key",404);
        if(is_null($name) && is_null($description) && is_null($price)) return $this->fail("請傳入更改資料",404);

        $productionEntity = $productionModel->find($p_key);
        if (is_null($productionEntity)) return $this->fail("查無此商品", 404);

        $productionEntity->p_key = $p_key;
        if(!is_null($name))        $productionEntity->name = $name;
        if(!is_null($description)) $productionEntity->description = $description;
        if(!is_null($price))       $productionEntity->price = $price;

        if($productionEntity->hasChanged() == false){
            return $this->fail([
                "error" => "沒有任何資料被更改。"
            ], 400);
        }
        
        $productionModel->where('p_key',$productionEntity->p_key)
                        ->save($productionEntity);
        return $this->respond([
            "msg" => "OK"
        ]);

    }

    /**
     * [DELETE] /api/v1/products/{productKey}
     * 刪除產品
     *
     * @param int $productKey
     */
    public function delete($productKey = null)
    {
        if(is_null($productKey)) return $this->fail("請傳入產品key",404);

        $productionModel = new ProductionModel();

        $productionEntity = $productionModel->find($productKey);
        if (is_null($productionEntity)) return $this->fail("查無此商品", 404);
        
        $result = $productionModel->delete($productKey);

        return $this->respond([
            "msg" => "OK",
            "res" => $result
        ]);
    }
}