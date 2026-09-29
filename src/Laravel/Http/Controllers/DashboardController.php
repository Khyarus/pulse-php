<?php

declare(strict_types=1);

namespace PulsePHP\Laravel\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use PulsePHP\Dashboard\Dashboard;

final class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $dashboard = new Dashboard((string) config('pulse.database_path'));
        $dashboard->authorize(static fn (): bool => Gate::allows('viewPulse'));

        $username = config('pulse.dashboard_user');
        $password = config('pulse.dashboard_password');
        if ($username !== null || $password !== null) {
            if (!is_string($username) || !is_string($password) || $username === '' || $password === '') {
                $dashboard->authorize(static fn (): bool => false);
            } else {
                $dashboard->authWithBasic($username, $password);
            }
        }

        $allowedIps = config('pulse.dashboard_allowed_ips', []);
        if (is_array($allowedIps) && $allowedIps !== []) {
            $dashboard->authWithIp($allowedIps);
        }

        $result = $dashboard->handle();

        $response = response($result->body, $result->status);

        foreach ($result->headers() as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }
}

