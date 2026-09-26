# vitalheart-php

PulsePHP is a lightweight PHP 8.1+ telemetry library backed by SQLite. Its Laravel support is optional; the core has no Illuminate dependency.

## Standalone Dashboard

The public example only allows loopback IPs by default. Set both Basic Auth variables to require a second factor as well:

```powershell
$env:PULSE_DASHBOARD_USER = 'pulse-admin'
$env:PULSE_DASHBOARD_PASSWORD = 'use-a-long-secret'
php -S 127.0.0.1:8000 -t public
```

Open `http://127.0.0.1:8000/dashboard.php`. When configured, the IP whitelist and Basic Auth are cumulative. Applications embedding the dashboard can also authorize through a callback:

```php
$dashboard = new PulsePHP\Dashboard\Dashboard($databasePath);
$dashboard->authorize(static fn (): bool => $currentUser->isAdmin());
$dashboard->render();
```

The dashboard denies access when no policy is configured. Keep the endpoint behind authentication and HTTPS before exposing it beyond a trusted local network.

## Laravel 9+

The service provider is Composer auto-discovered when the package is installed in Laravel. Publish its configuration with:

```shell
php artisan vendor:publish --tag=pulse-config
```

The provider registers the `pulse` route, query listener, and request middleware according to `config/pulse.php`. Access is denied until the application defines the `viewPulse` Gate, for example in an authorization provider:

```php
Gate::define('viewPulse', static fn (User $user): bool => $user->is_admin);
```

Set `PULSE_DASHBOARD_USER` and `PULSE_DASHBOARD_PASSWORD` to add Basic Auth, and `PULSE_DASHBOARD_IPS` to provide a comma-separated IP whitelist. If Basic Auth or IP restrictions are configured, they are required in addition to the Gate.