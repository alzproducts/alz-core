<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Notifications\Events\AdminAlertEvent;
use App\Infrastructure\Notifications\Listeners\AdminAlertSlackListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class NotificationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(AdminAlertEvent::class, AdminAlertSlackListener::class);
    }
}
