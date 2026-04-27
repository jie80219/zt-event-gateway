<?php

use Tests\Support\DatabaseTestCase;
use App\Models\v1\ProductionModel;

class ProductionTest extends  DatabaseTestCase
{
	public function setUp(): void
	{
		parent::setUp();
	}

	public function tearDown(): void
	{
		parent::tearDown();

		$this->db->table('production')->emptyTable('production');
		$this->db->table('inventory')->emptyTable('inventory');
		$this->db->table('history')->emptyTable('history');

		//reset AUTO_INCREMENT
		$queryTable = ['production', 'inventory', 'history'];
		foreach ($queryTable  as $tableName) {
			$this->db->query("ALTER TABLE " . $tableName . " AUTO_INCREMENT = 1");
		}
	}

	/**
	 * @test
	 * 
	 * 查詢所有商品
	 * 
	 * @return void
	 */
	public function testIndex()
	{

		$productionData  = array(
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
				"name" => '125',
				"description" => '789',
				"price" => 5000,
				"created_at" => date("Y-m-d H:i:s"),
				"updated_at" => date("Y-m-d H:i:s")
			]
		);

		$this->db->table("production")->insertBatch($productionData);

		//url帶有get參數

		$limit = 20;
		$search = "12";
		$offset = 1;
		$isDesc = "ASC";

		$results = $this->get("api/v1/products?limit={$limit}&search={$search}&offset={$offset}&isDesc={$isDesc}");
		if (!$results->isOK()) $results->assertStatus(404);
		$results->assertStatus(200);

		//取得resopnse資料並json decode解為Stdclass
		$decodeResult = json_decode($results->getJSON());
	
		//將取得data->list的資料
		$resultStdGetList = $decodeResult->data->list;
		$resultStdGetAmount = $decodeResult->data->amount;

		//以相同參數取得DB結果  typeof Stdclass 
		$productionModel = new ProductionModel();
		$testQuery = $productionModel->select('name,description,price,created_at as createdAt,updated_at as updatedAt')
									 ->orderBy("created_at", $isDesc)
									 ->like("name", $search);
		$testResultAmount = $testQuery->countAllResults(false);				
		$testResult = $testQuery->get($limit, $offset)->getResult();
	
		//比較List是否相同
		$this->assertEquals($resultStdGetList, $testResult);

		//比較amount是否相同
		$this->assertEquals($resultStdGetAmount, $testResultAmount);

		//url未帶參數
		$notHasParamResults = $this->get("api/v1/products");
		if (!$notHasParamResults->isOK()) $results->assertStatus(404);
		$notHasParamResults->assertStatus(200);

		//取得resopnse資料並json decode
		$decodeNotHasParamResults = json_decode($notHasParamResults->getJSON());

		//將取得data->list的資料
		$notHasParamResultsStdGetList = $decodeNotHasParamResults->data->list;
		$notHasParamResultsStdGetAmount =$decodeNotHasParamResults->data->amount;

		//以相同參數取得DB結果   
		$testNotHasParamQuery = $this->db->table('production')
										 ->select('name,description,price,created_at as createdAt,updated_at as updatedAt');
		$testNotHasParamAmount = $testNotHasParamQuery->countAllResults(false);
		$testNotHasParamResult = $testNotHasParamQuery->get()->getResult();
	
		//比較List是否相同
		$this->assertEquals($notHasParamResultsStdGetList, $testNotHasParamResult);

