<?php

namespace App\Providers;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Models\Admin;
use App\Services\Admin\AdminUpdateMetadataService;
use App\Services\Admin\AdminWelcomeModalService;
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
use Illuminate\Http\Client\Factory as HttpFactory;
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

        // 设计决策（与 yaojingang/GEOFlow 安全架构一致）：
        // 不再提供 application-level 的 outbound HTTP proxy 中间件挂载。
        //
        // 历史背景：19ea0d2 (Add outbound HTTP proxy config) 引入过 OutboundHttpProxy
        // 全局中间件；ae1a8c4 (fix: secure outbound requests and admin routes)
        // 主动删除并替换成 SafeOutboundHttpClient + SecureHttpFactory（强制直连 + 白名单
        // host 安全出站）。LaravelPinnedOutboundTransport 与
        // FinalOutboundSecurityPolicy 两层都 hard-code 'proxy' => ''。
        // 因此任何 application-level 代理注入都会被覆盖并抛
        // 'secure_pending_request_required' / 'generic_http_middleware_forbidden'。
        //
        // 若部署环境需要走代理（如隔离网络），应在 Nginx 出口或基础设施层（如
        // host.docker.internal 上游）做 NAT，而不在 application 层挂代理中间件。

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
        });
    }
}
