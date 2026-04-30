<?php

use Tests\Support\DatabaseTestCase;
use App\Models\v1\InventoryModel;



class DtmInventoryTest extends DatabaseTestCase
{
	public function setUp(): void
	{
		parent::setUp();
		// InitDatabase::InitDatabase('tests');
		// Extra code to run before each test

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
	 * 增加庫存
	 * @return void
	 */
	public function testAddInventory()
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

		$userKey = 1;

		$okeyArray = [
			"okeyByProduct1" => sha1(serialize($productionData[0]) . $userKey . uniqid()),
			"okeyByProduct2" => sha1(serialize($productionData[1]) . $userKey . uniqid()),
			"okeyByProduct3" => sha1(serialize($productionData[2]) . $userKey . uniqid())
		];

		$insertIDArray = [];

		for ($i = 0; $i < 3; $i++) {

			$this->db->table("production")->insert($productionData[$i]);
			$productionInsertId = $this->db->insertID();
			$insertIDArray[$i] = $productionInsertId;
			
			//第一、二筆資料建立庫存
			if ($i != 2) {
				$inventory = [
					"p_key" => $productionInsertId,
					"amount" => 25,
					"created_at" => date("Y-m-d H:i:s"),
					"updated_at" => date("Y-m-d H:i:s")
				];

				$this->db->table("inventory")->insert($inventory);
			}

			//第一筆資料建立History
			if ($i == 0) {
				$historyData = [
					'p_key' => $productionInsertId,
					'o_key' => $okeyArray['okeyByProduct1'],
					"amount" => 25,
					'type' => 'create',
					"created_at" => date("Y-m-d H:i:s"),
					"updated_at" => date("Y-m-d H:i:s")
				];
				$this->db->table("history")->insert($historyData);
			}
		}

		/** 空資料輸入*/
		$otherDataNotExistResults = $this->withBodyFormat('json')->post('api/vDtm/inventory/addInventory', []);
		$otherDataNotExistResults->assertStatus(404);

		/** 該商品不存在*/
		$keyNotExistData = [
			"p_key" => 999,
			"o_key" => sha1(serialize($productionData[0]) . $userKey . uniqid()),
			"amount" => 25,
			"type" => "reduce"
		];

		$keyNotExistResults = $this->withBodyFormat('json')->post('api/vDtm/inventory/addInventory', $keyNotExistData);
		$keyNotExistResults->assertStatus(404);

		/** 資料重複*/
		$dataRepeatData = [
			"p_key" => $insertIDArray[0],
			"o_key" => $okeyArray['okeyByProduct1'],
			"amount" => 25,
			"type" => "create"
		];

		$dataRepeatResults = $this->withBodyFormat('json')->post('api/vDtm/inventory/addInventory', $dataRepeatData);
		$dataRepeatResults->assertStatus(400);

		/** 訂單未被成立*/
		$orderIsNotCreateData = [
			"p_key" => $insertIDArray[1],
			"o_key" => $okeyArray['okeyByProduct3'],
			"amount" => 200,
			"type" => "create"
		];

		$orderIsNotCreateResults = $this->withBodyFormat('json')->post('api/vDtm/inventory/addInventory', $orderIsNotCreateData);
		$orderIsNotCreateResults->assertStatus(400);

		/** Successful*/
		$SuccessfulData = [
			"p_key" => $insertIDArray[0],
			"o_key" => $okeyArray['okeyByProduct1'],
			"amount" => 1000,
			"type" => "reduce"
		];

		//get transation before data
		$inventoryModel = new InventoryModel();
		$beforeInventory = $inventoryModel->where('p_key',$insertIDArray[0])->first();

		$SuccessfulResults = $this->withBodyFormat('json')->post('api/vDtm/inventory/addInventory', $SuccessfulData);
		if (!$SuccessfulResults->isOK()) $SuccessfulResults->assertStatus(400);
		$SuccessfulResults->assertStatus(200);

		//get transation after data
		$afterInventory = $inventoryModel->where('p_key',$insertIDArray[0])->first();

		// 將TRANSATION前後的資料進行相減比對
		$transationIsTrue = ($afterInventory->amount - $SuccessfulData['amount']) == $beforeInventory->amount;
		$SuccessfulResults->assertTrue($transationIsTrue);

		//於inventory中比對是否有該筆資料
		$seeingData = [
			"p_key" => $insertIDArray[0],
			"amount" => $SuccessfulData['amount'] + $beforeInventory->amount
		];

		$SuccessfulResults->seeInDatabase('inventory', $seeingData);

		//於history中比對是否有該筆資料
		$historyAssertData = [
			"p_key" => $insertIDArray[0],
			"o_key" => $okeyArray['okeyByProduct1'],
			"amount" => 1000,
			"type" => "reduce"
		];

		$this->seeInDatabase("history", $historyAssertData);
	}
	/**
	 * @test 
	 * 減少庫存
	 * @return void
	 */
	public function testReduceInventory()
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

		$userKey = 1;

		$okeyArray = [
			"okeyByProduct1" => sha1(serialize($productionData[0]) . $userKey . uniqid()),
			"okeyByProduct2" => sha1(serialize($productionData[1]) . $userKey . uniqid()),
			"okeyByProduct3" => sha1(serialize($productionData[2]) . $userKey . uniqid())
		];

		$insertIDArray = [];

