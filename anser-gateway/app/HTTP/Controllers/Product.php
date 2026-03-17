<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use Workerman\Protocols\Http\Response;
use SDPMlab\Anser\Service\Action;
use Psr\Http\Message\ResponseInterface;
use SDPMlab\Anser\Exception\ActionException;
use AnserGateway\HTTPConnectionManager;




class Product extends BaseController
{
    public function products()
    {
        $action = new Action(
            serviceName: "product_service",
            method: "GET",
            path: "/api/v1/products"
        );

        $action->setTimeout(60)
            ->doneHandler(static function (
                ResponseInterface $response,
                Action $runtimeAction
            ): void {
                $data = $response->getBody()->getContents();
                $runtimeAction->setMeaningData($data);
            })
            ->failHandler(function (ActionException $e): void {
                var_dump($e->getMessage());
                var_dump($e->getAction()->getOptions());
            });

        $data = $action->do()->getMeaningData();

        return $this->response->withStatus(200)->withBody($data);
    }
}
