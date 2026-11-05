<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureKfosAuthStageLocal;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class KfosAuthStageGuardTest extends TestCase
{
    private function request(string $url = 'http://localhost:8080/kfos-auth-stage/login'): Request
    {
        return Request::create($url, 'POST');
    }

    private function next(): \Closure
    {
        return static fn () => response()->json(['allowed' => true]);
    }

    public function test_feature_flag_disabled_rejects_access(): void
    {
        config(['kfos_auth_stage.enabled' => false]);
        $this->expectException(NotFoundHttpException::class);
        app(EnsureKfosAuthStageLocal::class)->handle($this->request(), $this->next());
    }

    public function test_nonlocal_host_rejects_access_even_when_flag_enabled(): void
    {
        config(['kfos_auth_stage.enabled' => true]);
        $this->expectException(NotFoundHttpException::class);
        app(EnsureKfosAuthStageLocal::class)->handle(
            $this->request('http://example.invalid/kfos-auth-stage/login'),
            $this->next()
        );
    }

    public function test_nonlocal_app_url_rejects_access_even_with_local_request_host(): void
    {
        config(['kfos_auth_stage.enabled' => true, 'app.url' => 'https://example.invalid']);
        $this->expectException(NotFoundHttpException::class);
        app(EnsureKfosAuthStageLocal::class)->handle($this->request(), $this->next());
    }
}
