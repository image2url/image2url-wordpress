=== Image2URL Clipboard Booster ===
Contributors: image2url
Tags: images, upload, clipboard, gutenberg, external media
Requires at least: 5.0
Requires PHP: 7.4
Tested up to: 6.9.4
Stable tag: 0.12.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Upload pasted images from the block editor to a remote image host and insert the returned URL without storing the file in the local media library.

== Description ==

Image2URL Clipboard Booster lets editors paste screenshots or image files directly into the block editor and send them to a remote image hosting endpoint instead of the local Media Library.

Main features:

* Clipboard upload for pasted images in the block editor
* Sequential handling for multi-image paste actions
* Configurable upload endpoint with an admin-side reachability check
* Optional migration tools to bring previously inserted remote images back into the local Media Library
* Background rollback and validation jobs powered by WP-Cron
* Validation reports with filters, summaries, and CSV export
* File type validation, size limits, nonce checks, and rate limiting

Typical use cases:

* Shared hosting environments with strict inode limits
* Editorial workflows that do not want every pasted image stored locally
* Sites that want remote-hosted editor images but still need a rollback path later

External service disclosure:

* This plugin sends uploaded image files to the remote upload endpoint configured in the plugin settings.
* The default endpoint is `https://www.image2url.com/api/upload`.
* The default service homepage is `https://www.image2url.com/`.
* The default service Terms of Service are available at `https://www.image2url.com/en-IN/terms`.
* The default service Privacy Policy is available at `https://www.image2url.com/en-IN/privacy`.
* Requests are made when an editor pastes an image into the block editor and when an administrator runs the endpoint verification action.
* Depending on the configured service, the remote service may receive the image binary itself plus upload metadata such as filename, MIME type, file size, request timestamp, user agent, and the server IP that performs the request.
* Site owners can replace the default endpoint with their own public HTTPS upload service.

== Installation ==

1. Upload the plugin zip in `Plugins > Add New > Upload Plugin`.
2. Activate the plugin.
3. Go to `Settings > Image2URL`.
4. Review the default endpoint or replace it with your own public HTTPS upload endpoint.
5. Save the settings and verify the endpoint before using the editor workflow in production.

== Frequently Asked Questions ==

= Which image formats are supported? =

JPEG, PNG, GIF, and WebP are supported by default.

= Does the plugin store the uploaded image in the local Media Library? =

No. The default editor workflow uploads the image to the configured remote endpoint and inserts the returned remote URL into the post content.

= Can I use my own upload service? =

Yes. Administrators can replace the default endpoint with another public HTTPS endpoint that accepts the upload request format used by the plugin.

= What data is sent to the external service? =

The plugin sends the pasted image file and standard upload metadata required by the remote endpoint. The external service or its infrastructure may also see the originating server IP, user agent, and request time.

= Does the plugin retry failed uploads? =

Yes. The editor script retries failed uploads up to 3 times with exponential backoff.

= Can I verify my custom endpoint before enabling the workflow? =

Yes. The settings page includes a "Verify endpoint" button that checks whether the configured endpoint can be reached from the current WordPress site.

= Can I migrate remote images back into the local Media Library later? =

Yes. The migration screen under `Tools > Image2URL Migration` can scan posts, localize remote images, validate results, queue background jobs, and export validation reports.

= Why did a background migration job stop progressing? =

Background jobs depend on WP-Cron. If WP-Cron is disabled on the site, you need a server-side cron job that triggers `wp-cron.php`.

== Changelog ==

= 0.12.2 =

* Rewrote the plugin header and readme content in English for directory review compliance
* Removed WordPress.org directory image assets from the plugin runtime folder
* Continued hardening the release package so only runtime files are included in the submission zip

= 0.12.1 =

* Switched remote uploads to the WordPress HTTP API and removed direct cURL usage
* Tightened endpoint validation so only public HTTPS endpoints are accepted by default
* Added external service, terms, and privacy disclosures
* Added suggested privacy policy content for site owners

= 0.12.0 =

* Added severity and post type filters to validation reports
* Added richer filtering to the current validation task panel
* Added severity information to exported CSV reports

== Upgrade Notice ==

= 0.12.2 =

This release focuses on WordPress.org submission compliance and release packaging hygiene.

== Other Notes ==

Runtime expectations:

* WordPress 5.0 or newer
* PHP 7.4 or newer
* Outbound requests through the WordPress HTTP API must be allowed
* WP-Cron should be available if you plan to use background migration or validation jobs

Privacy:

This plugin does not add analytics or marketing tracking by itself, but it does send image uploads and related request metadata to the configured external upload service. Review your chosen service's terms and privacy policy before enabling the workflow for editors.
