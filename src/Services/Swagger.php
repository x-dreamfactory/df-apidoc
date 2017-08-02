<?php

namespace DreamFactory\Core\ApiDoc\Services;

use DreamFactory\Core\Components\StaticCacheable;
use DreamFactory\Core\Contracts\ServiceInterface;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Enums\DataFormats;
use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Services\BaseRestService;
use DreamFactory\Core\Utility\ResourcesWrapper;
use DreamFactory\Core\Utility\Session;
use Config;
use Log;
use ServiceManager;


/**
 * Swagger
 * API Documentation manager
 *
 */
class Swagger extends BaseRestService
{
    use StaticCacheable;

    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * @const string The current API version
     */
    const API_VERSION = '2.0';
    /**
     * @const string The Swagger version
     */
    const SWAGGER_VERSION = '2.0';
    /**
     * @const string The private cache file
     */
    const SWAGGER_CACHE_PREFIX = 'swagger:';

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var int|null Native data format of this service - DataFormats enum value.
     */
    protected $nativeFormat = DataFormats::JSON;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     *
     * @return string The cache prefix associated with this service
     */
    protected static function getCachePrefix()
    {
        return static::SWAGGER_CACHE_PREFIX;
    }

    /**
     * @return array
     * @throws ForbiddenException
     */
    protected function handleGET()
    {
        if (empty($this->resource)) {
            if ($this->request->getParameterAsBool(ApiOptions::AS_ACCESS_LIST)) {
                return parent::handleGET();
            }

            if ($this->request->getParameterAsBool('as_resources')) {
                $services = [];
                foreach (ServiceManager::getServiceNames(true) as $serviceName) {
                    if (Session::checkForAnyServicePermissions($serviceName)) {
                        $services[] = ['name' => $serviceName];
                    }
                }

                return ResourcesWrapper::wrapResources($services);
            }
        }

        Log::info('Building Swagger cache');

        $content = [
            'swagger'             => static::SWAGGER_VERSION,
            'securityDefinitions' => ['apiKey' => ['type' => 'apiKey', 'name' => 'api_key', 'in' => 'header']],
            //'host'           => 'df.local',
            //'schemes'        => ['https'],
            'basePath'            => '/api/v2',
            'consumes'            => ['application/json', 'application/xml'],
            'produces'            => ['application/json', 'application/xml'],
        ];

        $paths = [];
        $definitions = static::getDefaultModels();
        $parameters = ApiOptions::getSwaggerGlobalParameters();
        $tags = [];

        if (empty($this->resource)) {
            $description = <<<HTML
HTML;
            $content['info'] = [
                'title'       => 'DreamFactory Live API Documentation',
                'description' => $description,
                'version'     => Config::get('df.api_version', static::API_VERSION),
                //'termsOfServiceUrl' => 'http://www.dreamfactory.com/terms/',
                'contact'     => [
                    'name'  => 'DreamFactory Software, Inc.',
                    'email' => 'support@dreamfactory.com',
                    'url'   => "https://www.dreamfactory.com/"
                ],
                'license'     => [
                    'name' => 'Apache 2.0',
                    'url'  => 'http://www.apache.org/licenses/LICENSE-2.0.html'
                ]
            ];
            foreach (ServiceManager::getServiceNames(true) as $serviceName) {
                if (Session::checkForAnyServicePermissions($serviceName)) {
                    if (!empty($service = ServiceManager::getService($serviceName))) {
                        $tags[$service->getName()] = $service->getDescription();

                        $results = $this->buildSwaggerServiceInfo($service);
                        $paths = array_merge($paths, (array)array_get($results, 'paths'));
                        $definitions = array_merge($definitions, (array)array_get($results, 'definitions'));
                        $parameters = array_merge($parameters, (array)array_get($results, 'parameters'));
                    }
                }
            }
        } else {
            if (!Session::checkForAnyServicePermissions($this->resource)) {
                throw new ForbiddenException("You do not have access to API Docs for the requested service {$this->resource}.");
            }

            if (!empty($service = ServiceManager::getService($this->resource))) {
                $tags[$service->getName()] = $service->getDescription();
                $content['info'] = [
                    'title'       => $service->getLabel(),
                    'description' => $service->getDescription(),
                    'version'     => Config::get('df.api_version', static::API_VERSION),
//                //'termsOfServiceUrl' => 'http://www.dreamfactory.com/terms/',
//                'contact'     => [
//                    'name'  => 'DreamFactory Software, Inc.',
//                    'email' => 'support@dreamfactory.com',
//                    'url'   => "https://www.dreamfactory.com/"
//                ],
//                'license'     => [
//                    'name' => 'Apache 2.0',
//                    'url'  => 'http://www.apache.org/licenses/LICENSE-2.0.html'
//                ]
                ];
                $results = $this->buildSwaggerServiceInfo($service);
                $paths = array_merge($paths, (array)array_get($results, 'paths'));
                $definitions = array_merge($definitions, (array)array_get($results, 'definitions'));
                $parameters = array_merge($parameters, (array)array_get($results, 'parameters'));
            }
        }

        $content['paths'] = $paths;
        $content['definitions'] = $definitions;
        $content['parameters'] = $parameters;
        $content['tags'] = $tags;

        Log::info('Swagger cache build process complete');

        return $content;
    }

