<?php
namespace AnserGateway\ServiceDiscovery\LoadBalance;

use AnserGateway\ServiceDiscovery\LoadBalance\LoadBalanceInterface;
use AnserGateway\Util\RedisClient;

class LeastConn implements LoadBalanceInterface
{
    /**
     * 根據最少連線數選擇服務
     *
     * @param array $services [['address' => '10.1.1.x', ...], ...]
     * @return array
     * @throws \Exception
     */
    public function do(array $services): array
    {
        $serviceCount = count($services);
        if ($serviceCount === 0) {
            throw new \Exception("服務列表為空");
        }

        $minConn = PHP_INT_MAX;
        $selected = null;

        // 選擇連線數最少的服務
        foreach ($services as $service) {
            if (!isset($service['address'])) {
                error_log("[LeastConn] Service is missing 'address' key");
                continue; // 跳過缺少 'address' 鍵的服務
            }

            $address = $service['address']; // 使用 address 欄位
            $key = "conn:$address"; // 根據 address 記錄連線數

            // 取得當前的連線數
            $conn = (int) RedisClient::get()->get($key);
            if ($conn < $minConn) {
                $minConn = $conn;
                $selected = $service;
            }
        }

        if (!$selected) {
            throw new \Exception("無法選擇最少連線數的服務");
        }

        // 選擇到伺服器後，將其連線數 +1
        RedisClient::get()->incr("conn:{$selected['address']}");

        return $selected;
    }

    /**
     * 當請求完成後，將伺服器的連線數 -1
     *
     * @param string $address
     */
    public function decrementConn(string $address): void
    {
        RedisClient::get()->decr("conn:$address");
    }
}
