=== Free WebP Optimizer by thisismyurl ===
Contributors: thisismyurl
Donate link: https://thisismyurl.com/donate/
Author URI: https://thisismyurl.com/
Tags: webp, optimization, speed, image-optimizer, performance
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 1.251224
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A free non-destructive WebP for WordPress: Auto-optimize new uploads & bulk-convert existing files with secure backups & 1-click restore. #WPPlugin

== Description ==

**Free WebP Optimizer by thisismyurl** is a lightweight, high-performance utility designed to maximize your site speed without the need for expensive monthly subscriptions or external API keys. 

By converting your images to the modern WebP format, you can significantly reduce file sizes while maintaining high visual quality. This plugin is built with a **Safety-First** philosophy: every time an image is converted, the original file is archived in a secure backup folder. If you ever need to revert, a single click restores your original JPEG or PNG perfectly.

### Key Features:
* **100% Automatic:** New uploads are converted and optimized the moment they hit your Media Library.
* **Bulk Processing:** Convert your entire historical library using an AJAX-powered tool that prevents server timeouts.
* **Non-Destructive Workflow:** Original images are moved to `/uploads/webp-backups/` for safe keeping.
* **Live Savings Report:** View a real-time dashboard showing exactly how many megabytes of server space you have saved.
* **Individual & Bulk Restore:** Undo changes for a single image or your entire library at any time.
* **Quality Granularity:** Use the built-in slider to find your own perfect balance between compression and clarity.
* **Tutorial-Grade Code:** Built to strict WordPress coding standards, making it fast, secure, and developer-friendly.

== Installation ==

1. Upload the `free-webp-optimizer-thisismyurl` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Tools > WebP Optimizer** to access the dashboard and configuration.

== Frequently Asked Questions ==

= Does this delete my original images? =
No. It moves them to a backup folder within your uploads directory. This ensures you never lose your original high-resolution files.

= Will my images break if I delete the plugin? =
We recommend using the "Restore All" button in the plugin's Danger Zone before uninstallation. This reverts your site back to using standard JPEGs and PNGs.

= Does this support PNG transparency? =
Yes! The conversion engine specifically preserves alpha channels to ensure your logos and transparent graphics remain crisp and clear.

== Screenshots ==

1. The main dashboard featuring the storage savings counter and quality slider.
2. The optimization report showing detailed savings per image.
3. The "Danger Zone" which allows for a clean, safe uninstallation and restoration.

== Support ==

For support, bug reports, or feature requests, please visit the WordPress community forums.

== Changelog ==

= 1.251224 =
* Final release with full WordPress coding standard compliance.
* Added comprehensive DocBlock documentation for developers.
* Implemented `uninstall.php` for clean database removal.
* Enhanced UI with external CSS and simplified instructions.

= 1.0.0 =
* Initial beta release.

== Upgrade Notice ==

= 1.251224 =
This version introduces a highly stable backup and restore system. We recommend all users upgrade to ensure their Media Library remains safe and fully optimized.

== Thank You ==
Thank you for using our tools! Visit us at [thisismyurl.com](https://thisismyurl.com/) for more WordPress resources.