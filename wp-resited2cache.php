<?php
/*
Plugin Name: wp-resized2cache
Plugin URI: https://github.com/petermolnar/wp-resized2cache
Description: Sharpen, enchance and move resized images to cache folder
Version: 0.1
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

		if ( !$size )
			return $resized;

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
	 */
	static function debug( $message, $level = LOG_NOTICE ) {
		if ( @is_array( $message ) || @is_object ( $message ) )
			$message = json_encode($message);


		switch ( $level ) {
			case LOG_ERR :
				wp_die( '<h1>Error:</h1>' . '<p>' . $message . '</p>' );
				exit;
			default:
				if ( !defined( 'WP_DEBUG' ) || WP_DEBUG != true )
					return;
				break;
		}

		error_log(  __CLASS__ . " => " . $message );
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