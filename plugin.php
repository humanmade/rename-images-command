<?php
/**
 * Plugin Name: Rename Images Command
 * Author: Human Made Limited
 * Author URI: https://humanmade.com/
 */

namespace HM\Rename_Images;

use WP_CLI;

require_once __DIR__ . '/inc/class-command.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'media rename-images', __NAMESPACE__ . '\\Command' );
}
