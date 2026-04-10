<?php

namespace Config;

use AnserGateway\Config\BaseConfig;

class ServiceDiscovery extends BaseConfig
{
    /**
     * 需要被探索的服務名稱
     *
     * @var array<string>
     */
    public array $defaultServiceGroup = [];

    /**
     * Service discovery address
     *
     * @var string
     */
    public string $address = '';

    /**
     * HTTP Scheme [http or https]
     *
     * @var string
     */
    public string $scheme = 'http';

    /**
     * DataCenter
     *
     * @var string
     */
    public string $dataCenter = '';

    /**
     * 請求間隔
     *
     * @var integer
     */
    public int $reloadTime = 10;

    public function __construct()
    {
        parent::__construct();

        // 因.env傳入為字串，故使用explode作切割
        if(getenv('servicediscovery.defaultServiceGroup') !== ''){
            $this->defaultServiceGroup = explode(',', getenv('servicediscovery.defaultServiceGroup'));
        }
    }
}
