<?php

namespace Rconfig\VectorServer\Traits;

use App\Jobs\PublishToRabbitMQJob;

trait PublishesToRabbitMQ
{
    /**
     * Boot the trait
     * This method is called when the trait is being booted
     * and it will register the model events for publishing to RabbitMQ
     * 
     * This is only used when the RabbitMQ configuration is set and is used in conjunction with the Vector Central Manager
     *
     * @return void
     */
    public static function bootPublishesToRabbitMQ()
    {
        static::created(function ($model) {
            if (self::isRabbitMQConfigured()) {
                PublishToRabbitMQJob::dispatch(
                    $model->toArray(),
                    'created',
                    get_class($model)
                )->onQueue('rConfigDefault');
            }
        });

        static::updated(function ($model) {
            if (self::isRabbitMQConfigured()) {
                PublishToRabbitMQJob::dispatch(
                    $model->toArray(),
                    'updated',
                    get_class($model)
                )->onQueue('rConfigDefault');
            }
        });

        static::deleted(function ($model) {
            if (self::isRabbitMQConfigured()) {
                PublishToRabbitMQJob::dispatch(
                    $model->toArray(),
                    'deleted',
                    get_class($model)
                )->onQueue('rConfigDefault');
            }
        });
    }

    /**
     * Check if RabbitMQ is configured properly
     *
     * @return bool
     */
    protected static function isRabbitMQConfigured()
    {
        $rabbitMq = config('services.rabbitmq');

        if (!$rabbitMq) {
            return false;
        }

        return !empty($rabbitMq['host']) &&
            !empty($rabbitMq['port']) &&
            !empty($rabbitMq['user']) &&
            !empty($rabbitMq['pass']) &&
            !empty($rabbitMq['vhost']) &&
            !empty($rabbitMq['queue']);
    }
}
