<?php
/*
Plugin Name: wp-resized2cache
Plugin URI: https://github.com/petermolnar/wp-resized2cache
Description: Sharpen, enchance and move resized images to cache folder
Version: 0.5
Author: Peter Molnar <hello@petermolnar.eu>
Author URI: http://petermolnar.eu/
License: GPLv3
*/

/*  Copyright 2016 Peter Molnar ( hello@petermolnar.eu )

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

define ( 'WP_RESIZED2CACHE\CACHENAME', 'cache' );

define ( 'WP_RESIZED2CACHE\CACHE', \WP_CONTENT_DIR . DIRECTORY_SEPARATOR
	. CACHENAME . DIRECTORY_SEPARATOR );

\register_activation_hook( __FILE__ , 'WP_RESIZED2CACHE\plugin_activate' );

\add_action( 'init', 'WP_RESIZED2CACHE\init' );
\add_action( 'delete_attachment', 'WP_RESIZED2CACHE\delete_from_cache' );

function init () {
	if ( ! is_dir( CACHE ) ) {
		if ( ! mkdir( CACHE ) ) {
			debug('failed to create ' . CACHE, 4);
		}
	}

	// set higher jpg quality
	\add_filter( 'jpeg_quality', 'WP_RESIZED2CACHE\jpeg_quality' );
	\add_filter( 'wp_editor_set_quality', 'WP_RESIZED2CACHE\jpeg_quality' );

	// sharpen resized images on upload
	\add_filter( 'image_make_intermediate_size',
		'WP_RESIZED2CACHE\fix_location', 10 );

	add_filter( 'wp_get_attachment_image_src',
		'WP_RESIZED2CACHE\wp_get_attachment_image_src', 1, 4 );

}

/**
 * activate hook
 */
function plugin_activate() {
	if ( version_compare( phpversion(), 5.3, '<' ) ) {
		die( 'The minimum PHP version required for this plugin is 5.3' );
	}
}

