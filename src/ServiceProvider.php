<?php
namespace DreamFactory\Core\ApiDoc;

use DreamFactory\Core\ApiDoc\Services\Swagger;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     */
    public function boot()
    {
        // add migrations, https://laravel.com/docs/5.4/packages#resources
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // Add our service types.
        $this->app->resolving('df.service', function (ServiceManager $df) {
            $df->addType(
                new ServiceType([
                    'name'            => 'swagger',
                    'label'           => 'API Docs',
                    'description'     => 'API documenting and testing service using Swagger specifications.',
                    'group'           => ServiceTypeGroups::API_DOC,
                    'singleton'       => true,
                    'factory'         => function ($config) {
                        return new Swagger($config);
                    },
                ])
            );
        });
    }
}
