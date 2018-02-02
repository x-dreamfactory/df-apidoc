<?php

use DreamFactory\Core\Enums\Verbs;

class SwaggerServiceTest extends \DreamFactory\Core\Testing\TestCase
{
    const RESOURCE = 'db';

    protected $serviceId = 'api_docs';

    public function stage()
    {
        parent::stage();
        if (!$this->serviceExists($this->serviceId)) {
            \DreamFactory\Core\Models\Service::create(
                [
                    'name'        => $this->serviceId,
                    'label'       => 'Live API Docs',
                    'description' => 'API documenting and testing service.',
                    'is_active'   => true,
                    'type'        => 'swagger',
                    'config'      => []
                ]
            );
        }
    }

    /************************************************
     * Testing GET
     ************************************************/

    public function testGetResources()
    {
        // no session should return empty
        $result = $this->service->getResources();
        $this->assertEmpty($result, 'Test for no resources without session.');

        // admin session should return all services that are active
        Session::put('user.is_sys_admin', true);
        $result = $this->service->getResources();
        $this->assertNotEmpty($result, 'Test for all active resources with admin session.');
    }

    public function testGetAccessList()
    {
        // no session should return empty
        $result = $this->service->getAccessList();
        $this->assertEmpty($result, 'Test for empty access list without session.');

        // admin session should return all services that are active
        Session::put('user.is_sys_admin', true);
        $result = $this->service->getAccessList();
        $this->assertNotEmpty($result, 'Test for access list with admin session.');
    }

    public function testGETFullView()
    {
        $rs = $this->makeRequest(Verbs::GET);
        $content = $rs->getContent();

        $this->assertNotEmpty($content);
        $this->assertArraySubset(['openapi' => '3.0.0'], $content);
    }

    public function testGETServiceView()
    {
        $rs = $this->makeRequest(Verbs::GET, static::RESOURCE);
        $this->assertAttributeEquals(403, 'statusCode', $rs, 'Forbidden if no session access for service.');

        Session::put('user.is_sys_admin', true);
        $rs = $this->makeRequest(Verbs::GET, static::RESOURCE);
        $content = $rs->getContent();
        $this->assertNotEmpty($content);
    }

    public function testAddServiceInfo()
    {
        $serviceName = 'db';
        $base = [];
        $content = [
            'paths' => [
                '/'        => [
                    'get' => [
                        'summary'     => 'Retrieve the resources.',
                        'description' => 'Returns a listing of resources on this service.',
                        'operationId' => 'getDb',
                        'parameters'  => [],
                        'responses'   => [
                            '200' => ['$ref' => '#/components/responses/Response']
                        ],
                    ],
                ],
                '/_schema' => [
                    'get'  =>
                        [
                            'summary'     => 'Retrieve the schema resources.',
                            'description' => 'Returns the schemas available and their properties.',
                            'operationId' => 'getDbSchemas',
                            'parameters'  => [],
                            'responses'   => [
                                '200' => ['$ref' => '#/components/responses/SchemasResponse']
                            ],
                        ],
                    'post' =>
                        [
                            'summary'     => 'Create the schema resources.',
                            'description' => 'Creates the schemas available and their properties.',
                            'operationId' => 'createDbSchemas',
                            'parameters'  => [],
                            'responses'   => [
                                '200' => ['$ref' => '#/components/responses/SchemasResponse']
                            ],
                        ],
                ],
                '/_table'  => [
                    'get'  =>
                        [
                            'summary'     => 'Retrieve the table resources.',
                            'description' => 'Returns the table available and their properties.',
                            'operationId' => 'getDbTables',
                            'parameters'  => [],
                            'responses'   => [
                                '200' => ['$ref' => '#/components/responses/TablesResponse']
                            ],
                        ],
                    'post' =>
                        [
                            'summary'     => 'Create the table resources.',
                            'description' => 'Creates the tables available and their properties.',
                            'operationId' => 'createDbTables',
                            'parameters'  => [],
                            'responses'   => [
                                '200' => ['$ref' => '#/components/responses/TablesResponse']
                            ],
                        ],
                ],
            ],
        ];

        // no paths without session
        $result = $this->invokeMethod($this->service, 'addServiceInfo', [&$base, $serviceName, $content, true]);
        $this->assertArrayHasKey('paths', $result, 'Testing addServiceInfo without session');
        $this->assertEmpty($result['paths']);

        Session::put('user.is_sys_admin', true);

        // single service
        $result = $this->invokeMethod($this->service, 'addServiceInfo', [&$base, $serviceName, $content, true]);
        $this->assertArrayHasKey('paths', $result, 'Testing addServiceInfo for single service view');
        $this->assertNotEmpty($result['paths']);

        // full scope
        $base = [];
        $result = $this->invokeMethod($this->service, 'addServiceInfo', [&$base, $serviceName, $content, false]);
        $this->assertArrayHasKey('paths', $result, 'Testing addServiceInfo for full system view');
        $this->assertNotEmpty($result['paths']);

        $content = [
            'paths' => [
                '/db'        => [
                    'get' => [
                        'summary'     => 'Retrieve the resources.',
                        'description' => 'Returns a listing of resources on this service.',
                        'operationId' => 'getDb',
                        'parameters'  => [],
                        'responses'   => [
                            '200' => ['$ref' => '#/components/responses/Response']
                        ],
                    ],
                ],
                '/db/_schema' => [
                    'get'  =>
                        [
                            'summary'     => 'Retrieve the schema resources.',
                            'description' => 'Returns the schemas available and their properties.',
                            'operationId' => 'getDbSchemas',
                            'parameters'  => [],
                            'responses'   => [
                                '200' => ['$ref' => '#/components/responses/SchemasResponse']
                            ],
                        ],
                    'post' =>
                        [
                            'summary'     => 'Create the schema resources.',
                            'description' => 'Creates the schemas available and their properties.',
                            'operationId' => 'createDbSchemas',
                            'parameters'  => [],
                            'responses'   => [
                                '200' => ['$ref' => '#/components/responses/SchemasResponse']
                            ],
                        ],
                ],
                '/db/_table'  => [
                    'get'  =>
                        [
                            'summary'     => 'Retrieve the table resources.',
                            'description' => 'Returns the table available and their properties.',
                            'operationId' => 'getDbTables',
                            'parameters'  => [],
                            'responses'   => [
                                '200' => ['$ref' => '#/components/responses/TablesResponse']
                            ],
                        ],
                    'post' =>
                        [
                            'summary'     => 'Create the table resources.',
                            'description' => 'Creates the tables available and their properties.',
                            'operationId' => 'createDbTables',
                            'parameters'  => [],
                            'responses'   => [
                                '200' => ['$ref' => '#/components/responses/TablesResponse']
                            ],
                        ],
                ],
            ],
        ];

        // single service
        $result = $this->invokeMethod($this->service, 'addServiceInfo', [&$base, $serviceName, $content, true]);
        $this->assertArrayHasKey('paths', $result, 'Testing addServiceInfo for single service view');
        $this->assertNotEmpty($result['paths']);

        // full scope
        $base = [];
        $result = $this->invokeMethod($this->service, 'addServiceInfo', [&$base, $serviceName, $content, false]);
        $this->assertArrayHasKey('paths', $result, 'Testing addServiceInfo for full system view');
        $this->assertNotEmpty($result['paths']);
    }
}