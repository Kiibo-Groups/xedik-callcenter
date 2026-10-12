<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Services\Sms\Contracts\SmsProviderInterface;
use Illuminate\Contracts\Container\Container;

class SmsProviderManager
{
    public function __construct(private readonly Container $app) {}

    public function resolve(string $providerKey): SmsProviderInterface
    {
        $map   = config('sms.providers', []);
        $class = $map[$providerKey] ?? null;

        if (!$class || !class_exists($class)) {
            throw new \InvalidArgumentException("Unknown SMS provider: {$providerKey}");
        }

        $instance = $this->app->make($class);

        if (!$instance instanceof SmsProviderInterface) {
            throw new \InvalidArgumentException("Class {$class} does not implement SmsProviderInterface");
        }

        return $instance;
    }

    /**
     * Providers utilizables en selects de UI: solo entradas de config/sms.php
     * cuya clase existe e implementa el contrato — nunca un catálogo manual
     * en base de datos ni una lista hardcodeada en la vista/controller.
     *
     * @return array<string, string> provider key => label visible
     */
    public function availableProviders(): array
    {
        $providers = [];

        foreach (config('sms.providers', []) as $key => $class) {
            if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, SmsProviderInterface::class)) {
                continue;
            }

            $providers[$key] = $class::label();
        }

        return $providers;
    }
}
