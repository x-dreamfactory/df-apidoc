<?php

namespace DreamFactory\Core\ApiDoc\Services;

use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Exceptions\BadRequestException;
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
    const OPENAPI_VERSION = '3.0.0';

    //*************************************************************************
    //	Methods
    //*************************************************************************

    public function getResources($onlyHandlers = false)
    {
        if ($onlyHandlers) {
            return [];
        }

        $services = [];
        foreach (ServiceManager::getServiceNames(true) as $serviceName) {
            if (Session::allowsServiceAccess($serviceName)) {
                $services[] = [static::getResourceIdentifier() => $serviceName];
            }
        }

        return $services;
    }

    public function getAccessList()
    {
        $list = parent::getAccessList();
        $nameField = static::getResourceIdentifier();
        foreach ($this->getResources() as $resource) {
            $name = array_get($resource, $nameField);
            $list[] = $name;
        }

        return $list;
    }

    /**
     * @return array
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws ServiceUnavailableException
     */
    protected function handleGET()
    {
        if (empty($this->resource) && $this->request->getParameterAsBool(ApiOptions::AS_ACCESS_LIST) ||
            $this->request->getParameterAsBool(ApiOptions::AS_LIST)) {
            return parent::handleGET(); // returning a list of services here
        }

        $content = [
            'openapi'    => static::OPENAPI_VERSION,
            'servers'    => [
                [
                    'url'         => '/api/v2',
                    'description' => '',
//                    'variables'   => [
//                        'version' => [
//                            'enum'    => ['v1', 'v2'],
//                            'default' => 'v2'
//                        ]
//                    ]
                ]
            ],
            'components' => [
                'securitySchemes' => [
                    'BasicAuth'          => [
                        'type'   => 'http',
                        'scheme' => 'basic',
                    ],
                    'BearerAuth'         => [
                        'type'   => 'http',
                        'scheme' => 'bearer',
                    ],
                    'ApiKeyQuery'        => [
                        'type' => 'apiKey',
                        'in'   => 'query',
                        'name' => 'api_key',
                    ],
                    'ApiKeyHeader'       => [
                        'type' => 'apiKey',
                        'in'   => 'header',
                        'name' => 'X-DreamFactory-API-Key',
                    ],
                    'SessionTokenQuery'  => [
                        'type' => 'apiKey',
                        'in'   => 'query',
                        'name' => 'session_token',
                    ],
                    'SessionTokenHeader' => [
                        'type' => 'apiKey',
                        'in'   => 'header',
                        'name' => 'X-DreamFactory-Session-Token',
                    ],
                ],
                'responses'       => $this->getDefaultApiDocResponses(),
                'schemas'         => $this->getDefaultApiDocSchemas(),
            ],
            'security'   => [
                ['BasicAuth' => []],
                ['BearerAuth' => []],
                ['ApiKeyQuery' => []],
                ['ApiKeyHeader' => []],
                ['SessionTokenQuery' => []],
                ['SessionTokenHeader' => []],
            ],
            'tags'       => [],
        ];

        if (!empty($this->resource)) {
            Log::info("Building OpenAPI specification for service {$this->resource}.");

            if (!Session::allowsServiceAccess($this->resource)) {
                throw new ForbiddenException("You do not have access to API Docs for the requested service {$this->resource}.");
            }

            $service = ServiceManager::getService($this->resource);
            if (empty($doc = $service->getApiDoc($this->request->getParameterAsBool(ApiOptions::REFRESH)))) {
                throw new ServiceUnavailableException("There are no defined API Docs for the requested service {$this->resource}.");
            }

            // update headings for single service usage
            $content['servers'][0]['url'] = $content['servers'][0]['url'] . '/' . $service->getName();
            $content['info'] = [
                'title'       => $service->getLabel(),
                'description' => $service->getDescription(),
//                            "termsOfService"  => "http://example.com/terms/",
//                            "contact"         => [
//                                "name"  => "API Support",
//                                "url"   => "http://www.example.com/support",
//                                "email" => "support@example.com"
//                            ],
//                            "license"         => [
//                                "name" => "Apache 2.0",
//                                "url"  => "http://www.apache.org/licenses/LICENSE-2.0.html"
//                            ],
                'version'     => Config::get('df.api_version'),
            ];

            $this->addServiceInfo($content, $service->getName(), $doc, true);

            Log::info('Swagger file build process complete.');

            return $content;
        }

        Log::info('Building Swagger file for this instance.');

        $content['info'] = [
            'title'       => 'DreamFactory Live API Documentation',
            'description' => '',
//                            "termsOfService"  => "http://example.com/terms/",
//                            "contact"         => [
//                                "name"  => "API Support",
//                                "url"   => "http://www.example.com/support",
//                                "email" => "support@example.com"
//                            ],
//                            "license"         => [
//                                "name" => "Apache 2.0",
//                                "url"  => "http://www.apache.org/licenses/LICENSE-2.0.html"
//                            ],
            'version'     => Config::get('df.api_version'),
        ];

        $nameField = static::getResourceIdentifier();
        foreach ($this->getResources() as $resource) {
            $serviceName = array_get($resource, $nameField);
            try {
                if (!empty($service = ServiceManager::getService($serviceName))) {
                    if (!empty($doc = $service->getApiDoc($this->request->getParameterAsBool(ApiOptions::REFRESH)))) {
                        $this->addServiceInfo($content, $serviceName, $doc, false);
                        $content['tags'][] = [
                            'name'        => $service->getName(),
                            'description' => (string)$service->getDescription()
                        ];
                    }
                }
            } catch (\Exception $ex) {
                Log::info("Failed to build Swagger file for service $serviceName. {$ex->getMessage()}");
            }
        }

        Log::info('Swagger file build process complete.');

        return $content;
    }

    /**
     * @param array  $base
     * @param string $name
     * @param array  $content
     * @param bool   $single_service_format
     * @return array
     */
    protected function addServiceInfo(array &$base, $name, array $content, $single_service_format = false)
    {
        $paths = [];
        $servicePaths = (array)array_get($content, 'paths');
        $isAdmin = Session::isSysAdmin();
        // tricky here, loop through all indexes to check if all start with service name,
        // otherwise need to prepend service name to all.
        $prependServiceName = (!empty(array_filter(array_keys($servicePaths), function ($k) use ($name) {
            $k = ltrim($k, '/');
            if (false !== strpos($k, '/')) {
                $k = strstr($k, '/', true);
            }

            return (0 !== strcasecmp($name, $k));
        })));

        //	Spin through service and parse out the paths and verbs not permitted
        foreach ($servicePaths as $path => $pathInfo) {
            $resource = $path;
            if (false !== stripos($resource, '/' . $name)) {
                $resource = ltrim(substr($resource, strlen($name) + 1), '/');
            }
            if (!$single_service_format && $prependServiceName) {
                $path = '/' . $name . $path;
            }
            $allowed = Session::getServicePermissions($name, $resource);
            foreach ($pathInfo as $verb => $verbInfo) {
                // Need to check if verb is really verb
                try {
                    $action = VerbsMask::toNumeric($verb);
                    if ($isAdmin || ($action & $allowed)) {
                        if (is_array($verbInfo)) {
                            if (isset($verbInfo['responses']) && !isset($verbInfo['responses']['default'])) {
                                // no default declared, add the error handling default
                                $verbInfo['responses']['default'] = ['$ref' => '#/components/responses/Error'];
                            }
                        }
                        if ($single_service_format) {
                            $verbInfo['tags'] = array_merge((array)array_get($verbInfo, 'tags'), [$name]);
                        } else {
                            // If we leave the incoming tags, they get bubbled up to our service-level
                            // and possibly confuse the whole interface. Replace with our service name tag.
                            $verbInfo['tags'] = [$name];
                        }

                        $paths[$path][$verb] = $verbInfo;
                    }
                } catch (\Exception $ex) {
                    // not a valid verb, could be part of swagger spec, add it anyway
                    $paths[$path][$verb] = $verbInfo;
                }
            }
        }

        $base['paths'] = array_merge((array)array_get($base, 'paths'), $paths);
        if (isset($content['components'])) {
            if (isset($content['components']['requestBodies'])) {
                $base['components']['requestBodies'] = array_merge((array)array_get($base, 'components.requestBodies'),
                    (array)$content['components']['requestBodies']);
            }
            if (isset($content['components']['responses'])) {
                $base['components']['responses'] = array_merge((array)array_get($base, 'components.responses'),
                    (array)$content['components']['responses']);
            }
            if (isset($content['components']['schemas'])) {
                $base['components']['schemas'] = array_merge((array)array_get($base, 'components.schemas'),
                    (array)$content['components']['schemas']);
            }
        }

        return $base;
    }

    protected function getApiDocPaths()
    {
        $capitalized = camelize($this->name);

        return [
            '/'               => [
                'get' => [
                        'summary'     => 'Retrieve the whole system specification document.',
                        'description' => 'This returns the Open API specification file containing all API services.',
                        'operationId' => 'get' . $capitalized,
                        'parameters'  => [
                            [
                                'name'        => 'file',
                                'description' => 'Download the results of the request as a file.',
                                'schema'      => ['type' => 'string'],
                                'in'          => 'query',
                            ],
                            ApiOptions::documentOption(ApiOptions::AS_ACCESS_LIST),
                            ApiOptions::documentOption(ApiOptions::AS_LIST),
                            ApiOptions::documentOption(ApiOptions::REFRESH),
                        ],
                        'responses'   => [
                            '200' => ['$ref' => '#/components/responses/ApiDocsResponse']
//                            '200' => ['$ref' => '#/components/responses/ResourceList']
                        ],
                    ],
            ],
            '/{service_name}' => [
                'get' =>
                    [
                        'summary'     => 'Retrieve the specification for a specific service.',
                        'description' => 'This returns the Open API specification file for the requested service.',
                        'operationId' => 'get' . $capitalized . 'ByService',
                        'parameters'  => [
                            [
                                'name'        => 'service_name',
                                'description' => 'Name of the service to retrieve the specification for.',
                                'schema'      => ['type' => 'string'],
                                'in'          => 'path',
                                'required'    => true,
                            ],
                            ApiOptions::documentOption(ApiOptions::REFRESH),
                        ],
                        'responses'   => [
                            '200' => ['$ref' => '#/components/responses/ApiDocsResponse']
                        ],
                    ],
            ],
        ];
    }

    protected function getApiDocResponses()
    {
        return [
            'ApiDocsResponse' => [
                'description' => 'Open Api Specification',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ApiDocsResponse']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/ApiDocsResponse']
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocSchemas()
    {
        $schemas = [
            'ApiDocsResponse' => [
                'type'       => 'object',
                'properties' => [
                    'openapi'      => [
                        'type'        => 'string',
                        'description' => 'Version of the Open API Specification.',
                    ],
                    'info'         => [
                        'type'        => 'object',
                        'description' => 'Additional information about the API.',
                        'properties'  => [
                            'title'          => [
                                'type'        => 'string',
                                'description' => 'Title of the API.',
                            ],
                            'description'    => [
                                'type'        => 'string',
                                'description' => 'Description of the API.',
                            ],
                            'termsOfService' => [
                                'type'        => 'string',
                                'description' => 'A URL to the Terms of Service for the API.',
                            ],
                            'contact'        => [
                                'type'        => 'object',
                                'description' => 'The contact information for the exposed API.',
                                'properties'  => [
                                    'name'  => [
                                        'type'        => 'string',
                                        'description' => 'The identifying name of the contact person/organization.',
                                    ],
                                    'url'   => [
                                        'type'        => 'string',
                                        'description' => 'The URL pointing to the contact information.',
                                    ],
                                    'email' => [
                                        'type'        => 'string',
                                        'description' => 'The email address of the contact person/organization.',
                                    ],
                                ],
                            ],
                            'license'        => [
                                'type'        => 'object',
                                'description' => 'The license information for the exposed API.',
                                'properties'  => [
                                    'name' => [
                                        'type'        => 'string',
                                        'description' => 'The license name used for the API.',
                                    ],
                                    'url'  => [
                                        'type'        => 'string',
                                        'description' => 'A URL to the license used for the API.',
                                    ],
                                ],
                            ],
                            'version'        => [
                                'type'        => 'string',
                                'description' => 'Version of the API.',
                            ],
                        ],
                    ],
                    'servers'      => [
                        'type'        => 'array',
                        'description' => 'Array of API paths.',
                        'items'       => [
                            '$ref' => '#/components/schemas/ApiDocsServer',
                        ],
                    ],
                    'paths'        => [
                        'type'        => 'object',
                        'description' => 'Array of API paths.',
                    ],
                    'components'   => [
                        'type'       => 'object',
                        'properties' => [
                            'schemas'         => [
                                'type'        => 'object',
                                'description' => 'Reusable schemas.',
                            ],
                            'responses'       => [
                                'type'        => 'object',
                                'description' => 'Reusable responses.',
                            ],
                            'parameters'      => [
                                'type'        => 'object',
                                'description' => 'Reusable parameters.',
                            ],
                            'examples'        => [
                                'type'        => 'object',
                                'description' => 'Reusable examples.',
                            ],
                            'requestBodies'   => [
                                'type'        => 'object',
                                'description' => 'Reusable requestBodies.',
                            ],
                            'headers'         => [
                                'type'        => 'object',
                                'description' => 'Reusable headers.',
                            ],
                            'securitySchemes' => [
                                'type'        => 'object',
                                'description' => 'Reusable security schemes.',
                            ],
                            'links'           => [
                                'type'        => 'object',
                                'description' => 'Reusable links.',
                            ],
                            'callbacks'       => [
                                'type'        => 'object',
                                'description' => 'Reusable callbacks.',
                            ],
                        ],
                    ],
                    'security'     => [
                        'type'        => 'array',
                        'description' => 'Array of API paths.',
                        'items'       => [
                            '$ref' => '#/components/schemas/ApiDocsSecurity',
                        ],
                    ],
                    'tags'         => [
                        'type'        => 'array',
                        'description' => 'Array of API paths.',
                        'items'       => [
                            '$ref' => '#/components/schemas/ApiDocsTag',
                        ],
                    ],
                    'externalDocs' => [
                        'type'       => 'object',
                        'properties' => [
                            'url'         => [
                                'type'        => 'string',
                                'description' => 'The URL for the target documentation.',
                            ],
                            'description' => [
                                'type'        => 'string',
                                'description' => 'A short description of the target documentation.',
                            ],
                        ],
                    ],
                ],
            ],
            'ApiDocsServer'   => [
                'type'       => 'object',
                'properties' => [
                    'url'         => [
                        'type'        => 'string',
                        'description' => 'A URL to the target host.',
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'An optional string describing the host designated by the URL.',
                    ],
                ],
            ],
            'ApiDocsSecurity' => [
                'type'       => 'object',
                'properties' => [
                    '__name__' => [
                        'type'        => 'string',
                        'description' => 'Security Requirements.',
                    ],
                ],
            ],
            'ApiDocsTag'      => [
                'type'       => 'object',
                'properties' => [
                    'name'        => [
                        'type'        => 'string',
                        'description' => 'The name of the tag.',
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'A short description for the tag.',
                    ],
                ],
            ],
        ];

        return $schemas;
    }

    protected function getDefaultApiDocResponses()
    {
        return [
            'Success'      => [
                'description' => 'Success Response',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/Success']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/Success']
                    ],
                ],
            ],
            'Error'        => [
                'description' => 'Error Response',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/Error']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/Error']
                    ],
                ],
            ],
            'ResourceList' => [
                'description' => 'Resource List Response',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ResourceList']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/ResourceList']
                    ],
                ],
            ],
        ];
    }

    protected function getDefaultApiDocSchemas()
    {
        $wrapper = ResourcesWrapper::getWrapper();

        return [
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
        ];
    }
}
