<?php
namespace DreamFactory\Core\ApiDoc\Enums;

/**
 * The base events raised by swagger operations
 */
class SwaggerEvents
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * @var string Triggered immediately after the swagger cache is cleared
     */
    const CACHE_CLEARED = 'swagger.cache_cleared';
    /**
     * @var string Triggered immediately after the swagger cache has been rebuilt
     */
    const CACHE_REBUILT = 'swagger.cache_rebuilt';
}