		//比較amount是否相同
		$this->assertEquals($notHasParamResultsStdGetAmount, $testNotHasParamAmount);
	}

	/**
	 * @test
	 * 
	 * 取得單一產品
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

		$insertIDArray = [];

		for($i=0 ; $i<3 ; $i++){

			$this->db->table('production')->insert($productionData[$i]);
			$inserIDByThisProduct = $this->db->insertID();
			$insertIDArray[$i] = $inserIDByThisProduct;
		}

		//產品key不存在  999為不存在的key
		$keyExistResults = $this->get('api/v1/products/999');
		$keyExistResults->assertStatus(404);

		//產品key存在  use 第一筆insert ID
		$results = $this->get('api/v1/products/'.$insertIDArray[0]);
		$results->assertStatus(200);
	}

	/**
	 * @test
	 * 
	 * 建立商品
	 * 
	 * @return void
	 */
	public function testCreate()
	{

		//無輸入資料 
		$dataExistResults = $this->post('api/v1/products', []);
		$dataExistResults->assertStatus(400);

		//正確輸入測試 
		$data = [
			"name" => "iphone 15",
			"description" => "smart phone",
			"price" => 32000,
			"amount" => 25
		];

		$results = $this->post('api/v1/products', $data);
		if (!$results->isOK()) $results->assertStatus(400);
		$results->assertStatus(200);

		$inventoryAssertData = [
			"p_key" => 1,
			"amount" => $data['amount']
		];

		//檢查資料是否成功新增 in inventory table
		$this->seeInDatabase("inventory", $inventoryAssertData);

		$productsAssertData = [
			"p_key" => 1,
			"name" => "iphone 15",
			"description" => "smart phone",
			"price" => 32000,
		];

		//檢查資料是否成功新增 in production table
		$this->seeInDatabase("production", $productsAssertData);
	}


	/**
	 * @test
	 * 
	 * 更新商品資訊
	 * 
	 * @return void
	 */
	public function testUpdate()
	{

		$productionData = [
			"name" => " iphone 15 ",
			"description" => "smart phone !!! 2022",
			"price" => 30,
			"created_at" => date("Y-m-d H:i:s"),
			"updated_at" => date("Y-m-d H:i:s")
		];

		$this->db->table("production")->insert($productionData);
		$insertID = $this->db->insertID();
		
		//資料無包含key 
		$keyNotHasData = [
			"name" => " iphone 15 ",
			"description" => "smart phone !!! 2022",
			"price" => 30
		];

		$keyNotHasResults = $this->put('api/v1/products', $keyNotHasData);
		$keyNotHasResults->assertStatus(404);

		//資料缺失 
		$otherDataExistdata = ["p_key" => $insertID];
		$otherDataExistResults = $this->put('api/v1/products', $otherDataExistdata);
		$otherDataExistResults->assertStatus(404);

		//不存在的key 
		$keyExistdata = [
			"p_key" => 999,
			"name" => " iphone 15 ",
			"description" => "smart phone !!! 2022",
			"price" => 30
		];

		$keyExistResults = $this->put('api/v1/products', $keyExistdata);
		$keyExistResults->assertStatus(404);

		//正確輸入測試
		$data = [
			"p_key" => $insertID,
			"name" => " iphone 15 ",
			"description" => "smart phone !!!update",
			"price" => 40
		];

		$results = $this->withBodyFormat('json')->put('api/v1/products', $data);
		$results->assertStatus(200);

		//檢查資料是否成功新增 in production table
		$this->seeInDatabase("production", $data);
	}

	/**
	 * @test
	 * 
	 * 刪除商品
	 * 
	 * @return void
	 */

	public function testDelete()
	{

		$productionData = [
			"name" => " iphone 15 ",
			"description" => "smart phone !!! 2022",
			"price" => 30,
			"created_at" => date("Y-m-d H:i:s"),
			"updated_at" => date("Y-m-d H:i:s")
		];

		$this->db->table("production")->insert($productionData);
		$insertID = $this->db->insertID();

		//商品key不存在 
		$keyNotExistResults = $this->withBodyFormat('json')->post('api/vDtm/products/delete', ["p_key" => 999999]);
		$keyNotExistResults->assertStatus(404);

		//正確輸入測試
		$results = $this->withBodyFormat('json')->post('api/vDtm/products/delete', ["p_key" => $insertID]);
		$results->assertStatus(200);

		//確認資料已刪除
		$deleteCheckResult = $this->grabFromDatabase('production', 'deleted_at', ['p_key' => $insertID]);
		$this->assertTrue(!is_null($deleteCheckResult));
	}
}
