<?php
namespace DreamFactory\Core\ApiDoc\Handlers\Events;

use DreamFactory\Core\ApiDoc\Services\Swagger;
use DreamFactory\Core\Events\BaseRoleEvent;
use DreamFactory\Core\Events\BaseServiceEvent;
use DreamFactory\Core\Events\RoleDeletedEvent;
use DreamFactory\Core\Events\RoleModifiedEvent;
use DreamFactory\Core\Events\ServiceDeletedEvent;
use DreamFactory\Core\Events\ServiceModifiedEvent;
use Illuminate\Contracts\Events\Dispatcher;

class EventHandler
{
    /**
     * Register the listeners for the subscriber.
     *
     * @param  Dispatcher $events
     */
    public function subscribe($events)
    {
        $events->listen(
            [
                RoleModifiedEvent::class,
                RoleDeletedEvent::class,
            ],
            static::class . '@handleRoleEvent'
        );
        $events->listen(
            [
                ServiceModifiedEvent::class,
                ServiceDeletedEvent::class,
            ],
            static::class . '@handleServiceEvent'
        );
    }

    /**
     * Handle Role changed events.
     *
     * @param BaseRoleEvent $event
     *
     * @return void
     */
    public function handleRoleEvent($event)
    {
        Swagger::clearCache($event->role->id);
    }

    /**
     * Handle Service changed events.
     *
     * @param BaseServiceEvent $event
     *
     * @return void
     */
    public function handleServiceEvent(/** @noinspection PhpUnusedParameterInspection */$event)
    {
        Swagger::flush();
    }
}
