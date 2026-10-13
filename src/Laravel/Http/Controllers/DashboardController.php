<?php

declare(strict_types=1);

namespace PulsePHP\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use PulsePHP\Dashboard\Dashboard;
use PulsePHP\Dashboard\DashboardResponse;

final class DashboardController extends Controller
{
    public function show(Request $request): Response
    {
        return $this->toResponse($this->dashboard($request)->handle());
    }

    public function data(Request $request): Response
    {
        return $this->toResponse($this->dashboard($request)->handleJson());
    }

    private function dashboard(Request $request): Dashboard
    {
        $dashboard = (new Dashboard((string) config('pulse.database_path')))
            ->withRequest(
                $request->query->all(),
                $request->request->all(),
                $request->cookies->all(),
                $request->method(),
                (string) $request->ip()
            )
            ->withDataEndpoint(route('pulse.data', [], false))
            // Laravel's VerifyCsrfToken middleware checks the token on POST.
            ->withFormToken('_token', (string) csrf_token())
            ->withNPlusOneThreshold((int) config('pulse.n_plus_one_threshold', 5));
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

        return $dashboard;
    }

    private function toResponse(DashboardResponse $result): Response
    {
        $response = response($result->body, $result->status);

        foreach ($result->headers() as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }
}
