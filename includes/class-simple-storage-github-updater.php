<?php
/**
 * Native WordPress plugin updates from the public GitHub repository.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Storage_GitHub_Updater {
	private const REPOSITORY = 'vitaliikaplia/simple-storage';
	private const BRANCH = 'master';
	private const SLUG = 'simple-storage';
	private const CACHE_KEY = 'simple_storage_github_update_data';
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;
	private const FAILURE_TTL = HOUR_IN_SECONDS;

	private bool $remote_check_performed = false;

	/** @var array<string, int|string>|null */
	private ?array $remote_check_result = null;

	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_update_plugins_transient' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'filter_update_plugins_transient' ) );
		add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'normalize_github_source_directory' ), 11, 4 );
		add_action( 'delete_site_transient_update_plugins', array( $this, 'clear_cached_update_data' ) );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache_after_update' ), 10, 2 );
	}

	/**
	 * Never materialize an update transient that WordPress does not have.
	 *
	 * Returning a fresh stdClass for a missing transient turns core's `false` into a
	 * truthy value. delete_plugins() checks the raw value, so it would then take its
	 * "there is update data" branch and write back a transient with no last_checked.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function filter_update_plugins_transient( mixed $transient ): mixed {
		if ( ! is_object( $transient ) || empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		$local_version = $transient->checked[ SIMPLE_STORAGE_BASENAME ] ?? SIMPLE_STORAGE_VERSION;
		$remote_data   = $this->get_remote_update_data( $this->should_force_check() );
		if ( null === $remote_data ) {
			return $transient;
		}

		if ( empty( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( empty( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		$update = $this->build_update_response( $remote_data );
		if ( version_compare( $remote_data['version'], (string) $local_version, '>' ) ) {
			$transient->response[ SIMPLE_STORAGE_BASENAME ] = $update;
			unset( $transient->no_update[ SIMPLE_STORAGE_BASENAME ] );
		} else {
			$transient->no_update[ SIMPLE_STORAGE_BASENAME ] = $update;
			unset( $transient->response[ SIMPLE_STORAGE_BASENAME ] );
		}

		return $transient;
	}

	/**
	 * Supply the standard WordPress plugin-details modal for this GitHub plugin.
	 *
	 * @param mixed $result
	 * @param mixed $args
	 * @return mixed
	 */
	public function filter_plugins_api( mixed $result, string $action, mixed $args ): mixed {
		if (
			'plugin_information' !== $action
			|| ! is_object( $args )
			|| empty( $args->slug )
			|| self::SLUG !== $args->slug
		) {
			return $result;
		}

		$remote_data = $this->get_remote_update_data( $this->should_force_check() );
		$version     = $remote_data['version'] ?? SIMPLE_STORAGE_VERSION;

		return (object) array(
			'name'          => 'Simple Storage',
			'slug'          => self::SLUG,
			'version'       => $version,
			'author'        => 'Vitalii Kaplia',
			'homepage'      => $this->get_repository_url(),
			'requires'      => '6.5',
			'requires_php'  => '8.1',
			'download_link' => $remote_data['package'] ?? $this->get_package_url(),
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Moves WordPress media files to the Hosting Ukraine storage and back, with verification, and serves them from the storage either under their original addresses (transparent proxy) or directly.', 'simple-storage' ) . '</p>',
				'changelog'   => '<p>' . esc_html__( 'Version 0.3.2: folders that deleting files leaves empty in the storage (a month, its subfolders, a year) are removed too, safely, under the job lock. Version 0.3.1: deleting an attachment whose language copies are deleted together (WP-LOC) deletes its files in the storage too, and new files are copied to the storage at the end of the request that wrote them.', 'simple-storage' ) . '</p>',
			),
		);
	}

	/**
	 * GitHub branch archives unpack as simple-storage-master. Rename the
	 * temporary source to the currently installed plugin directory so updates
	 * also preserve activation when the first install kept GitHub's ZIP suffix.
	 *
	 * @param mixed               $source
	 * @param mixed               $remote_source
	 * @param mixed               $upgrader
	 * @param array<string,mixed> $hook_extra
	 * @return mixed
	 */
	public function normalize_github_source_directory( mixed $source, mixed $remote_source, mixed $upgrader, array $hook_extra = array() ): mixed {
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( empty( $hook_extra['plugin'] ) || SIMPLE_STORAGE_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}

		$source_path         = untrailingslashit( (string) $source );
		$source_name         = basename( $source_path );
		$installed_directory = $this->get_installed_directory();
		if ( $installed_directory === $source_name ) {
			return trailingslashit( $source_path );
		}
		if ( self::SLUG !== $source_name && ! str_starts_with( $source_name, self::SLUG . '-' ) ) {
			return $source;
		}

		$target = trailingslashit( dirname( $source_path ) ) . $installed_directory;
		if ( $target === $source_path ) {
			return $source;
		}

		global $wp_filesystem;

		if ( $wp_filesystem && $wp_filesystem->exists( $target ) ) {
			$wp_filesystem->delete( $target, true );
		} elseif ( file_exists( $target ) || is_link( $target ) ) {
			$this->delete_directory( $target );
		}

		if ( $wp_filesystem && $wp_filesystem->move( $source_path, $target, true ) ) {
			return trailingslashit( $target );
		}
		if ( @rename( $source_path, $target ) ) {
			return trailingslashit( $target );
		}

		return $source;
	}

	/** @param mixed $upgrader @param array<string,mixed> $hook_extra */
	public function clear_cache_after_update( mixed $upgrader, array $hook_extra ): void {
		if ( 'update' !== ( $hook_extra['action'] ?? '' ) || 'plugin' !== ( $hook_extra['type'] ?? '' ) ) {
			return;
		}

		$plugins = isset( $hook_extra['plugins'] )
			? (array) $hook_extra['plugins']
			: array( $hook_extra['plugin'] ?? '' );
		if ( in_array( SIMPLE_STORAGE_BASENAME, $plugins, true ) ) {
			$this->clear_cached_update_data();
		}
	}

	public function clear_cached_update_data(): void {
		delete_site_transient( self::CACHE_KEY );
		$this->remote_check_performed = false;
		$this->remote_check_result    = null;
	}

	/** @return array<string, int|string>|null */
	private function get_remote_update_data( bool $force = false ): ?array {
		if ( $this->remote_check_performed ) {
			return $this->remote_check_result;
		}

		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return ! empty( $cached['version'] ) ? $cached : null;
			}
		}

		$this->remote_check_performed = true;
		$response = wp_remote_get(
			$this->get_remote_plugin_file_url(),
			array(
				'timeout'             => 10,
				'redirection'         => 3,
				'limit_response_size' => 65536,
				'headers'             => array(
					'Accept'     => 'text/plain',
					'User-Agent' => 'Simple-Storage/' . SIMPLE_STORAGE_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->cache_failed_check();
			return null;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$this->cache_failed_check();
			return null;
		}

		$version = $this->parse_plugin_version( (string) wp_remote_retrieve_body( $response ) );
		if ( null === $version ) {
			$this->cache_failed_check();
			return null;
		}

		$data = array(
			'version'      => $version,
			'package'      => $this->get_package_url(),
			'url'          => $this->get_repository_url(),
			'branch'       => self::BRANCH,
			'last_checked' => time(),
		);

		$this->remote_check_result = $data;
		set_site_transient( self::CACHE_KEY, $data, self::CACHE_TTL );

		return $data;
	}

	/** @param array<string, int|string> $remote_data */
	private function build_update_response( array $remote_data ): stdClass {
		return (object) array(
			'id'           => $this->get_repository_url(),
			'slug'         => self::SLUG,
			'plugin'       => SIMPLE_STORAGE_BASENAME,
			'new_version'  => $remote_data['version'],
			'url'          => $remote_data['url'],
			'package'      => $remote_data['package'],
			'requires'     => '6.5',
			'requires_php' => '8.1',
		);
	}

	private function parse_plugin_version( string $plugin_file_contents ): ?string {
		if ( ! preg_match( '/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $plugin_file_contents, $matches ) ) {
			return null;
		}

		$version = trim( $matches[1] );
		if ( '' === $version || strlen( $version ) > 64 ) {
			return null;
		}

		return preg_match( '/^[0-9][0-9A-Za-z._+-]*$/D', $version ) ? $version : null;
	}

	private function should_force_check(): bool {
		$force_check = isset( $_GET['force-check'] )
			? sanitize_text_field( wp_unslash( $_GET['force-check'] ) )
			: '';

		return is_admin() && current_user_can( 'update_plugins' ) && '1' === $force_check;
	}

	private function cache_failed_check(): void {
		set_site_transient(
			self::CACHE_KEY,
			array(
				'version'      => '',
				'last_checked' => time(),
			),
			self::FAILURE_TTL
		);
	}

	private function delete_directory( string $directory ): void {
		if ( is_link( $directory ) || is_file( $directory ) ) {
			@unlink( $directory );
			return;
		}
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$items = scandir( $directory );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$this->delete_directory( $directory . DIRECTORY_SEPARATOR . $item );
		}
		@rmdir( $directory );
	}

	private function get_remote_plugin_file_url(): string {
		return sprintf(
			'https://raw.githubusercontent.com/%s/%s/simple-storage.php',
			self::REPOSITORY,
			$this->get_url_branch()
		);
	}

	private function get_package_url(): string {
		return sprintf(
			'https://github.com/%s/archive/refs/heads/%s.zip',
			self::REPOSITORY,
			$this->get_url_branch()
		);
	}

	private function get_repository_url(): string {
		return 'https://github.com/' . self::REPOSITORY;
	}

	private function get_installed_directory(): string {
		$directory = dirname( SIMPLE_STORAGE_BASENAME );
		if ( '.' === $directory || '' === $directory ) {
			return self::SLUG;
		}

		$directory = basename( str_replace( '\\', '/', $directory ) );
		return '' !== $directory && '.' !== $directory ? $directory : self::SLUG;
	}

	private function get_url_branch(): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', self::BRANCH ) ) );
	}
}
