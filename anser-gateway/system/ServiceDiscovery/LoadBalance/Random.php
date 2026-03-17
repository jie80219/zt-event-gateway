<?php 
namespace AnserGateway\ServiceDiscovery\LoadBalance;
use AnserGateway\ServiceDiscovery\LoadBalance\LoadBalanceInterface;

class Random implements LoadBalanceInterface
{
    /**
     * 隨機選出一個服務
     *
     * @param array $services
     * @return array
     */
    public function do(array $services): array
    {
        $serviceCount = count($services);
        $rand         = rand(0,$serviceCount-1); 

        return $services[$rand];
    }
}


?>