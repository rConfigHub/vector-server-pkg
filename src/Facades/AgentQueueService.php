<?php

namespace Rconfig\VectorServer\Facades;

use Illuminate\Support\Facades\Facade;

class AgentQueueService extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'agentqueueservice';
    }
}
