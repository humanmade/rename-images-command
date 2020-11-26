<?php
/**
 * CLI Command for migrating image file names.
 */

namespace HM\Rename_Images;

use Search_Replace_Command;
use WP_CLI;
use WP_Error;

class Command {
	/**
	 * Update original attachment file names to strip dimensions.
	 *
	 * Certain file names that end in dimensions such as those produced by
	 * WordPress eg. example-150x150.jpg can cause problems when uploaded
	 * as an original image. This stops Tachyon from accurately and
	 * performantly rewriting the post content as well as breaks some new WP
     * internals with regards to generating srcset and sizes attributes.
     * This only affects media uploaded on a version of WordPress prior to
     * 5.3.1.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Run the migration for all sites on the network.
	 *
	 * [--sites-page=<int>]
	 * : If you have more than 100 sites you can process the next 100 by incrementing this.
	 *
	 * [--search-replace]
	 * : Whether to update the database.
	 *
	 * [--tables=<tables>]
	 * : A comma separated string of tables to search & replace on. Wildcards are supported. Defaults to wp_*posts, wp_*postmeta.
	 *
	 * [--include-columns=<columns>]
	 * : The database columns to search & replace on. Defaults to post_content, post_excerpt and meta_value.
	 *
	 * @synopsis [--network] [--sites-page=<int>] [--search-replace] [--tables=<tables>] [--include-columns=<columns>]
	 */
	public function __invoke( $args, $assoc_args ) {
		global $wpdb;

		$assoc_args = wp_parse_args( $assoc_args, [
			'network' => false,
			'search-replace' => false,
			'sites-page' => 0,
			'include-columns' => 'post_content,post_excerpt,meta_value',
			'tables' => sprintf( '%1$s*posts,%1$s*postmeta', $wpdb->base_prefix ),
		] );

		$sites = [ get_current_blog_id() ];
		if ( $assoc_args['network'] ) {
			$sites = get_sites( [
				'fields' => 'ids',
				'offset' => $assoc_args['sites-page'],
			] );
		}

		// Get a reference to the search replace command class.
		// The class uses the `__invoke()` magic method allowing it to be called like a function.
		$search_replace = new Search_Replace_Command;

		// Parse tables args to pass to search replace.
		$tables = explode( ',', $assoc_args['tables'] );
		$tables = array_map( 'trim', $tables );
		$tables = array_filter( $tables );

		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );

			if ( $assoc_args['network'] ) {
				WP_CLI::log( "Processing site {$site_id}..." );
			}

            // Look for post IDs via attached file post meta regex match.
			$attachments = $wpdb->get_col( "SELECT post_id
				FROM {$wpdb->postmeta}
				WHERE meta_key = '_wp_attached_file'
				AND meta_value REGEXP '-[[:digit:]]+x[[:digit:]]+\.(jpe?g|png|gif)$';" );

			WP_CLI::log( sprintf( 'Renaming %d attachments...', count( $attachments ) ) );

			foreach ( $attachments as $attachment_id ) {
				$result = $this->_rename_file( $attachment_id );
				if ( is_wp_error( $result ) ) {
					WP_CLI::error( $result, false );
					continue;
				}

				if ( ! $assoc_args['search-replace'] ) {
					WP_CLI::success( sprintf( 'Renamed attachment %d successfully: %s -> %s', $attachment_id, $result['old']['file'], $result['new']['file'] ) );
					continue;
				}

				// Get the old and new file names minus the extension for replacement.
				$ext = pathinfo( $result['old']['file'], PATHINFO_EXTENSION );
				$old = str_replace( ".$ext", '', $result['old']['file'] );
				$new = str_replace( ".$ext", '', $result['new']['file'] );

				WP_CLI::success( sprintf( 'Renamed attachment %d successfully, performing search & replace: %s -> %s', $attachment_id, $old, $new ) );

				// Store all update queries into one transaction per image.
				$wpdb->query( 'START TRANSACTION;' );

				// Run search & replace.
				$search_replace(
					array_merge( [
						$old,
						$new,
					], $tables ),
					// Associative array args / command flags.
					[
						'include-columns' => $assoc_args['include-columns'],
						'quiet' => true,
						'network' => $assoc_args['network'],
					]
				);

				// Commit the updates.
				$wpdb->query( 'COMMIT;' );
			}

			restore_current_blog();
		}

		WP_CLI::log( 'Flushing cache...' );
		wp_cache_flush();
		WP_CLI::success( 'Done!' );
	}

	/**
	 * Rename an existing attachment file to support Tachyon.
	 *
	 * Some legacy uploads may have image dimensions in the file name. Tachyon
	 * does not support this for performance reasons. This function renames
	 * attachments and returns an array containing the attachment ID, the old
	 * metadata and the new metadata.
	 *
	 * @param integer $attachment_id The attachment post ID.
	 * @return array|WP_Error
	 */
	protected function _rename_file( int $attachment_id ) {
		if ( ! get_post( $attachment_id ) ) {
			return new WP_Error( 'rename_file', sprintf( 'Attachment ID %d does not exist', $attachment_id ) );
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'rename_file', sprintf( 'Attachment ID %d is not an image', $attachment_id ) );
		}

		$file = get_attached_file( $attachment_id );
		$metadata = wp_get_attachment_metadata( $attachment_id );

		// Trim dimensions from file name and make sure it's actually different.
		$new_file = preg_replace( '/-\d+x\d+\.(jpe?g|png|gif)$/', '.$1', $file );
		if ( $file === $new_file ) {
			return new WP_Error( 'rename_file', "{$file} does not need to be renamed" );
		}

		// Make sure it's unique.
		$new_file = wp_unique_filename( dirname( $file ), basename( $new_file ) );
		$new_file = dirname( $file ) . DIRECTORY_SEPARATOR . $new_file;

		// Copy old file to new name.
		$copied = copy( $file, $new_file );
		if ( ! $copied ) {
			return new WP_Error( 'rename_file', "{$file} could not be copied to {$new_file}" );
		}

		// Generate new metadata.
		$new_metadata = wp_generate_attachment_metadata( $attachment_id, $new_file );
		if ( empty( $new_metadata ) || ! is_array( $new_metadata ) ) {
			return new WP_Error( 'rename_file', "Could not generate new attachment metadata for attachment ID {$attachment_id}" );
		}

		// Update the attachment.
		wp_update_attachment_metadata( $attachment_id, $new_metadata );
		update_attached_file( $attachment_id, $new_file );
		clean_attachment_cache( $attachment_id );

		return [
			'ID' => $attachment_id,
			'old' => $metadata,
			'new' => $new_metadata,
		];
	}
}
