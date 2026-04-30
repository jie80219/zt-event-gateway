<?php

use Tests\Support\DatabaseTestCase;
use App\Models\v1\OrderModel;
use App\Entities\v1\OrderEntity;
use App\Models\v1\OrderProductModel;




class DtmOrderTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Extra code to run before each test
    }

    public function tearDown(): void
    {
        parent::tearDown();

        $this->db->table('order')->emptyTable('order');
        $this->db->table('order_product')->emptyTable('order_product');
    }

    /**
     * @test
     * 取得所有的訂單清單測試 
     * 
     * @return void
     */
    public function testIndex()
    {
        $productionData = array(
            [
                "name" => '123',
                "description" => '123',
                "price" => 5000,
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ],
            [
                "name" => '465',
                "description" => '456',
                "price" => 5000,
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ],
            [
                "name" => '789',
                "description" => '789',
                "price" => 5000,
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ]
        );

        $userKeyArray = [1, 2, 3];

        $oKeyArray = [];

        for($i=0;$i<6;$i++){

            $oKeyArray[$i] = sha1(serialize($productionData[$i%3]) . $userKeyArray[$i % 3] . uniqid());

            $data = [
                'o_key'      => sha1(serialize($productionData[$i%3]) . $userKeyArray[$i%3] . uniqid()),
                'u_key'      => $userKeyArray[0],
                'discount'   => random_int(500, 1000),
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ];

            $this->db->table("order")->insert($data);
        }

        // 參數測試

        $data = [
            "limit" => 3,
            'offset' => 0,
            'isDesc' => 'ASC',
            "u_key" => $userKeyArray[0]
        ];

        $results = $this->withBodyFormat('json')->post('api/vDtm/order/list', $data);
        if (!$results->isOK()) $results->assertStatus(404);
        $results->assertStatus(200);

        //取得resopnse資料並json decode decode解為Stdclasss
        $decodeResult = json_decode($results->getJSON());

        //將取得data->list的資料
        $resultStdGetList = $decodeResult->data->list;
        $resultStdGetAmount = $decodeResult->data->amount;

        //以相同參數取得DB結果   
        $productionModel = new OrderModel();
        $testQuery = $this->db->table('order')->select('u_key,discount,created_at as createdAt,updated_at as updatedAt')
                                     ->where('u_key', $userKeyArray[0])
                                     ->orderBy("created_at", $data['isDesc']);
        $testResultAmount = $testQuery->countAllResults(false);
        $testResult = $testQuery->get($data['limit'], $data['offset'])->getResult();

        //比較List是否相同
        $this->assertEquals($resultStdGetList, $testResult);

        //比較amount是否相同
        $this->assertEquals($resultStdGetAmount, $testResultAmount);

        //無其餘參數測試

        $notHasParamData = [
            "u_key" => $userKeyArray[0]
        ];

        $notHasParamResults = $this->withBodyFormat('json')->post('api/vDtm/order/list', $notHasParamData);

        if (!$notHasParamResults->isOK()) $notHasParamResults->assertStatus(404);
        $notHasParamResults->assertStatus(200);

        //取得resopnse資料並json decode
        $decodeNotHasParamResults = json_decode($notHasParamResults->getJSON());

        //將取得data->list的資料
        $notHasParamResultsStdGetList = $decodeNotHasParamResults->data->list;
        $notHasParamResultsStdGetAmount = $decodeNotHasParamResults->data->amount;

        //以相同參數取得DB結果   
        $testNotHasParamQuery = $this->db->table('order')
                                         ->select('u_key,discount,created_at as createdAt,updated_at as updatedAt');
        $testNotHasParamAmount = $testNotHasParamQuery->countAllResults(false);
        $testNotHasParamResult = $testNotHasParamQuery->get()->getResult();

        //比較List是否相同
        $this->assertEquals($notHasParamResultsStdGetList, $testNotHasParamResult);

        //比較amount是否相同
        $this->assertEquals($notHasParamResultsStdGetAmount, $testNotHasParamAmount);

        // 回傳無資料測試

        $failReturnData = [
            "u_key" => 4  //不存在的user
        ];

        $failReturnResults = $this->withBodyFormat('json')->post('api/vDtm/order/list', $failReturnData);

        $failReturnResponseData = json_decode($failReturnResults->getJSON());
        $failReturnResponseDataErrorMsg = $failReturnResponseData->messages->error;

        $this->assertEquals($failReturnResponseDataErrorMsg, "無資料");
      
    }

    /**
     * @test
     * 取得單一訂單資訊
     * 
     * @return void
     */
    public function testShow()
    {
        $productionData = array(
            [
                "name" => '123',
                "description" => '123',
                "price" => 5000,
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ],
            [
                "name" => '465',
                "description" => '456',
                "price" => 5000,
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ],
            [
                "name" => '789',
                "description" => '789',
                "price" => 5000,
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ]
        );

        $userKeyArray = [1, 2, 3];

        $oKeyArray = [];

        for ($i = 0; $i < 6; $i++) {

            /**
             * 使user個別產生2張訂單 
             * eg. u_key=>1 對應 $oKeyArray[0],$oKeyArray[3]   
             *     u_key=>2 對應 $oKeyArray[1],$oKeyArray[4]
             *     u_key=>3 對應 $oKeyArray[2],$oKeyArray[5]
            */

            $oKeyArray[$i] = sha1(serialize($productionData[$i % 3]) . $userKeyArray[$i % 3] . uniqid());
            
            $data = [
                'o_key'      => $oKeyArray[$i],
                'u_key'      => $userKeyArray[0],
                'discount'   => random_int(500, 1000),
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ];

            $this->db->table("order")->insert($data);

            $this->db->table("order_product")->insert([
                'o_key' => $oKeyArray[$i],
                'p_key' => $j=$i+1,
                'price' => random_int(0, 10000),
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ]);  
        }

        //無傳入o_key測試

        $notHasOkeyData = [
            "u_key" => $userKeyArray[0]
        ];
        
        $notHasOkeyResults = $this->withBodyFormat('json')->post('api/vDtm/order/show', $notHasOkeyData);

        $notHasOkeyResponseData =json_decode($notHasOkeyResults->getJSON());
        $notHasOkeyResponseDataErrorMsg = $notHasOkeyResponseData->messages->error;

        $this->assertEquals($notHasOkeyResponseDataErrorMsg, "無傳入訂單 key");
        $notHasOkeyResults->assertStatus(404);

        //無商品資料測試

        $product = [
            "name" => '不存在的資料',
            "description" => '不存在的資料',
            "price" => 5000,
            "created_at" => date("Y-m-d H:i:s"),
            "updated_at" => date("Y-m-d H:i:s")
        ];

        $existProductData = [
                "u_key" => $userKeyArray[0],
                "o_key" => sha1(serialize($product) . $userKeyArray[0] . uniqid())
        ];

        $existProductResults = $this->withBodyFormat('json')->post('api/vDtm/order/show', $existProductData);

        $existProductResponseData = json_decode($existProductResults->getJSON());
        $existProductResponseDataErrorMsg  = $existProductResponseData->messages->error;

        $this->assertEquals($existProductResponseDataErrorMsg, "無商品資料");
        $existProductResults->assertStatus(404);

        //回傳無資料測試

        $failReturnData = [
            "u_key" => 4,   //不存在的user
            "o_key" => $oKeyArray[2]   
        ];

        $failReturnResults = $this->withBodyFormat('json')->post('api/vDtm/order/show', $failReturnData);

        $failReturnResponseData = json_decode($failReturnResults->getJSON());
        $failReturnResponseDataErrorMsg = $failReturnResponseData->messages->error;

        $this->assertEquals($failReturnResponseDataErrorMsg, "無資料");
        $failReturnResults->assertStatus(404);

        //正確性測試

        $successReturnData = [
            "u_key" => $userKeyArray[0],   
            "o_key" => $oKeyArray[0]
        ];

        $successReturnResults = $this->withBodyFormat('json')->post('api/vDtm/order/show', $successReturnData);

        $successReturnResponseData = json_decode($successReturnResults->getJSON(), true);
        $successReturnResponseDataGet = $successReturnResponseData['data'];
        
        //產生測試結果
        $orderModel  = new OrderModel();
        $orderEntity = new OrderEntity();
        $orderProductModel = new OrderProductModel();

        $orderEntity = $orderModel->where("u_key", $userKeyArray[0])->find($oKeyArray[0]);

        $orderProductEntity = $orderProductModel->where('o_key', $oKeyArray[0])->find();

        $orderProdcutsArr = [];

        foreach ($orderProductEntity as $orderProdcuts) {
            $orderProdcut = [
                "p_key" => $orderProdcuts->p_key,
                "price" => $orderProdcuts->price
            ];
            $orderProdcutsArr[] = $orderProdcut;
        }

        $testData = [
            "o_key"     => $orderEntity->o_key,
            "u_key"     => $orderEntity->u_key,
            "discount"  => $orderEntity->discount,
            "products"  => $orderProdcutsArr,
            "createdAt" => $orderEntity->createdAt,
            "updatedAt" => $orderEntity->updatedAt
        ];

        $this->assertEquals($successReturnResponseDataGet, $testData);
        $failReturnResults->assertStatus(200);

    }

    /**
     * @test
     * 產生訂單
     * 
     * @return void
     */
    public function testCreate()
    {
        $productionData = array(
            [
                "p_key" => 1,
                "name" => '123',
                "description" => '123',
                "price" => 5000,
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ],
            [
                "p_key" => 2,
                "name" => '465',
                "description" => '456',
                "price" => 5000,
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ],
            [
                "p_key" => 3,
                "name" => '789',
                "description" => '789',
                "price" => 5000,
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ]
        );

        $userKeyArray = [1, 2,3];

        $oKeyArray = [];

        for ($i = 0; $i < 3; $i++) {

            /**
             * 使user個別產生訂單 
             * eg. u_key=>1 對應 $oKeyArray[0] 對應$productionData[0]
             *     u_key=>2 對應 $oKeyArray[1] 對應$productionData[1]
             *     u_key=>3 對應 $oKeyArray[2] 對應$productionData[2]
             */

            $oKeyArray[$i] = sha1(serialize($productionData[$i ]) . $userKeyArray[$i] . uniqid());

            $data = [
                'o_key'      => $oKeyArray[$i],
                'u_key'      => $userKeyArray[$i%3],
                'discount'   => random_int(500, 1000),
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ];

            $this->db->table("order")->insert($data);

            $this->db->table("order_product")->insert([
                'o_key' => $oKeyArray[$i],
                'p_key' =>  $productionData[$i]['p_key'],
                'price' => random_int(0, 10000),
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ]);
        }

        //輸入資料錯誤測試

        $failData =  [
            'u_key'=> $userKeyArray[0]
        ];
        
        $failResults = $this->withBodyFormat('json')->post('api/vDtm/order/create', $failData);
        $failResults->assertStatus(404);

        $failResultsData =json_decode($failResults->getJSON());
        $failResultsDataGetErrMsg = $failResultsData->messages->error;
        $this->assertEquals($failResultsDataGetErrMsg, "請確認輸入資料");

        //訂單key重複輸入測試

        $repeatData =  [
            'u_key' => $userKeyArray[0],
            'o_key' => $oKeyArray[0],
            'discount' => 20,
            'productDetailArr' => $productionData[0]
        ];
        
        $repeatResults = $this->withBodyFormat('json')->post('api/vDtm/order/create', $repeatData);
        $repeatResults->assertStatus(400);

        $repeatResultsData = json_decode($repeatResults->getJSON());
        $repeatResultsDataGetErrMsg = $repeatResultsData->messages->error;
        $this->assertEquals($repeatResultsDataGetErrMsg, "訂單 key 重複輸入");

        //正確結果測試   

        $successData =  [
            'u_key' => $userKeyArray[0], //1
            'o_key' =>  sha1(serialize($productionData[2]) . $userKeyArray[0] . uniqid()),
            'discount' => 20,
            'productDetailArr' => [$productionData[2]]   //需另用[]包裹
        ];
        $successResults = $this->withBodyFormat('json')->post('api/vDtm/order/create', $successData);
        $successResultsData = json_decode($successResults->getJSON());

        if($successResults->getStatus() == 400){
            $successResultsDataGetMsg = $successResultsData->msg;
            $this->assertEquals($successResultsDataGetMsg, "訂單新增失敗");

        }else{

            $successResultsDataGetMsg = $successResultsData->msg;
            $this->assertEquals($successResultsDataGetMsg, "OK");
            $successResults->assertStatus(200);

            //檢查是否新增

            $orderSeeingData = [
                'u_key' => $successData['u_key'],
                'o_key' => $successData['o_key'],
                'discount' => $successData['discount'],
            ];
            $this->seeInDatabase('order', $orderSeeingData);
        }
    }

    /**
     * @test
     * 更新訂單折扣
     * 
     * @return void
     */
    public function testUpdate()
    {
        $productionData = array(
            [
                "p_key" => 1,
                "name" => '123',
                "description" => '123',
                "price" => 5000,
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ],
            [
                "p_key" => 2,
                "name" => '465',
                "description" => '456',
                "price" => 5000,
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ],
            [
                "p_key" => 3,
                "name" => '789',
                "description" => '789',
                "price" => 5000,
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ]
        );

        $userKeyArray = [1, 2, 3];

        $oKeyArray = [];

        for ($i = 0; $i < 3; $i++) {

            /**
             * 使user個別產生訂單 
             * eg. u_key=>1 對應 $oKeyArray[0] 對應$productionData[0]
             *     u_key=>2 對應 $oKeyArray[1] 對應$productionData[1]
             *     u_key=>3 對應 $oKeyArray[2] 對應$productionData[2]
             */

            $oKeyArray[$i] = sha1(serialize($productionData[$i]) . $userKeyArray[$i] . uniqid());

            $data = [
                'o_key'      => $oKeyArray[$i],
                'u_key'      => $userKeyArray[$i % 3],
                'discount'   => random_int(500, 1000),
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ];

            $this->db->table("order")->insert($data);

            $this->db->table("order_product")->insert([
                'o_key' => $oKeyArray[$i],
                'p_key' =>  $productionData[$i]['p_key'],
                'price' => random_int(0, 10000),
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ]);
        }

        //無傳入訂單key測試

        $notHasOkeyData =  [
            'u_key' => $userKeyArray[0],
            'discount' => 20,
            'productDetailArr' => [$productionData[2]]   //需另用[]包裹
        ];

        $notHasOkeyResults = $this->withBodyFormat('json')->post('api/vDtm/order/update', $notHasOkeyData);
        $notHasOkeyResults->assertStatus(404);

        $notHasOkeyResultsData = json_decode($notHasOkeyResults->getJSON());
        $notHasOkeyResultsData = $notHasOkeyResultsData->messages->error;
        $this->assertEquals($notHasOkeyResultsData, "請傳入訂單 key");

        //輸入資料缺失測試

        $existData =  [
            'u_key' => $userKeyArray[0],
            'o_key' => $oKeyArray[0],
        ];

        $existDataResults = $this->withBodyFormat('json')->post('api/vDtm/order/update', $existData);
        $existDataResults->assertStatus(400);

        $existDataResultsData = json_decode($existDataResults->getJSON());
        $existDataResultsDataGetErrMsg = $existDataResultsData->messages->error;
        $this->assertEquals($existDataResultsDataGetErrMsg, "請傳入更改折扣或商品");

        //查無此商品測試

        $productExistData =  [
            'u_key' => $userKeyArray[0],
            'o_key' => sha1(serialize($productionData[2]) . $userKeyArray[0] . uniqid()),
            'discount' => 20,
            'productDetailArr' => [$productionData[2]]   //需另用[]包裹
        ];

        $productExistResults = $this->withBodyFormat('json')->post('api/vDtm/order/update', $productExistData);
        $productExistResults->assertStatus(404);

        $productExistResultsData = json_decode($productExistResults->getJSON());
        $productExistResultsDataGetErrMsg = $productExistResultsData->messages->error;
        $this->assertEquals($productExistResultsDataGetErrMsg, "查無此商品");

        //正確案例測試

        $successData =  [
            'u_key' => $userKeyArray[0],
            'o_key' => $oKeyArray[0],
            'discount' => 50,
            'productDetailArr' => [$productionData[0]]   //需另用[]包裹
        ];

        $successResults = $this->withBodyFormat('json')->post('api/vDtm/order/update', $successData);
        $successResultsData = json_decode($successResults->getJSON());

        if ($successResults->getStatus() == 400) {

            $successResultsDataGetErrMsg = $successResultsData->messages->error;
            $this->assertEquals($successResultsDataGetErrMsg, "更新失敗");
        } else {

            $successResults->assertStatus(200);
            $successResultsDataGetErrMsg = $successResultsData->msg;
            $this->assertEquals($successResultsDataGetErrMsg, "OK");

            //檢查是否更新

            $orderSeeingData = [
                'u_key' => $userKeyArray[0],
                'o_key' => $successData['o_key'],
                'discount' => $successData['discount'],
            ];
            $this->seeInDatabase('order', $orderSeeingData);
        }

    }

    /**
     * @test
     * 刪除訂單測試
     * 
     * @return void
     */
    public function testDelete()
    {

        $productionData = array(
            [
                "p_key" => 1,
                "name" => '123',
                "description" => '123',
                "price" => 5000,
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ],
            [
                "p_key" => 2,
                "name" => '465',
                "description" => '456',
                "price" => 5000,
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ],
            [
                "p_key" => 3,
                "name" => '789',
                "description" => '789',
                "price" => 5000,
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ]
        );

        $userKeyArray = [1, 2, 3];

        $oKeyArray = [];

        for ($i = 0; $i < 3; $i++) {

            /**
             * 使user個別產生訂單 
             * eg. u_key=>1 對應 $oKeyArray[0] 對應$productionData[0]
             *     u_key=>2 對應 $oKeyArray[1] 對應$productionData[1]
             *     u_key=>3 對應 $oKeyArray[2] 對應$productionData[2]
             */

            $oKeyArray[$i] = sha1(serialize($productionData[$i]) . $userKeyArray[$i] . uniqid());

            $data = [
                'o_key'      => $oKeyArray[$i],
                'u_key'      => $userKeyArray[$i % 3],
                'discount'   => random_int(500, 1000),
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ];

            $this->db->table("order")->insert($data);

            $this->db->table("order_product")->insert([
                'o_key' => $oKeyArray[$i],
                'p_key' =>  $productionData[$i]['p_key'],
                'price' => random_int(0, 10000),
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ]);
        }

        //未輸入o_key測試

        $notHasOkeyData =  [
            'u_key' => $userKeyArray[0],
        ];

        $notHasOkeyResults = $this->withBodyFormat('json')->post('api/vDtm/order/delete', $notHasOkeyData);
        $notHasOkeyResults->assertStatus(400);

        $notHasOkeyResultsData = json_decode($notHasOkeyResults->getJSON());

        $notHasOkeyResultsDataGetErrMsg = $notHasOkeyResultsData->messages->error;
        $this->assertEquals($notHasOkeyResultsDataGetErrMsg, "請輸入訂單 key");;

        //正確案例測試

        $successData =  [
            'u_key' => $userKeyArray[0],
            'o_key' => $oKeyArray[0]
        ];

        $successResults = $this->withBodyFormat('json')->post('api/vDtm/order/delete', $successData);
        $successResultsData = json_decode($successResults->getJSON());

        if ($successResults->getStatus() == 400) {

            $successResultsDataGetErrMsg = $successResultsData->messages->error;
            $this->assertEquals($successResultsDataGetErrMsg, "刪除訂單失敗");
        } else {

            $successResults->assertStatus(200);
            $successResultsDataGetErrMsg = $successResultsData->msg;
            $this->assertEquals($successResultsDataGetErrMsg, "OK");

            //確認資料已刪除
            $deleteCheckResult = $this->grabFromDatabase('order_product', 'deleted_at', ['o_key' => $oKeyArray[0]]);
            $this->assertTrue(!is_null($deleteCheckResult));
        }
    }
}
