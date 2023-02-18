<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use TypeError;

class Factory {
	/**
	 * @throws ClientRegisterFail
	 */
	public static function getStorage( Client $client ) : Storage {
		$storageClass = apply_filters( 'wpConnections/factory/getStorage/class', WPStorage::class, $client );
		if ( ! class_exists( $storageClass ) ) {
			throw new ClientRegisterFail( 'A storage class does not exist. See filter hooks [wpConnections/factory/getStorage/class]' );
		}

		try {
			return new $storageClass( $client );
		} catch ( TypeError $e ) {
			throw new ClientRegisterFail( 'A storage class does not inherit Abstract\Storage. See filter hooks [wpConnections/factory/getStorage/class]' );
		}
	}

	/**
	 * @throws ClientRegisterFail
	 */
	public static function getRestApi( Client $client ): ClientRestApi {
		$restApiClass = apply_filters( 'wpConnections/factory/getRestApi/class', ClientRestApi::class, $client );
		if ( ! class_exists( $restApiClass ) ) {
			throw new ClientRegisterFail( 'A REST API class does not exist. See filter hooks [wpConnections/factory/getRestApi/class]' );
		}

		try {
			return new $restApiClass( $client );
		} catch ( TypeError $e ) {
			throw new ClientRegisterFail( 'A REST API class does not inherit ClientRestApi. See filter hooks [wpConnections/factory/getStorage/class]' );
		}
	}
}
