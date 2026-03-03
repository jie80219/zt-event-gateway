<?php

declare(strict_types=1);

namespace ZtEventGateway\Event;

interface EventBusInterface
{
    public function publish(EventEnvelope $event): void;
}
