<?php

namespace App\Services\Bibliography;

class DeferredCheck extends \RuntimeException
{
    public int $requests = 0;

    public function __construct(public int $delay = 2)
    {
        parent::__construct('External provider slot is busy.');
    }
}
