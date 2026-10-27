<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // WebPush résolu via le container : les tests peuvent binder un mock
        // (PushService ne l'instancie plus lui-même).
        $this->app->bind(\Minishlink\WebPush\WebPush::class, function ($app) {
            $public = (string) config('webpush.vapid_public_key', env('VAPID_PUBLIC_KEY', ''));
            $private = (string) config('webpush.vapid_private_key', env('VAPID_PRIVATE_KEY', ''));
            $subject = (string) config('webpush.vapid_subject', env('VAPID_SUBJECT', 'mailto:contact@fedpawa.com'));

            return new \Minishlink\WebPush\WebPush(
                ['VAPID' => ['subject' => $subject, 'publicKey' => $public, 'privateKey' => $private]],
                ['TTL' => 86400] // 24 h
            );
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
