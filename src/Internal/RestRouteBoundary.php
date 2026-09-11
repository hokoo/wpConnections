<?php

namespace iTRON\wpConnections\Internal;

use Closure;
use iTRON\wpConnections\ClientRestApi;
use WP_Error;
use WP_REST_Request;

/**
 * Context-neutral callbacks retained by a WordPress REST server.
 *
 * @internal
 */
final class RestRouteBoundary
{
    private const REGISTRATION_TOKEN_ATTRIBUTE = '_wpconnections_rest_registration';

    private const HANDLERS = [
        'getTheClient',
        'getRelation',
        'createConnection',
        'getConnection',
        'updateConnection',
        'deleteConnection',
        'updateConnectionMeta',
        'deleteConnectionMeta',
    ];

    public function __construct(
        private RestRouteRegistry $registry,
        private RestRouteIdentity $identity
    ) {
    }

    /**
     * @return bool|WP_Error
     */
    public function checkPermissions(WP_REST_Request $request)
    {
        $attributes = $request->get_attributes();
        $handler = $attributes['callback'][1] ?? null;

        if (! is_string($handler) || ! in_array($handler, self::HANDLERS, true)) {
            return $this->noRouteError();
        }

        $target = $this->registry->resolve($this->identity);
        if (null === $target) {
            return $this->noRouteError();
        }

        /** @var ClientRestApi $delegate */
        $delegate = $target['delegate'];
        $permission = $this->withDelegateAttributes(
            $request,
            $delegate,
            $handler,
            static fn () => $delegate->checkPermissions($request)
        );

        $attributes = $request->get_attributes();
        $attributes[ self::REGISTRATION_TOKEN_ATTRIBUTE ] = $target['token'];
        $request->set_attributes($attributes);

        return $permission;
    }

    public function getTheClient(WP_REST_Request $request)
    {
        return $this->dispatch(__FUNCTION__, $request);
    }

    public function getRelation(WP_REST_Request $request)
    {
        return $this->dispatch(__FUNCTION__, $request);
    }

    public function createConnection(WP_REST_Request $request)
    {
        return $this->dispatch(__FUNCTION__, $request);
    }

    public function getConnection(WP_REST_Request $request)
    {
        return $this->dispatch(__FUNCTION__, $request);
    }

    public function updateConnection(WP_REST_Request $request)
    {
        return $this->dispatch(__FUNCTION__, $request);
    }

    public function deleteConnection(WP_REST_Request $request)
    {
        return $this->dispatch(__FUNCTION__, $request);
    }

    public function updateConnectionMeta(WP_REST_Request $request)
    {
        return $this->dispatch(__FUNCTION__, $request);
    }

    public function deleteConnectionMeta(WP_REST_Request $request)
    {
        return $this->dispatch(__FUNCTION__, $request);
    }

    /**
     * @return mixed
     */
    private function dispatch(string $handler, WP_REST_Request $request)
    {
        $target = $this->registry->resolve($this->identity);
        $token = $request->get_attributes()[ self::REGISTRATION_TOKEN_ATTRIBUTE ] ?? null;

        if (null === $target || $target['token'] !== $token) {
            return $this->noRouteError();
        }

        /** @var ClientRestApi $delegate */
        $delegate = $target['delegate'];

        return $this->withDelegateAttributes(
            $request,
            $delegate,
            $handler,
            static fn () => $delegate->{$handler}($request)
        );
    }

    /**
     * @return mixed
     */
    private function withDelegateAttributes(
        WP_REST_Request $request,
        ClientRestApi $delegate,
        string $handler,
        Closure $operation
    ) {
        $boundaryAttributes = $request->get_attributes();
        $delegateAttributes = $boundaryAttributes;
        $delegateAttributes['callback'] = [ $delegate, $handler ];
        $delegateAttributes['permission_callback'] = [ $delegate, 'checkPermissions' ];
        $request->set_attributes($delegateAttributes);

        try {
            return $operation();
        } finally {
            $restoredAttributes = $request->get_attributes();
            $restoredAttributes['callback'] = $boundaryAttributes['callback'] ?? null;
            $restoredAttributes['permission_callback'] =
                $boundaryAttributes['permission_callback'] ?? null;
            $request->set_attributes($restoredAttributes);
        }
    }

    private function noRouteError(): WP_Error
    {
        return new WP_Error(
            'rest_no_route',
            __('No route was found matching the URL and request method.'),
            [ 'status' => 404 ]
        );
    }
}
