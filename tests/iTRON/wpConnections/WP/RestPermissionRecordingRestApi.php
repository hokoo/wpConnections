<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\ClientRestApi;
use WP_REST_Request;

class RestPermissionRecordingRestApi extends ClientRestApi
{
    public static array $delegates = [];
    public static array $handler_calls = [];

    public function __construct(Client $client)
    {
        parent::__construct($client);
        self::$delegates[ $client->getName() ] = $this;
    }

    public static function reset(): void
    {
        self::$delegates = [];
        self::$handler_calls = [];
    }

    public function getTheClient(WP_REST_Request $request)
    {
        $this->record_handler(__FUNCTION__, $request);

        return parent::getTheClient($request);
    }

    public function getRelation(WP_REST_Request $request)
    {
        $this->record_handler(__FUNCTION__, $request);

        return parent::getRelation($request);
    }

    public function createConnection(WP_REST_Request $request)
    {
        $this->record_handler(__FUNCTION__, $request);

        return parent::createConnection($request);
    }

    public function getConnection(WP_REST_Request $request)
    {
        $this->record_handler(__FUNCTION__, $request);

        return parent::getConnection($request);
    }

    public function updateConnection(WP_REST_Request $request)
    {
        $this->record_handler(__FUNCTION__, $request);

        return parent::updateConnection($request);
    }

    public function deleteConnection(WP_REST_Request $request)
    {
        $this->record_handler(__FUNCTION__, $request);

        return parent::deleteConnection($request);
    }

    public function updateConnectionMeta(WP_REST_Request $request)
    {
        $this->record_handler(__FUNCTION__, $request);

        return parent::updateConnectionMeta($request);
    }

    public function deleteConnectionMeta(WP_REST_Request $request)
    {
        $this->record_handler(__FUNCTION__, $request);

        return parent::deleteConnectionMeta($request);
    }

    private function record_handler(string $handler, WP_REST_Request $request): void
    {
        self::$handler_calls[] = [ $handler, $request->get_method() ];
    }
}
