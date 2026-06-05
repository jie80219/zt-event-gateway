<?php

namespace Config;

use AnserGateway\Filters\JsonResponseFilter;

class Filters
{
    /**
     * Configures aliases for Filter classes to
     * make reading things nicer and simpler.
     */
    public array $aliases = [
        'jsonResponse'  => JsonResponseFilter::class,
    ];

    /**
     * List of filter aliases that are always
     * applied before and after every request.
     */
    public array $globals = [
        'before' => [
        ],
        'after' => [
            'jsonResponse',
        ],
    ];

}
