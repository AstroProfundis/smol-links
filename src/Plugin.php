<?php
/**
 * Class Plugin
 *
 * @package   Shlinkify
 * @author    The Markup
 * @license   GPL-2.0-or-later
 * @link      https://themarkup.org/
 * @copyright 2022 The Markup
 */

namespace Shlinkify;

class Plugin {

	/**
	 * Setup contingent object instances (Options, Admin, and API) and WordPress
	 * hooks
	 *
	 * @return void
	 */
	function __construct() {
		$this->options =  new Options($this);
		$this->api =      new API($this);
		$this->settings = new Settings($this);
		$this->manager =  new Manager($this);
		$this->editor =   new Editor($this);
		add_action('save_post', [$this, 'on_save_post']);
		add_filter('shlink_tags', [$this, 'shlink_tags']);
	}

	/**
	 * Handler function invoked when a post is saved
	 *
	 * This handler listens for `save_post` actions and—if the __Generate on
	 * save__ option is enabled—automatically generates a short URL for the
	 * post.
	 *
	 * The Shlink record includes the post's title and the following tags:
	 * - `shlinkify-onsave`
	 * - `shlinkify-site:[WordPress site hostname]`
	 * - `shlinkify-post:[numeric post ID]`
	 *
	 * @param number $post_id The post's numeric ID
	 * @return void
	 */
	function on_save_post($post_id) {
		try {

			if (! $this->options->get('base_url') ||
				! $this->options->get('api_key')) {
				return;
			}

			if (! $this->options->get('generate_on_save')) {
				return;
			}

			$post = get_post($post_id);
			if ($post->post_type != 'post') {
				return;
			}

			if ($post->post_status == 'future' &&
			    $post->post_status == 'publish') {
				$long_url = get_permalink($post);
			} else if ($post->post_status != 'auto-draft') {
				$long_url = $this->get_expected_permalink($post);
			}

			if (empty($long_url)) {
				return;
			}

			$request = [
				'longUrl' => apply_filters('shlink_long_url', $long_url),
				'title'   => $post->post_title
			];

			$tags = apply_filters('shlink_tags', [
				'shlinkify-onsave',
				"shlinkify-post:{$post->ID}"
			]);
			if (is_array($tags)) {
				$request['tags'] = $tags;
			}

			$shlink = $this->get_post_shlink($post);
			if (empty($shlink)) {
				$response = $this->api->create_shlink($request);
				$this->save_post_response($response, $post);
			} else {
				$short_code = $shlink['short_code'];
				$response = $this->api->update_shlink($short_code, $request);
				$this->save_post_response($response, $post);
			}

		} catch (\Exception $err) {
			if ( function_exists( '\wp_sentry_safe' ) ) {
				\wp_sentry_safe( function ( $client ) use ( $err ) {
					$client->captureException( $err );
				} );
			} else {
				error_log( $err->getMessage() );
			}
		}
	}

	/**
	 * Retrieve a stored Shlink for a given post
	 *
	 * The returned array contains existing short/long URLs and short code, or
	 * null if none are stored for the target post.
	 *
	 * @param \WP_Post $post Target post to retrieve the Shlink for
	 * @return array|null Associative array with keys `long_url`, `short_url`,
	 *                    and `short_code`
	 */
	function get_post_shlink($post) {
		$long_url   = get_post_meta($post->ID, 'shlink_long_url', true);
		$short_url  = get_post_meta($post->ID, 'shlink_short_url', true);
		$short_code = get_post_meta($post->ID, 'shlink_short_code', true);
		if (empty($short_url) || empty($long_url) || empty($short_code)) {
			return null;
		}
		return [
			'long_url'   => $long_url,
			'short_url'  => $short_url,
			'short_code' => $short_code
		];
	}

	/**
	 * Returns the current expected permalink, if the post were to be published
	 *
	 * This is kind of a hack. We create a clone of `$post`, set its status to
	 * `publish` and then pass _that_ post to `get_permalink()`. The resulting
	 * URL should match the post's eventual permalink.
	 *
	 * @param \WP_Post $post Target post to predict a permalink for
	 * @return string Expected permalink URL
	 * @see https://wordpress.stackexchange.com/a/42988
	 */
	function get_expected_permalink($post) {
		$expected = clone $post;
		$expected->post_status = 'publish';
		if (! $expected->post_name) {
			if (! $expected->post_title) {
				return null;
			}
			$expected->post_name = sanitize_title($expected->post_title);
		}
		return get_permalink($expected);
	}

	/**
	 * Handles the Shlink API response for a recently saved post
	 *
	 * @param array $response shlink creation response from the API
	 * @param \WP_Post $post The post we're saving a Shlink for
	 */
	function save_post_response($shlink, $post) {
		if (! empty($shlink['shortUrl'])) {
			update_post_meta($post->ID, 'shlink_long_url', $shlink['longUrl']);
			update_post_meta($post->ID, 'shlink_short_url', $shlink['shortUrl']);
			update_post_meta($post->ID, 'shlink_short_code', $shlink['shortCode']);
		} else {
			throw new \Exception("shlinkify: no 'shortUrl' found in API response");
		}
	}

	/**
	 * Adds standard tags to shlinks
	 *
	 * @param array $tags array of base tags to apply
	 */
	function shlink_tags($tags = []) {
		$site_url = parse_url(get_site_url());
		$user = wp_get_current_user();

		// This conditional exists because in some conditions, for example
		// when a save is cron-initiated, we cannot expect a valid URL from
		// get_site_url(). And if you omit the 'tags' part of a shlink
		// update, the tags assigned previously are still retained.
		// (dphiffer/2022-02-04)
		if (empty($site_url['host']) || empty($user->user_login)) {
			return null;
		}

		$tags[] = "shlinkify-site:{$site_url['host']}";
		$tags[] = "shlinkify-user:{$user->user_login}";

		return $tags;
	}

}
