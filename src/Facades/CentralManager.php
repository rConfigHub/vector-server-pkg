<?php

namespace Rconfig\VectorServer\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 * @method static bool configured()
 * @method static bool canPublish()
 * @method static bool canConsume()
 * @method static string mode()
 * @method static ?string reasonDisabled()
 */
class CentralManager extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'central-manager.gate';
    }
}
