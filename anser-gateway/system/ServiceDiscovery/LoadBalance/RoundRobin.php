<?php
namespace AnserGateway\ServiceDiscovery\LoadBalance;
use AnserGateway\ServiceDiscovery\LoadBalance\LoadBalanceInterface;

class RoundRobin implements LoadBalanceInterface
{
    /**
     * 輪詢選擇服務
     *
     * @param array $services
     * @return mixed
     */
    public function do(array $services): array
    {
        static $lastIndex = -1; // 使用變數記錄上次選擇的索引

        $serviceCount = count($services);
        if ($serviceCount === 0) {
            throw new \Exception("服務列表為空");
        }

        // 輪詢選擇服務
        $lastIndex = ($lastIndex + 1) % $serviceCount;
        return $services[$lastIndex];
    }
}
