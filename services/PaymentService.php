<?php
namespace Services;

use SDPMlab\Anser\Service\SimpleService;
use SDPMlab\Anser\Service\ActionInterface;

class PaymentService extends SimpleService
{
    protected $serviceName = "PaymentService";
    protected $retry = 1;
    protected $retryDelay = 0.2;
    protected $timeout = 5.0;
    protected $options = [];

    /**
     * Charge payment for an order.
     *
     * @param array<string,mixed> $payload
     */
    public function chargeAction(array $payload = []): ActionInterface
    {
        return $this->getAction(
            method: "POST",
            path: "/api/v1/payment/charge"
        )->setOptions([
            "json" => $payload
        ]);
    }

    /**
     * Refund a previously charged payment.
     *
     * @param array<string,mixed> $payload
     */
    public function refundAction(array $payload = []): ActionInterface
    {
        return $this->getAction(
            method: "POST",
            path: "/api/v1/payment/refund"
        )->setOptions([
            "json" => $payload
        ]);
    }
}
