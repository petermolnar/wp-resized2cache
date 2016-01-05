=== wp-resized2cache ===
Contributors: cadeyrn
Donate link: https://paypal.me/petermolnar/3
Tags: image, cache, image quality,
Requires at least: 3.0
Tested up to: 4.4
Stable tag: 0.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Required minimum PHP version: 5.3

Sharpen, enchance and move resized images to cache folder

== Description ==

The plugin does 3 things:

* sets the upload JPEG quality to 92%
* applies adaptive sharpening to resized JPG files
* moves the resized files from the default file upload location to the wp-content/cache folder (only the resized ones!)

why:
Because the default jpg quality is too low and because orphaned resized files used to give me a headache; this way I can clean the cache folder and regenerate the missing ones easily.

== Requirements ==

* minimum PHP version: 5.3
* Imagemagick PHP plugin

== Installation ==

1. Upload contents of `wp-resized2cache.zip` to the `/wp-content/plugins/` directory
2. Activate the plugin through the `Plugins` menu in WordPress

== Changelog ==

Version numbering logic:

* every A. indicates BIG changes.
* every .B version indicates new features.
* every ..C indicates bugfixes for A.B version.

= 0.1 =
*2015-12-10*

* first stable release
