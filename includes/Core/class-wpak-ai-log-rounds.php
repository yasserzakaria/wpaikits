<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_AI_Log_Rounds {

	public static function from_logs( array $logs ): array {
		$rounds = array();
		foreach ( $logs as $log ) {
			$item = WPAK_AI_Log_Summarizer::summarize( $log );
			$key = self::round_key( $item['request'] );
			$last = array_key_last( $rounds );

			if ( null !== $last && $rounds[ $last ]['key'] === $key ) {
				$rounds[ $last ] = self::merge( $rounds[ $last ], $item );
				continue;
			}

			$rounds[] = self::new_round( $key, $item );
		}

		return array_map(
			static function ( array $round ): array {
				unset( $round['key'], $round['sort_time'] );
				return $round;
			},
			$rounds
		);
	}

	private static function new_round( string $key, array $item ): array {
		return array(
			'key' => $key,
			'time' => $item['time'],
			'sort_time' => $item['time'],
			'tokens' => self::token_count( $item['tokens'] ),
		);
	}

	private static function merge( array $round, array $item ): array {
		$round['time'] = self::earlier_time( $round['time'], $item['time'] );
		$round['sort_time'] = self::later_time( $round['sort_time'], $item['time'] );
		$round['tokens'] += self::token_count( $item['tokens'] );
		return $round;
	}

	private static function round_key( string $request ): string {
		$request = strtolower( trim( preg_replace( '/\s+/', ' ', $request ) ) );
		return $request ?: 'background';
	}

	private static function token_count( string $tokens ): int {
		return absint( preg_replace( '/[^\d]/', '', $tokens ) );
	}

	private static function earlier_time( string $left, string $right ): string {
		return strtotime( $right ) < strtotime( $left ) ? $right : $left;
	}

	private static function later_time( string $left, string $right ): string {
		return strtotime( $right ) > strtotime( $left ) ? $right : $left;
	}
}
