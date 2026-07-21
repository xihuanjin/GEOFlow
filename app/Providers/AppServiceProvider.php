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
