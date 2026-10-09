<?php
/**
 * Short operation log shown on the plugin page. It never stores credentials or tokens.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Storage_Log {
	public const OPTION = 'simple_storage_log';
	private const LIMIT = 200;

	public static function info( string $message ): void {
		self::add( 'info', $message );
	}

	public static function warning( string $message ): void {
		self::add( 'warning', $message );
	}

	public static function error( string $message ): void {
		self::add( 'error', $message );
	}

	private static function add( string $level, string $message ): void {
		$entries   = self::entries();
		$entries[] = array(
			'time'    => time(),
			'level'   => $level,
			'message' => wp_strip_all_tags( $message ),
		);

		update_option( self::OPTION, array_slice( $entries, -self::LIMIT ), false );
	}

	/** @return array<int, array{time: int, level: string, message: string}> */
	public static function entries(): array {
		$entries = get_option( self::OPTION, array() );

		return is_array( $entries ) ? array_values( $entries ) : array();
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