		for ($i = 0; $i < 3; $i++) {

			$this->db->table("production")->insert($productionData[$i]);
			$productionInsertId = $this->db->insertID();
			$insertIDArray[$i] = $productionInsertId;

			//第一、二筆資料建立庫存
			if ($i != 2) {
				$inventory = [
					"p_key" => $productionInsertId,
					"amount" => 25,
					"created_at" => date("Y-m-d H:i:s"),
					"updated_at" => date("Y-m-d H:i:s")
				];

				$this->db->table("inventory")->insert($inventory);
			}

			//第一筆資料建立History
			if ($i == 0) {
				$historyData = [
					'p_key' => $productionInsertId,
					'o_key' => $okeyArray['okeyByProduct1'],
					"amount" => 25,
					'type' => 'create',
					"created_at" => date("Y-m-d H:i:s"),
					"updated_at" => date("Y-m-d H:i:s")
				];
				$this->db->table("history")->insert($historyData);
			}
		}

		/** 傳入值不完整*/
		$otherDataNotExistResults = $this->withBodyFormat('json')->post('api/vDtm/inventory/reduceInventory', []);
		$otherDataNotExistResults->assertStatus(404);

		/** 查無此商品key*/
		$keyNotExistData = [
			"p_key" => 999,
			"o_key" => sha1(serialize($productionData[0]) . $userKey . uniqid()),
			"amount" => 200,
		];

		$keyNotExistResults = $this->withBodyFormat('json')->post('api/vDtm/inventory/reduceInventory', $keyNotExistData);
		$keyNotExistResults->assertStatus(404);

		/** 類別或編號重複*/
		$dataRepeatData = [
			"p_key" => $insertIDArray[0],
			"o_key" => $okeyArray['okeyByProduct1'],
			"amount" => 200
		];

		$dataRepeatResults = $this->withBodyFormat('json')->post('api/vDtm/inventory/reduceInventory', $dataRepeatData);
		$dataRepeatResults->assertStatus(400);

		/** 找不到庫存資料*/
		$notFindInventoryData = [
			"p_key" => $insertIDArray[2],
			"o_key" => $okeyArray['okeyByProduct3'],
			"amount" => 200,
		];

		$notFindKeyResults = $this->withBodyFormat('json')->post('api/vDtm/inventory/reduceInventory', $notFindInventoryData);
		$notFindKeyResults->assertStatus(404);

		/** 庫存數量不夠*/
		$amountInsufficientData = [
			"p_key" => $insertIDArray[1],
			"o_key" => $okeyArray['okeyByProduct2'],
			"amount" => 9999999,
		];

		$amountInsufficientResults = $this->withBodyFormat('json')->post('api/vDtm/inventory/reduceInventory', $amountInsufficientData);
		$amountInsufficientResults->assertStatus(400);


		/** 正確性測試*/
		$successfulData = [
			"p_key" => $insertIDArray[1],
			"o_key" => $okeyArray['okeyByProduct2'],
			"amount" => 20,
		];

		//get transation before data
		$inventoryModel = new InventoryModel();
		$beforeInventory = $inventoryModel->where('p_key', $insertIDArray[1])->first();

		$successfulResults = $this->withBodyFormat('json')->post('api/vDtm/inventory/reduceInventory', $successfulData);
		if (!$successfulResults->isOK()) $successfulResults->assertStatus(400);
		$successfulResults->assertStatus(200);

		//get transation before data
		$afterInventory = $inventoryModel->where('p_key', $insertIDArray[1])->first();

		$transationIsTrue = ($afterInventory->amount + $successfulData['amount']) == $beforeInventory->amount;
		$successfulResults->assertTrue($transationIsTrue);

		//於inventory中比對是否有該筆資料
		$seeingData = [
			"p_key" => $insertIDArray[1],
			"amount" => $successfulData['amount'] - $beforeInventory->amount
		];

		$successfulResults->seeInDatabase('inventory', $seeingData);

		//於history中比對是否有該筆資料
		$successfulVaildData = [
			"p_key" => $insertIDArray[1],
			"o_key" => $okeyArray['okeyByProduct2'],
			"amount" => 20,
			"type" => "create"
		];

		$this->seeInDatabase("history", $successfulVaildData);
	}

	/**
	 * @test 
	 * 刪除庫存
	 * @return void
	 */
	public function testDelete()
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

			$this->db->table("production")->insert($productionData[$i]);
			$productionInsertId = $this->db->insertID();
			$insertIDArray[$i] = $productionInsertId;

			$inventory = [
				"p_key" => $productionInsertId,
				"amount" => 25,
				"created_at" => date("Y-m-d H:i:s"),
				"updated_at" => date("Y-m-d H:i:s")
			];

			$this->db->table("inventory")->insert($inventory);
		}

		//該key不存在
		$keyNotExistResults = $this->delete('api/v1/inventory/99999');
		$keyNotExistResults->assertStatus(404);

		//正確案例測試
		$successfulResults = $this->delete('api/v1/inventory/'. $insertIDArray[2]);
		$successfulResults->assertStatus(200);

		//確認資料已刪除
		$deleteCheckResult = $this->grabFromDatabase('inventory', 'deleted_at', ['p_key' => $insertIDArray[2]]);
		$this->assertTrue(!is_null($deleteCheckResult));
	}
}
