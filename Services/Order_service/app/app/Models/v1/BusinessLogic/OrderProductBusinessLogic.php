<?php

namespace App\Models\v1\BusinessLogic;

use App\Models\v1\OrderProductModel;
use App\Entities\v1\OrderProductEntity;

class OrderProductBusinessLogic
{
    /**
     * 取得訂單商品
     *
     * @param  string $o_key
     * @return array|null
     */
    static function getOrderProduct(string $o_key): ?array
    {
        $orderProductModel = new OrderProductModel();

        $orderProductEntity = $orderProductModel->where('o_key', $o_key)
                                                ->find();

        $data = [];

        if ($orderProductEntity) {
            foreach ($orderProductEntity as $orderProdcuts) {
                $orderProdcut = [
                    "p_key" => $orderProdcuts->p_key,
                    "price" => $orderProdcuts->price,
                    "amount" => $orderProdcuts->amount,
                    "ext_price" => $orderProdcuts->ext_price
                ];
                $data[] = $orderProdcut;
            }
        } else {
            return null;
        }

        return $data;
    }

    /**
     * 新增 order_product
     *
     * @param string $o_key
     * @param array $productDetailArr
     * @return bool true 代表執行成功
     */
    static function createOrderProduct(string $o_key, array $productDetailArr): bool
    {
        $orderProductModel  = new OrderProductModel();
        $orderProductEntity = new OrderProductEntity();
        $result = true;

        foreach ($productDetailArr as $product) {
            if ($result == true) {
                $orderProductEntity->o_key = $o_key;
                $orderProductEntity->p_key = $product["p_key"];
                $orderProductEntity->price = $product["price"];
                $orderProductEntity->amount = $product["amount"];
                $orderProductEntity->ext_price = $product["ext_price"];

                $orderProductModel->insert($orderProductEntity->toRawArray(true));
            } else {
                $orderProductModel->where('o_key', $o_key)
                                  ->delete();
                return $result;
            }
        }
        return $result;
    }

}
