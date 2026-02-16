<?php

namespace Rconfig\VectorServer\CentralManager;

class CentralManagerGate
{
    private const STRING_KEYS = ['host', 'user', 'pass', 'vhost', 'queue'];

    private const VALID_MODES = ['off', 'publisher', 'consumer', 'both'];

    public function enabled(): bool
    {
        return (bool) config('central_manager.enabled', false);
    }

    public function mode(): string
    {
        $mode = strtolower((string) config('central_manager.mode', 'off'));

        if (! in_array($mode, self::VALID_MODES, true)) {
            return 'off';
        }

        return $mode;
    }

    public function configured(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        if ($this->mode() === 'off') {
            return false;
        }

        if (! $this->requiresRabbitMqConfig()) {
            return true;
        }

        return $this->isRabbitMqConfigured();
    }

    public function canPublish(): bool
    {
        if (! $this->enabled() || ! $this->configured()) {
            return false;
        }

        return in_array($this->mode(), ['publisher', 'both'], true);
    }

    public function canConsume(): bool
    {
        if (! $this->enabled() || ! $this->configured()) {
            return false;
        }

        return in_array($this->mode(), ['consumer', 'both'], true);
    }

    public function reasonDisabled(): ?string
    {
        if (! $this->enabled()) {
            return 'central_manager.disabled';
        }

        $mode = $this->mode();
        if ($mode === 'off') {
            return 'central_manager.mode_off';
        }

        if ($this->requiresRabbitMqConfig() && ! $this->isRabbitMqConfigured()) {
            return 'central_manager.rabbitmq_config_missing';
        }

        return null;
    }

    private function requiresRabbitMqConfig(): bool
    {
        return (bool) config('central_manager.require_rabbitmq_config', true);
    }

    private function isRabbitMqConfigured(): bool
    {
        $path = (string) config('central_manager.rabbitmq_config_path', 'services.rabbitmq');
        $required = config('central_manager.required_rabbitmq_keys', []);
        $rabbitCfg = config($path, []);

        if (! is_array($rabbitCfg)) {
            return false;
        }

        if (! is_array($required)) {
            $required = [];
        }

        foreach ($required as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $value = $rabbitCfg[$key] ?? null;

            if (in_array($key, self::STRING_KEYS, true)) {
                if (! is_string($value) || trim($value) === '') {
                    return false;
                }
                continue;
            }

            if ($key === 'port') {
                if (! is_numeric($value) || (int) $value <= 0) {
                    return false;
                }
                continue;
            }

            if ($value === null) {
                return false;
            }

            if (is_string($value) && trim($value) === '') {
                return false;
            }
        }

        return true;
    }
}
