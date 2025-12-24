<?php
/**
 * GitHub Updater Class
 * * Provides a simple way to update plugins directly from GitHub Releases.
 */

if ( ! class_exists( 'FWO_GitHub_Updater' ) ) {

	class FWO_GitHub_Updater {
		private $config;

		public function __construct( $config ) {
			$this->config = wp_parse_args( $config, array(
				'slug'               => '', // e.g., 'my-plugin'
				'proper_folder_name' => '', // e.g., 'my-plugin'
				'api_url'            => '', // e.g., 'https://api.github.com/repos/user/repo/releases/latest'
				'github_url'         => '', // e.g., 'https://github.com/user/repo'
				'plugin_file'        => '', // e.g., __FILE__
			) );

			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
			add_filter( 'upgrader_post_install', array( $this, 'rename_folder' ), 10, 3 );
		}

		public function check_update( $transient ) {
			if ( empty( $transient->checked ) ) {
				return $transient;
			}

			$response = wp_remote_get( $this->config['api_url'], array(
				'headers' => array( 'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) )
			) );

			if ( is_wp_error( $response ) ) {
				return $transient;
			}

			$release = json_decode( wp_remote_retrieve_body( $response ) );
			$current_version = $transient->checked[ plugin_basename( $this->config['plugin_file'] ) ];

			if ( isset( $release->tag_name ) && version_compare( $release->tag_name, $current_version, '>' ) ) {
				$obj = new stdClass();
				$obj->slug        = $this->config['slug'];
				$obj->plugin      = plugin_basename( $this->config['plugin_file'] );
				$obj->new_version = $release->tag_name;
				$obj->url         = $this->config['github_url'];
				$obj->package     = $release->zipball_url;
				
				$transient->response[ plugin_basename( $this->config['plugin_file'] ) ] = $obj;
			}

			return $transient;
		}

		/**
		 * Fixes the folder name.
		 * GitHub ZIPs often come as "repo-name-tagname". 
		 * This renames it back to the proper slug.
		 */
		public function rename_folder( $response, $hook_extra, $result ) {
			global $wp_filesystem;

			$install_directory = plugin_dir_path( $this->config['plugin_file'] );
			$wp_filesystem->move( $result['destination'], $install_directory );
			$result['destination'] = $install_directory;

			return $result;
		}
	}
}