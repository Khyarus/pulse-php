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

        ob_start();
        try {
            $dashboard->render();
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $status = http_response_code();
        $response = response($html, is_int($status) ? $status : 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, private');

        if ($status === 401
            && config('pulse.dashboard_user') !== null
            && config('pulse.dashboard_password') !== null) {
            $response->header('WWW-Authenticate', 'Basic realm="PulsePHP Dashboard", charset="UTF-8"');
        }

        return $response;
    }
}
