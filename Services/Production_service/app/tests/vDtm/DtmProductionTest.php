<?php

use Tests\Support\DatabaseTestCase;
use App\Models\v1\ProductionModel;


class DtmProductionTest extends DatabaseTestCase
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
	 * 取得產品列表
	 * 
	 * @return void
	 */
	public function testList()
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
				"name" => '125',
				"description" => '789',
				"price" => 5000,
				"created_at" => date("Y-m-d H:i:s"),
				"updated_at" => date("Y-m-d H:i:s")
			]
		);

		$this->db->table("production")->insertBatch($productionData);

		$data = [
			"limit" => 2,
			'search' => '12',
			'offset' => 1,
			'isDesc' => 'ASC'
		];

		//url帶有參數
		$results = $this->withBodyFormat('json')->post('api/vDtm/products/list', $data);
		if (!$results->isOK()) $results->assertStatus(404);
		$results->assertStatus(200);

		//取得resopnse資料並json decode decode解為Stdclasss
		$decodeResult = json_decode($results->getJSON());

		//將取得data->list的資料
		$resultStdGetList = $decodeResult->data->list;
		$resultStdGetAmount = $decodeResult->data->amount;

		//以相同參數取得DB結果   
		$productionModel = new ProductionModel();
		$testQuery = $productionModel->select('name,description,price,created_at as createdAt,updated_at as updatedAt')
									 ->orderBy("created_at", $data['isDesc'])
									 ->like("name", $data['search']);
		$testResultAmount = $testQuery->countAllResults(false);
		$testResult = $testQuery->get($data['limit'], $data['offset'])->getResult();

		//比較List是否相同
		$this->assertEquals($resultStdGetList, $testResult);

		//比較amount是否相同
		$this->assertEquals($resultStdGetAmount, $testResultAmount);

		//url未帶參數
		$notHasParamResults = $this->post('api/vDtm/products/list', []);
		if (!$notHasParamResults->isOK()) $notHasParamResults->assertStatus(404);
		$notHasParamResults->assertStatus(200);

		//取得resopnse資料並json decode
		$decodeNotHasParamResults = json_decode($notHasParamResults->getJSON());

		//將取得data->list的資料
		$notHasParamResultsStdGetList = $decodeNotHasParamResults->data->list;
		$notHasParamResultsStdGetAmount = $decodeNotHasParamResults->data->amount;

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

		for ($i = 0; $i < 3; $i++) {

			$this->db->table('production')->insert($productionData[$i]);
			$inserIDByThisProduct = $this->db->insertID();
			$insertIDArray[$i] = $inserIDByThisProduct;
		}

		//產品key未設定
		$keyUnsetResults = $this->withBodyFormat('json')->post('api/vDtm/products/show', []);
		$keyUnsetResults->assertStatus(404);

		//產品key不存在
		$keyNotExistResults = $this->withBodyFormat('json')->post('api/vDtm/products/show', ["p_key" => 999999]);
		$keyNotExistResults->assertStatus(404);

		//正確測試
		$results = $this->withBodyFormat('json')->post('api/vDtm/products/show', ['p_key' => $insertIDArray[0]]);
		$results->assertStatus(200);
	}

	/**
	 * @test
	 * 
	 * 建立產品
	 * 
	 * @return void
	 */
	public function testCreate()
	{

		//無輸入資料
		$otherDataNotExistResults = $this->withBodyFormat('json')->post('api/vDtm/products/create', []);
		$otherDataNotExistResults->assertStatus(400);

		//正確輸入測試
		$testData = [
			"name" => "iphone 29",
			"description" => "smart phone",
			"price" => 32000,
			"amount" => 50
		];

		$results = $this->withBodyFormat('json')->post('api/vDtm/products/create', $testData);
		if (!$results->isOK()) $results->assertStatus(400);
		$results->assertStatus(200);

		//檢查資料是否成功新增 in production table
		$productAssertData = [
			"name" => "iphone 29",
			"description" => "smart phone",
			"price" => 32000,
		];

		$this->seeInDatabase("production", $productAssertData);

		//檢查資料是否成功新增 in inventory table
		$productAssertData = [
			"p_key" => 1,
			"amount" => 50
		];

		$this->seeInDatabase("inventory", $productAssertData);
	}

	/**
	 * @test
	 * 修改產品
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
		$keyUnsetResults = $this->withBodyFormat('json')->post('api/vDtm/products/update', []);
		$keyUnsetResults->assertStatus(400);

		//不存在的key
		$keyNotExistResults = $this->withBodyFormat('json')->post('api/vDtm/products/update', ["p_key" => 999]);
		$keyNotExistResults->assertStatus(404);

		//資料缺失
		$otherDataNotExistResults = $this->withBodyFormat('json')->post('api/vDtm/products/update', ["p_key" => $insertID]);
		$otherDataNotExistResults->assertStatus(404);

		//正確輸入測試
		$productAssertData = [
			"p_key" => $insertID,
			"name" => "iphone 31",
			"description" => "I will be update!",
			"price" => 32000,
		];

		$results = $this->withBodyFormat('json')->post('api/vDtm/products/update', $productAssertData);
		$results->assertStatus(200);

		//檢查資料是否成功新增 in production table
		$this->seeInDatabase("production", $productAssertData);
	}

	/**
	 * @test
	 * 刪除產品
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

		//空資料輸入
		$keyUnsetResults = $this->withBodyFormat('json')->post('api/vDtm/products/delete', []);
		$keyUnsetResults->assertStatus(400);

		//商品key不存在
		$keyNotExistResults = $this->withBodyFormat('json')->post('api/vDtm/products/delete', ["p_key" => 999999]);
		$keyNotExistResults->assertStatus(404);

		//正確資料測試
		$results = $this->withBodyFormat('json')->post('api/vDtm/products/delete', ["p_key" => $insertID]);
		$results->assertStatus(200);

		//確認資料已刪除
		$deleteCheckResult = $this->grabFromDatabase('production', 'deleted_at', ['p_key' => $insertID]);
		$this->assertTrue(!is_null($deleteCheckResult));
	}
}
