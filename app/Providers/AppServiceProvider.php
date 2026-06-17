<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repositories\TransferRepositoryInterface;
use App\Repositories\DatabaseTransferRepository;
use App\Repositories\InMemoryTransferRepository;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TransferRepositoryInterface::class, function ($app) {
            $driver = config('transfers.storage_driver', 'database');

            return match ($driver) {
                'in_memory' => $app->make(InMemoryTransferRepository::class),
                default => $app->make(DatabaseTransferRepository::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