    public static function clearCache($role_id)
    {
        static::removeFromCache($role_id);
    }

    public function buildSwaggerServiceInfo(ServiceInterface $service)
    {
        //  Gather the services
        $paths = [];
        $definitions = [];
        $parameters = [];
        $tags = [];
        $name = $service->getName();

        //	Spin through service and pull the events
        $content = $service->getApiDoc();
        if (!empty($content)) {
            $servicePaths = (array)array_get($content, 'paths');
            $serviceDefs = (array)array_get($content, 'definitions');
            $serviceParams = (array)array_get($content, 'parameters');

            if (Session::isSysAdmin()) {
                //  Add to the pile
                $paths = array_merge($paths, $servicePaths);
            } else {
                foreach ($servicePaths as $path => $pathInfo) {
                    $resource = $path;
                    if (false !== stripos($resource, '/' . $name)) {
                        $resource = ltrim(substr($resource, strlen($name) + 1), '/');
                    }
                    $allowed = Session::getServicePermissions($name, $resource);
                    foreach ($pathInfo as $verb => $verbInfo) {
                        // Need to check if verb is really verb
                        try {
                            $action = VerbsMask::toNumeric($verb);
                            if ($action & $allowed) {
                                $paths[$path][$verb] = $verbInfo;
                            }
                        } catch (\Exception $ex) {
                            // not a valid verb, could be part of swagger spec, add it anyway
                            $paths[$path][$verb] = $verbInfo;
                        }
                    }
                }
            }

            //  Add to the pile
            $definitions = array_merge($definitions, $serviceDefs);
            $parameters = array_merge($parameters, $serviceParams);
        }

        return ['tags' => $tags, 'paths' => $paths, 'definitions' => $definitions, 'parameters' => $parameters];
    }

    public static function getDefaultModels()
    {
        $wrapper = ResourcesWrapper::getWrapper();

        return [
            'ResourceList' => [
                'type'       => 'object',
                'properties' => [
                    $wrapper => [
                        'type'        => 'array',
                        'description' => 'Array of accessible resources available to this service.',
                        'items'       => [
                            'type' => 'string',
                        ],
                    ],
                ],
            ],
            'Success'      => [
                'type'       => 'object',
                'properties' => [
                    'success' => [
                        'type'        => 'boolean',
                        'description' => 'True when API call was successful, false or error otherwise.',
                    ],
                ],
            ],
            'Error'        => [
                'type'       => 'object',
                'properties' => [
                    'code'    => [
                        'type'        => 'integer',
                        'format'      => 'int32',
                        'description' => 'Error code.',
                    ],
                    'message' => [
                        'type'        => 'string',
                        'description' => 'String description of the error.',
                    ],
                ],
            ],
        ];
    }

    public static function getApiDocInfo($service)
    {
        $name = strtolower($service->name);
        $capitalized = camelize($service->name);

        return [
            'paths'       => [
                '/' . $name => [
                    'get' =>
                        [
                            'tags'        => [$name],
                            'summary'     => 'get' . $capitalized . '() - Retrieve the Swagger document.',
                            'operationId' => 'get' . $capitalized,
                            'parameters'  => [
                                [
                                    'name'        => 'file',
                                    'description' => 'Download the results of the request as a file.',
                                    'type'        => 'string',
                                    'in'          => 'query',
                                    'required'    => false,
                                ],
                            ],
                            'responses'   => [
                                '200'     => [
                                    'description' => 'Swagger Response',
                                    'schema'      => ['$ref' => '#/definitions/SwaggerResponse']
                                ],
                                'default' => [
                                    'description' => 'Error',
                                    'schema'      => ['$ref' => '#/definitions/Error']
                                ]
                            ],
                            'description' => 'This returns the Swagger file containing all API services.',
                        ],
                ],
            ],
            'definitions' => [
                'SwaggerResponse'   => [
                    'type'       => 'object',
                    'properties' => [
                        'apiVersion'  => [
                            'type'        => 'string',
                            'description' => 'Version of the API.',
                        ],
                        'swagger'     => [
                            'type'        => 'string',
                            'description' => 'Version of the Swagger API.',
                        ],
                        'basePath'    => [
                            'type'        => 'string',
                            'description' => 'Base path of the API.',
                        ],
                        'paths'       => [
                            'type'        => 'array',
                            'description' => 'Array of API paths.',
                            'items'       => [
                                '$ref' => '#/definitions/SwaggerPath',
                            ],
                        ],
                        'definitions' => [
                            'type'        => 'array',
                            'description' => 'Array of API definitions.',
                            'items'       => [
                                '$ref' => '#/definitions/SwaggerDefinition',
                            ],
                        ],
                    ],
                ],
                'SwaggerPath'       => [
                    'type'       => 'object',
                    'properties' => [
                        '__name__' => [
                            'type'        => 'string',
                            'description' => 'Path.',
                        ],
                    ],
                ],
                'SwaggerDefinition' => [
                    'type'       => 'object',
                    'properties' => [
                        '__name__' => [
                            'type'        => 'string',
                            'description' => 'Definition.',
                        ],
                    ],
                ],
            ]
        ];
    }
}
