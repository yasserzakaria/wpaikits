<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPAK_Vertex_Schema {

	public static function tools( array $tools ): array {
		return array_map( array( __CLASS__, 'tool' ), $tools );
	}

	private static function tool( array $tool ): array {
		if ( isset( $tool['parameters'] ) ) {
			$tool['parameters'] = self::schema( $tool['parameters'], 'parameters' );
		}
		return $tool;
	}

	private static function schema( $value, string $key = '' ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( 'properties' === $key ) {
			return self::properties( $value );
		}

		if ( self::is_list( $value ) ) {
			return array_map(
				static fn( $item ) => self::schema( $item ),
				$value
			);
		}

		$clean = array();
		foreach ( $value as $child_key => $child_value ) {
			$clean[ $child_key ] = self::schema( $child_value, (string) $child_key );
		}
		return $clean;
	}

	private static function properties( array $properties ) {
		if ( empty( $properties ) ) {
			return new stdClass();
		}

		$clean = array();
		foreach ( $properties as $name => $schema ) {
			if ( is_int( $name ) ) {
				continue;
			}
			$clean[ $name ] = self::schema( $schema, (string) $name );
		}

		return empty( $clean ) ? new stdClass() : $clean;
	}

	private static function is_list( array $value ): bool {
		if ( empty( $value ) ) {
			return true;
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
