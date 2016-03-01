<?php
/*
Plugin Name: wp-resized2cache
Plugin URI: https://github.com/petermolnar/wp-resized2cache
Description: Sharpen, enchance and move resized images to cache folder
Version: 0.2
Author: Peter Molnar <hello@petermolnar.eu>
Author URI: http://petermolnar.eu/
License: GPLv3
Required minimum PHP version: 5.3
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

if (!class_exists('WP_RESIZED2CACHE')):

class WP_RESIZED2CACHE {

	const cachedir = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'cache';

	public function __construct () {
		register_activation_hook( __FILE__ , array( &$this, 'plugin_activate' ) );

		if (!is_dir(static::cachedir)) {
			if (!mkdir(static::cachedir)) {
				static::debug('failed to create ' . static::cachedir);
			}
		}

		add_action( 'init', array( &$this, 'init'));
		add_action( 'delete_attachment', array (&$this, 'delete_from_cache'));
	}

	public function init () {
		// set higher jpg quality
		add_filter( 'jpeg_quality', array( &$this, 'jpeg_quality' ) );
		add_filter( 'wp_editor_set_quality', array( &$this, 'jpeg_quality' ) );

		// sharpen resized images on upload
		add_filter( 'image_make_intermediate_size',array ( &$this, 'sharpen' ),10);

	}

	/**
	 * activate hook
	 */
	public static function plugin_activate() {
		if ( version_compare( phpversion(), 5.3, '<' ) ) {
			die( 'The minimum PHP version required for this plugin is 5.3' );
		}
	}


	/**
	 * called on attachment deletion and takes care of removing the moved files
	 *
	 */
	public static function delete_from_cache ( $aid = null ) {
		static::debug( "DELETE is called and aid is: " . $aid );
		if ($aid === null)
			return false;

		$attachment = get_post( $aid );

		if ( static::is_post($attachment)) {
			$meta = wp_get_attachment_metadata($aid);

			if (isset($meta['sizes']) && !empty($meta['sizes'])) {
				foreach ( $meta['sizes'] as $size => $data ) {
					$file = static::cachedir . DIRECTORY_SEPARATOR . $data['file'];
					if ( isset($data['file']) && is_file($file)) {
						static::debug( " removing " . $file );
						unlink ($file);
					}
				}
			}
		}

		return $aid;
	}

	/**
	 * better jpgs
	 */
	public static function jpeg_quality () {
		$jpeg_quality = (int)92;
		return $jpeg_quality;
	}

	/**
	 * adaptive sharpen images w imagemagick
	 */
	static public function sharpen( $resized ) {

		if (!class_exists('Imagick')) {
			static::debug('Please install Imagick extension; otherwise this plugin will not work as well as it should.');
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
			static::debug("Unable to get size");
			return $resized;
		}

		//$cachedir = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'cache';

		$fname = basename( $resized );
		$cached = static::cachedir . DIRECTORY_SEPARATOR . $fname;

		if ( $size[2] == IMAGETYPE_JPEG && class_exists('Imagick')) {
			static::debug( "adaptive sharpen " . $resized );
			try {
				$imagick = new Imagick($resized);
				$imagick->unsharpMaskImage(0,0.5,1,0);
				$imagick->setImageFormat("jpg");
				$imagick->setImageCompression(Imagick::COMPRESSION_JPEG);
				$imagick->setImageCompressionQuality(static::jpeg_quality());
				$imagick->setInterlaceScheme(Imagick::INTERLACE_PLANE);
				$imagick->writeImage($cached);
				$imagick->destroy();
			}
			catch (Exception $e) {
				static::debug( 'something went wrong with imagemagick: ',  $e->getMessage() );
				return $resized;
			}

			static::debug( "removing " . $resized );
			unlink ($resized);

		}
		else {
			static::debug( "moving " . $cached );
			if (copy( $resized, $cached)) {
				static::debug(  "removing " . $resized );
				unlink( $resized );
			}
			else {
				static::debug( "\tmove failed, passing on this" );
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
	public static function debug( $message, $level = LOG_NOTICE ) {
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
		if ( defined ( 'WP_DEBUG_LEVEL' ) ) {
			$wp_level = $levels [ WP_DEBUG_LEVEL ];
			if ( $level_ < $wp_level ) {
				return false;
			}
		}

		// ERR, CRIT, ALERT and EMERG
		if ( 3 >= $level_ ) {
			wp_die( '<h1>Error:</h1>' . '<p>' . $message . '</p>' );
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
	public static function is_post ( &$post ) {
		if ( !empty($post) && is_object($post) && isset($post->ID) && !empty($post->ID) )
			return true;

		return false;
	}
}

$WP_RESIZED2CACHE = new WP_RESIZED2CACHE();

endif;
