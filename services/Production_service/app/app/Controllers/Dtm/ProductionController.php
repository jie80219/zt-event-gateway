<?php

namespace App\Controllers\Dtm;

use CodeIgniter\API\ResponseTrait;

use App\Controllers\Dtm\BaseController;
use App\Entities\v1\ProductionEntity;
use App\Models\v1\ProductionModel;

class ProductionController extends BaseController
{
    use ResponseTrait;

    /**
     * [GET] /api/vDtm/products/list
     * 取得所有的產品清單
     *
     * @return void
     */
    public function index()
    {
        $data   = $this->request->getJSON(true);
        
        $limit  = $data["limit"]  ?? 10;
        $offset = $data["offset"] ?? 0;
        $search = $data["search"] ?? "";
        $isDesc = $data["isDesc"] ?? "desc";

        $productionEntity = new ProductionEntity();
        $productionModel  = new ProductionModel();

        $query = $productionModel->orderBy("created_at",$isDesc ? "DESC" : "ASC");
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
                    "name"        => $productionEntity->name,
                    "description" => $productionEntity->description,
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
            "msg"  => "OK",
            "data" => $data
        ]);
    }

    /**
     * [GET] /api/vDtm/products/show
     * 取得單一商品
     *
     * @return void
     */
    public function show()
    {
        $data = $this->request->getJSON(true);

        $productKey = $data["p_key"] ?? null;

        if(is_null($productKey)) return $this->fail("無資料",404);

        $productionEntity = new ProductionEntity();
        $productionModel  = new ProductionModel();

        $productionEntity = $productionModel->find($productKey);

        if($productionEntity){
            $data = [
                "p_key"       => $productionEntity->p_key,
                "name"        => $productionEntity->name,
                "description" => $productionEntity->description,
                "price"       => $productionEntity->price,
                "createdAt"   => $productionEntity->createdAt,
                "updatedAt"   => $productionEntity->updatedAt
            ];
        }else{
            return $this->fail("無資料",404);
        }

        return $this->respond([
            "msg"  => "OK",
            "data" => $data
        ]);
    }

    /**
     * [POST] /api/vDtm/products/create
     * 建立產品
     *
     * @return void
     */
    public function create()
    {
        $data = $this->request->getJSON(true);

        $name        = $data["name"] ?? null;
        $description = $data["description"] ?? null;
        $price       = $data["price"] ?? null;
        $amount      = $data["amount"] ?? null;

        if(is_null($name) || is_null($description) || is_null($price) || is_null($amount)) return $this->fail("傳入資料錯誤", 400);

        $productionModel = new ProductionModel();

        $productInsertResult = $productionModel->createProductionTranscation($name, $description, $price,$amount);

        if($productInsertResult){
            return $this->respond([
                        "msg" => "OK",
                        "res" => $productInsertResult
                    ]);
        }else{
            return $this->fail("新增商品或新增庫存失敗",400);
        }
    }

    /**
     * [PUT] /api/vDtm/products/update
     * 更新產品資訊
     * 
     * @return void
     */
    public function update()
    {
        $data = $this->request->getJSON(true);

        $p_key       = $data["p_key"]       ?? null;
        $name        = $data["name"]        ?? null;
        $description = $data["description"] ?? null;
        $price       = $data["price"]       ?? null;

        $productionEntity = new ProductionEntity();
        $productionModel  = new ProductionModel();
        
        if(is_null($p_key)) return $this->fail("請傳入產品key",400);
        if(is_null($name) && is_null($description) && is_null($price)) return $this->fail("請傳入要更改的資料",404);

        $productionEntity = $productionModel->find($p_key);
        if(is_null($productionEntity)) return $this->fail("查無此商品",404);

        $productionEntity->p_key = $p_key;
        if(!is_null($name))        $productionEntity->name = $name;
        if(!is_null($description)) $productionEntity->description = $description;
        if(!is_null($price))       $productionEntity->price = $price;

        $productionModel->where('p_key',$productionEntity->p_key)
                        ->save($productionEntity);
        return $this->respond([
            "msg" => "OK"
        ]);

    }

    /**
     * [DELETE] /api/vDtm/products/delete
     * 刪除產品
     *
     * @param int $productKey
     * @return void
     */
    public function delete()
    {
        $data = $this->request->getJSON(true);

        $productKey = $data["p_key"] ?? null;
        if(is_null($productKey)) return $this->fail("請傳入產品key",400);

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