function sizes() {
	return array (
		//90  => 's',
		360 => 'm',
		540 => 'n',
		720 => 'z',
		980 => 'c',
		1280 => 'b',
	);
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
				$file = CACHE . DIRECTORY_SEPARATOR . $data['file'];
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
 *
 */
function sharpen( $path ) {
	if ( ! class_exists( '\Imagick' ) )
		return;


	$dimensions = @getimagesize( $path );

	if ( ! isset( $dimensions[2] ) || IMAGETYPE_JPEG != $dimensions[2] )
		return;

	debug( "sharpening {$path}", 6 );
	try {
		$imagick = new \Imagick( $path );
		$imagick->unsharpMaskImage( 0, 0.5, 1, 0 );
		$imagick->setImageFormat( "jpg" );
		$imagick->setImageCompression( \Imagick::COMPRESSION_JPEG );
		$imagick->setImageCompressionQuality( jpeg_quality() );
		$imagick->setInterlaceScheme( \Imagick::INTERLACE_PLANE );

		// this is for watermarking
		$imagick = \apply_filters(
			"wp_resized2cache_imagick",
			$imagick,
			$path
		);

		$imagick->writeImage( $path );
		$imagick->destroy();
	}
	catch (Exception $e) {
		debug( 'something went wrong with imagemagick: ',  $e->getMessage(), 4 );
		return;
	}
}

/**
 *
 */
function link_largest_fallback ( $cached ) {

	$r = pathinfo( $cached );
	// trying to link the largest, resized image to the same name
	// as the original image is with; this is to make fallbacks easier
	$guess_original = preg_replace( '/^(.*)-[0-9]{2,4}x[0-9]{2,4}$/',
		'\\1', $r['filename'] ) . '.' . $r['extension'];

	$upload_dir = \wp_upload_dir();
	$linked = CACHE . DIRECTORY_SEPARATOR . $guess_original;
	$original = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $guess_original;

	// only continue if we could identify the original
	if ( ! file_exists( $original ) )
		return;

	// no link yet; create one
	if ( ! file_exists( $linked ) ) {
		symlink( $cached, $linked );
		debug ( "{$linked} linked to {$cached}", 6 );
		return;
	}

	// link exists, so compare the linked one's size with the current ones
	$linked_size = getimagesize( $linked );
	$cached_size = getimagesize( $cached );
	$compare = ( $cached_size[0] > $cached_size[1] ) ? 0 : 1;
	if ( $cached_size[ $compare ] > $linked_size[ $compare ] ) {
		unlink( $linked );
		symlink( $cached, $linked );
		debug ( "{$linked} linked to {$cached}, because it's larger", 6 );
	}

	return;
}

/**
 *
 */
function link_simple_name( $cached ) {

	$r = pathinfo( $cached );

		// try to find the relevant size
	$dimensions = @getimagesize( $cached );

	$match_by = ( $dimensions[0] > $dimensions[1] ) ? 0 : 1;

	$endings = sizes();

	//$endings = array_flip( $endings );

	if ( ! isset( $endings[ $dimensions[ $match_by ] ] ) )
		return;

	$simple = CACHE .
		preg_replace( '/^(.*)-[0-9]{2,4}x[0-9]{2,4}$/', '\\1', $r['filename'] )
		. '_' . $endings[ $dimensions[ $match_by ] ]
		. '.' . $r['extension'];

	if ( is_file( $simple ) )
		unlink( $simple );

	symlink( $cached, $simple );
	debug ( "{$cached} was linked to {$simple}" );

	return;
}

/**
 * adaptive sharpen images w imagemagick
 */
function fix_location( $resized ) {
	sharpen( $resized );

	$r = pathinfo( $resized );
	$cached = CACHE . $r['filename'] . '.' . $r['extension'];

	// move the file to cache
	if ( copy( $resized, $cached ) )
		unlink( $resized );

	link_largest_fallback( $cached );
	link_simple_name( $cached );


	return $cached;
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
 * test if an object is actually a post
 */
function is_post ( &$post ) {
	if ( ! empty( $post ) &&
			 is_object( $post ) &&
			 isset( $post->ID ) &&
			 ! empty( $post->ID ) )
		return true;

	return false;
}

/**
 *
 */
function find_thid ( $resized ) {

	global $wpdb;
	$dbname = "{$wpdb->prefix}postmeta";
	$req = false;

	$q = $wpdb->prepare( "SELECT `post_id` FROM `{$dbname}` WHERE "
		."`meta_value` LIKE '%%%s%%' AND "
		."`meta_key` = '_wp_attachment_metadata' LIMIT 1",
	basename( $resized ) );

	try {
		$req = $wpdb->get_var( $q );
	}
	catch (Exception $e) {
		debug('Something went wrong: ' . $e->getMessage(), 4);
	}

	return $req;
}

/**
 *
 *  fix the wp content url/dir when trying to get an image src
 *
 */
function wp_get_attachment_image_src ( $image, $thid, $size, $icon ) {
	debug ( $image );
	$by = ( $image[1] > $image[2] ) ? $image[1] : $image[2];

	$endings = sizes();
	if ( isset( $endings[ $by ] ) ) {
		$simple = pathinfo( $image[0] );
		$simple = CACHE .
		preg_replace( '/^(.*)-[0-9]{2,4}x[0-9]{2,4}$/', '\\1', $simple['filename'] )
			. '_' . $endings[ $s ]
			. '.' . $simple['extension'];

		$image[0] = $simple;
	}
	else {
		$upload_dir = \wp_upload_dir();
		$cached = str_replace( trim( $upload_dir['baseurl'], '/' ),
			CACHENAME, $image[0] );

		if ( is_file( \WP_CONTENT_DIR . $cached ) )
			$image[0] = $cached;
	}

	return $image;
}
