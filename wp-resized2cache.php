<?php
/*
Plugin Name: wp-resized2cache
Plugin URI: https://github.com/petermolnar/wp-resized2cache
Description: Sharpen, enchance and move resized images to cache folder
Version: 0.3
Author: Peter Molnar <hello@petermolnar.eu>
Author URI: http://petermolnar.eu/
License: GPLv3
*/

/*  Copyright 2015 Peter Molnar ( hello@petermolnar.eu )

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 3, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

namespace WP_RESIZED2CACHE;

define ( 'cachedir', \WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'cache' );
\register_activation_hook( __FILE__ , 'WP_RESIZED2CACHE\plugin_activate' );
\add_action( 'init', 'WP_RESIZED2CACHE\init' );
\add_action( 'delete_attachment', 'WP_RESIZED2CACHE\delete_from_cache' );

function init () {
	if ( ! is_dir( cachedir ) ) {
		if ( ! mkdir( cachedir ) ) {
			debug('failed to create ' . cachedir, 4);
		}
	}

	// set higher jpg quality
	\add_filter( 'jpeg_quality', 'WP_RESIZED2CACHE\jpeg_quality' );
	\add_filter( 'wp_editor_set_quality', 'WP_RESIZED2CACHE\jpeg_quality' );

	// sharpen resized images on upload
	\add_filter( 'image_make_intermediate_size', 'WP_RESIZED2CACHE\sharpen', 10 );

}

/**
 * activate hook
 */
function plugin_activate() {
	if ( version_compare( phpversion(), 5.3, '<' ) ) {
		die( 'The minimum PHP version required for this plugin is 5.3' );
	}
}


/**
 * called on attachment deletion and takes care of removing the moved files
 *
 */
function delete_from_cache ( $aid = null ) {
	debug( "DELETE is called and aid is: {$aid}", 5 );
	if ($aid === null)
		return false;

	$attachment = \get_post( $aid );

	if ( is_post($attachment)) {
		$meta = \wp_get_attachment_metadata($aid);

		if ( isset( $meta['sizes'] ) && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size => $data ) {
				$file = cachedir . DIRECTORY_SEPARATOR . $data['file'];
				if ( isset( $data['file'] ) && is_file( $file ) ) {
					debug( " removing {$file}", 5 );
					unlink ( $file );
				}
			}
		}
	}

	return $aid;
}

/**
 * better jpgs
 */
function jpeg_quality () {
	$jpeg_quality = (int)92;
	return $jpeg_quality;
}

/**
 * adaptive sharpen images w imagemagick
 */
function sharpen( $resized ) {

	if ( ! class_exists( '\Imagick' ) ) {
		debug('Please install Imagick extension; otherwise this plugin will not work as well as it should.', 4);
	}

	/*
	preg_match ( '/(.*)-([0-9]+)x([0-9]+)\.([0-9A-Za-z]{2,4})/', $resized, $details );

	 * 0 => original var
	 * 1 => full original file path without extension
	 * 2 => resized size w
	 * 3 => resized size h
	 * 4 => extension
	 */

	$size = @getimagesize($resized);

	if ( !$size ) {
		debug( "Unable to get size for: {$resized}", 4);
		return $resized;
	}

	//$cachedir = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'cache';

	$fname = basename( $resized );
	$cached = cachedir . DIRECTORY_SEPARATOR . $fname;

	if ( $size[2] == IMAGETYPE_JPEG && class_exists('\Imagick')) {
		debug( "adaptive sharpen {$resized}", 6 );
		try {
			$imagick = new \Imagick( $resized );
			$imagick->unsharpMaskImage( 0, 0.5, 1, 0 );
			$imagick->setImageFormat( "jpg" );
			$imagick->setImageCompression( \Imagick::COMPRESSION_JPEG );
			$imagick->setImageCompressionQuality( jpeg_quality() );
			$imagick->setInterlaceScheme( \Imagick::INTERLACE_PLANE );
			$imagick = \apply_filters( "wp_resized2cache_imagick", $imagick, $resized );
			$imagick->writeImage($cached);
			$imagick->destroy();
		}
		catch (Exception $e) {
			debug( 'something went wrong with imagemagick: ',  $e->getMessage(), 4 );
			return $resized;
		}

		debug( "removing " . $resized, 5 );
		unlink ($resized);

	}
	else {
		debug( "moving {$cached}", 5 );
		if ( copy( $resized, $cached ) ) {
			debug( "removing {$resized}", 5 );
			unlink( $resized );
		}
		else {
			debug( "\tmove failed, passing on this", 4 );
		}
	}

	return $resized;
}

/**
 *
 * debug messages; will only work if WP_DEBUG is on
 * or if the level is LOG_ERR, but that will kill the process
 *
 * @param string $message
 * @param int $level
 *
 * @output log to syslog | wp_die on high level
 * @return false on not taking action, true on log sent
 */
function debug( $message, $level = LOG_NOTICE ) {
	if ( empty( $message ) )
		return false;

	if ( @is_array( $message ) || @is_object ( $message ) )
		$message = json_encode($message);

	$levels = array (
		LOG_EMERG => 0, // system is unusable
		LOG_ALERT => 1, // Alert 	action must be taken immediately
		LOG_CRIT => 2, // Critical 	critical conditions
		LOG_ERR => 3, // Error 	error conditions
		LOG_WARNING => 4, // Warning 	warning conditions
		LOG_NOTICE => 5, // Notice 	normal but significant condition
		LOG_INFO => 6, // Informational 	informational messages
		LOG_DEBUG => 7, // Debug 	debug-level messages
	);

	// number for number based comparison
	// should work with the defines only, this is just a make-it-sure step
	$level_ = $levels [ $level ];

	// in case WordPress debug log has a minimum level
	if ( defined ( '\WP_DEBUG_LEVEL' ) ) {
		$wp_level = $levels [ \WP_DEBUG_LEVEL ];
		if ( $level_ > $wp_level ) {
			return false;
		}
	}

	// ERR, CRIT, ALERT and EMERG
	if ( 3 >= $level_ ) {
		\wp_die( '<h1>Error:</h1>' . '<p>' . $message . '</p>' );
		exit;
	}

	$trace = debug_backtrace();
	$caller = $trace[1];
	$parent = $caller['function'];

	if (isset($caller['class']))
		$parent = $caller['class'] . '::' . $parent;

	return error_log( "{$parent}: {$message}" );
}

/**
 *
 */
function is_post ( &$post ) {
	if ( !empty($post) && is_object($post) && isset($post->ID) && !empty($post->ID) )
		return true;

	return false;
}
