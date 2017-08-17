<?php

namespace DreamFactory\Core\ApiDoc\Services;

use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Exceptions\ServiceUnavailableException;
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
    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * @const string The OpenAPI Spec/Swagger version
     */
    const SWAGGER_VERSION = '2.0';

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * @return array
     * @throws ForbiddenException
     * @throws ServiceUnavailableException
     */
    protected function handleGET()
    {
        if (!empty($this->resource)) {
            Log::info("Building Swagger file for service {$this->resource}.");

            if (!Session::checkForAnyServicePermissions($this->resource)) {
                throw new ForbiddenException("You do not have access to API Docs for the requested service {$this->resource}.");
            }

            $service = ServiceManager::getService($this->resource);
            if (empty($doc = $service->getApiDoc())) {
                throw new ServiceUnavailableException("There are no defined API Docs for the requested service {$this->resource}.");
            }

            $results = $this->buildSwaggerServiceInfo($service->getName(), $doc);

            $tags = [['name' => $service->getName(), 'description' => (string)$service->getDescription()]];
            $paths = (array)array_get($results, 'paths');
            $definitions = (array)array_get($results, 'definitions');
            $parameters = (array)array_get($results, 'parameters');

            $content = [
                'swagger'             => static::SWAGGER_VERSION,
                'securityDefinitions' => ['apiKey' => ['type' => 'apiKey', 'name' => 'api_key', 'in' => 'header']],
                //'host'           => 'df.local',
                //'schemes'        => ['https'],
                'basePath'            => '/api/v2',
                'info'                => [
                    'title'       => $service->getLabel(),
                    'description' => $service->getDescription(),
                    'version'     => Config::get('df.api_version'),
                ],
                'consumes'            => ['application/json', 'application/xml'],
                'produces'            => ['application/json', 'application/xml'],
                'paths'               => $paths,
                'definitions'         => $definitions,
                'parameters'          => $parameters,
                'tags'                => $tags,
            ];

            Log::info('Swagger file build process complete.');

            return $content;
        }

        if ($this->request->getParameterAsBool(ApiOptions::AS_ACCESS_LIST)) {
            return ResourcesWrapper::wrapResources($this->getAccessList());
        }

        Log::info('Building Swagger file for this instance.');

        $paths = [];
        $definitions = [];
        $parameters = [];
        $tags = [];

        foreach (ServiceManager::getServiceNames(true) as $serviceName) {
            if (Session::checkForAnyServicePermissions($serviceName)) {
                try {
                    if (!empty($service = ServiceManager::getService($serviceName))) {
                        if (!empty($doc = $service->getApiDoc())) {
                            $results = $this->buildSwaggerServiceInfo($serviceName, $doc);
                            $paths = array_merge($paths, (array)array_get($results, 'paths'));
                            $definitions = array_merge($definitions, (array)array_get($results, 'definitions'));
                            $parameters = array_merge($parameters, (array)array_get($results, 'parameters'));
                            $tags[] = ['name'        => $service->getName(),
                                       'description' => (string)$service->getDescription()
                            ];
                        }
                    }
                } catch (\Exception $ex) {
                    Log::info("Failed to build Swagger file for service $serviceName. {$ex->getMessage()}");
                }
            }
        }

        $description = <<<HTML
HTML;
        $content = [
            'swagger'             => static::SWAGGER_VERSION,
            'securityDefinitions' => ['apiKey' => ['type' => 'apiKey', 'name' => 'api_key', 'in' => 'header']],
            //'host'           => 'df.local',
            //'schemes'        => ['https'],
            'basePath'            => '/api/v2',
            'info'                => [
                'title'       => 'DreamFactory Live API Documentation',
                'description' => $description,
                'version'     => Config::get('df.api_version'),
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
            ],
            'consumes'            => ['application/json', 'application/xml'],
            'produces'            => ['application/json', 'application/xml'],
            'paths'               => $paths,
            'definitions'         => $definitions,
            'parameters'          => $parameters,
            'tags'                => $tags,
        ];

        Log::info('Swagger file build process complete.');

        return $content;
    }

    protected function buildSwaggerServiceInfo($name, array $content)
    {
        //  Gather the services
        $paths = [];
        $definitions = [];
        $parameters = [];

        //	Spin through service and pull the events
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

        return ['paths' => $paths, 'definitions' => $definitions, 'parameters' => $parameters];
    }

    public static function getApiDocInfo($service)
    {
        $wrapper = ResourcesWrapper::getWrapper();
        $name = strtolower($service->name);
        $capitalized = camelize($service->name);

        return [
            'paths'       => [
                '/' . $name                              => [
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
                '/' . $name . '/_service'                => [
                    'get' =>
                        [
                            'tags'        => [$name],
                            'summary'     => 'get' . $capitalized . 'Services() - Retrieve the list of specific services.',
                            'operationId' => 'get' . $capitalized . 'Services',
                            'parameters'  => [
                            ],
                            'responses'   => [
                                '200'     => [
                                    'description' => 'Swagger Response',
                                    'schema'      => ['$ref' => '#/definitions/SwaggerServices']
                                ],
                                'default' => [
                                    'description' => 'Error',
                                    'schema'      => ['$ref' => '#/definitions/Error']
                                ]
                            ],
                            'description' => 'This returns the available services.',
                        ],
                ],
                '/' . $name . '/_service/{service_name}' => [
                    'get' =>
                        [
                            'tags'        => [$name],
                            'summary'     => 'get' . $capitalized . 'Service() - Retrieve the swagger for a specific services.',
                            'operationId' => 'get' . $capitalized . 'Service',
                            'parameters'  => [
                                [
                                    'name'        => 'service_name',
                                    'description' => 'Name of the service to retrieve the swagger doc for.',
                                    'type'        => 'string',
                                    'in'          => 'path',
                                    'required'    => true,
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
                            'description' => 'This returns the Swagger file containing the requested API services.',
                        ],
                ],
                '/' . $name . '/_service_type'           => [
                    'get' =>
                        [
                            'tags'        => [$name],
                            'summary'     => 'get' . $capitalized . 'ServiceTypes() - Retrieve the available service types.',
                            'operationId' => 'get' . $capitalized . 'ServiceTypes',
                            'parameters'  => [
                            ],
                            'responses'   => [
                                '200'     => [
                                    'description' => 'Swagger Response',
                                    'schema'      => ['$ref' => '#/definitions/SwaggerServiceTypes']
                                ],
                                'default' => [
                                    'description' => 'Error',
                                    'schema'      => ['$ref' => '#/definitions/Error']
                                ]
                            ],
                            'description' => 'This returns the service types.',
                        ],
                ],
            ],
            'definitions' => [
                'SwaggerResponse'     => [
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
                'SwaggerPath'         => [
                    'type'       => 'object',
                    'properties' => [
                        '__name__' => [
                            'type'        => 'string',
                            'description' => 'Path.',
                        ],
                    ],
                ],
                'SwaggerDefinition'   => [
                    'type'       => 'object',
                    'properties' => [
                        '__name__' => [
                            'type'        => 'string',
                            'description' => 'Definition.',
                        ],
                    ],
                ],
                'SwaggerServices'     => [
                    'type'       => 'object',
                    'properties' => [
                        $wrapper => [
                            'type'        => 'array',
                            'description' => 'Array of services and their properties.',
                            'items'       => [
                                '$ref' => '#/definitions/SwaggerService',
                            ],
                        ],
                    ],
                ],
                'SwaggerService'      => [
                    'type'       => 'object',
                    'properties' => [
                        'name' => [
                            'type'        => 'string',
                            'description' => 'Name of the service.',
                        ],
                        'label' => [
                            'type'        => 'string',
                            'description' => 'Label for the service.',
                        ],
                        'type' => [
                            'type'        => 'string',
                            'description' => 'Type of the service.',
                        ],
                        'description' => [
                            'type'        => 'string',
                            'description' => 'Description of the service.',
                        ],
                    ],
                ],
                'SwaggerServiceTypes' => [
                    'type'       => 'object',
                    'properties' => [
                        $wrapper => [
                            'type'        => 'array',
                            'description' => 'Array of service types and their properties.',
                            'items'       => [
                                '$ref' => '#/definitions/SwaggerServiceType',
                            ],
                        ],
                    ],
                ],
                'SwaggerServiceType'  => [
                    'type'       => 'object',
                    'properties' => [
                        'name' => [
                            'type'        => 'string',
                            'description' => 'Name of the service type.',
                        ],
                        'label' => [
                            'type'        => 'string',
                            'description' => 'Label for the service type.',
                        ],
                        'group' => [
                            'type'        => 'string',
                            'description' => 'Group to which the service type belongs.',
                        ],
                        'description' => [
                            'type'        => 'string',
                            'description' => 'Description of the service type.',
                        ],
                    ],
                ],
            ]
        ];
    }
}
