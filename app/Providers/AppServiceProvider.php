<?php

namespace App\Providers;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Models\Admin;
use App\Services\Admin\AdminUpdateMetadataService;
use App\Services\Admin\AdminWelcomeModalService;
use App\Services\GeoFlow\AnonymousUsageTelemetry;
use App\Services\GeoFlow\ArticleGeoFlowService;
use App\Services\GeoFlow\HorizonMetricsAdapter;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use App\Services\Outbound\FinalOutboundSecurityPolicy;
use App\Services\Outbound\LaravelPinnedOutboundTransport;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use App\Services\Outbound\SystemHostResolver;
use App\View\Composers\SiteLayoutComposer;
use Closure;
use GuzzleHttp\Utils;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $fixedContextCapability = new \stdClass;
        $trustedTerminal = Closure::fromCallable(Utils::chooseHandler());

        $this->app->bind(HostResolver::class, SystemHostResolver::class);
        $this->app->singleton(FinalOutboundSecurityPolicy::class);
        $this->app->bind(OutboundTransport::class, function () use ($fixedContextCapability): LaravelPinnedOutboundTransport {
            return new LaravelPinnedOutboundTransport($fixedContextCapability);
        });
        $this->app->singleton(HttpFactory::class, function ($app) use ($fixedContextCapability, $trustedTerminal): SecureHttpFactory {
            $resolver = Closure::fromCallable(
                fn (string $url) => $app->make(SafeOutboundHttpClient::class)->resolveTarget($url)
            );

            return new SecureHttpFactory(
                $app->make('events'),
                $app->make(FinalOutboundSecurityPolicy::class),
                $resolver,
                $trustedTerminal,
                $fixedContextCapability,
            );
        });
        $this->app->singleton(JobQueueService::class);
        $this->app->singleton(HorizonMetricsAdapter::class);
        $this->app->singleton(TaskMonitoringQueryService::class);
        $this->app->singleton(TaskLifecycleService::class);
        $this->app->singleton(ArticleGeoFlowService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Laravel 12 不再自动从 APP_URL 设置 forceRootUrl，需要显式调用
        if ($appUrl = config('app.url')) {
            URL::forceRootUrl($appUrl);
        }

        // 上游 (yaojingang/GEOFlow) 把 HTTP Factory 替换成 SecureHttpFactory，
        // 弃用了通用 globalMiddleware()（抛 generic_http_middleware_forbidden），
        // 并要求应用层用 globalRequestMiddleware()/globalResponseMiddleware() 代替。
        // 而 globalRequestMiddleware() 内部的契约是 single-layer
        //   (RequestInterface): RequestInterface
        // — 它无法修改 Guzzle 的 $options['proxy']。
        // 因此 OutboundHttpProxy::middleware()（Guzze double-handler 形式，
        // 通过 $options 注入 proxy）与新 contract 互不兼容。
        //
        // 临时处理：保留 OutboundHttpProxy 类与配置能力，但不在这里挂载中间件。
        // 后续若需恢复代理功能，应将代理选项下沉到 SafeOutboundHttpClient 的发送链路
        // （改造 resolveTarget() 或 send() 把 proxy options 合并进 withOptions）。

        RateLimiter::for('admin-login', function (Request $request): Limit {
            return Limit::perMinute(30)->by('admin-login-ip:'.$request->ip());
        });
        RateLimiter::for('admin-sensitive', function (Request $request): array {
            $adminId = (int) ($request->user('admin')?->getAuthIdentifier() ?? 0);

            return [
                Limit::perMinute(5)->by('admin-sensitive:admin:'.$adminId),
                Limit::perMinute(5)->by('admin-sensitive:admin-ip:'.$adminId.'|'.$request->ip()),
            ];
        });

        $adminGuard = Auth::guard('admin');
        if (method_exists($adminGuard, 'setRememberDuration')) {
            $adminGuard->setRememberDuration(
                max(1, (int) config('geoflow.admin_remember_minutes', 43200))
            );
        }

        View::composer(['site.layout', 'theme.*.layout'], SiteLayoutComposer::class);

        View::composer('admin.layouts.app', function ($view): void {
            $admin = auth('admin')->user();
            $view->with(
                'adminWelcomeModalPayload',
                $admin instanceof Admin ? app(AdminWelcomeModalService::class)->buildModalPayload($admin) : null
            );
            $view->with(
                'adminUpdateNotificationPayload',
                $admin instanceof Admin ? app(AdminUpdateMetadataService::class)->buildNotificationPayload() : null
            );
            $view->with(
                'anonymousUsageTelemetryPayload',
                $admin instanceof Admin ? app(AnonymousUsageTelemetry::class)->payload($admin) : null
            );
        });
    }
}
