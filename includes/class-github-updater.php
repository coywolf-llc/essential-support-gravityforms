<?php
/**
 * GitHub Releases-based plugin updater.
 *
 * Teaches WordPress's built-in plugin update flow to pull this plugin's updates
 * from the project's GitHub releases. When a release exists whose tag (with an
 * optional "v" prefix stripped) is newer than the installed version, the plugin
 * shows up in Dashboard → Updates and one-click "Update Now" works.
 *
 * Requires no auth (the repo is public). The latest release is cached in a
 * transient so the GitHub API is not hit on every admin pageload. This file is
 * stripped from the WordPress.org build (which updates through .org).
 *
 * @package EssentialSupportGravityForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pulls plugin updates from GitHub releases (GitHub build only).
 */
final class ESGF_GitHub_Updater {

	const REPO          = 'coywolf-llc/essential-support-gravityforms';
	const TRANSIENT_KEY = 'esgf_gh_release';
	const CACHE_HOURS   = 6;

	/**
	 * WP-Cron hook that refreshes the cached release off the request path.
	 */
	const CRON_HOOK = 'esgf_refresh_release';

	/**
	 * Timeout (seconds) for the GitHub HTTP calls.
	 */
	const HTTP_TIMEOUT = 3;

	/**
	 * Plugin file relative to wp-content/plugins.
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Plugin slug (the containing folder name).
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Installed plugin version.
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file     Absolute path to the main plugin file.
	 * @param string $current_version Currently installed version.
	 */
	public function __construct( $plugin_file, $current_version ) {
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->plugin_slug     = dirname( $this->plugin_basename );
		if ( '.' === $this->plugin_slug ) {
			$this->plugin_slug = basename( $plugin_file, '.php' );
		}
		$this->current_version = (string) $current_version;
	}

	/**
	 * Wire into the WordPress update flow.
	 */
	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dirname' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( $this, 'override_view_details' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( $this, 'guard_pre_download' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_after_update' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'maybe_show_update_error' ) );
		add_action( self::CRON_HOOK, array( $this, 'refresh_release_cache' ) );
	}

	/**
	 * Surface a GitHub-check failure on the Plugins / Updates screens.
	 */
	public function maybe_show_update_error() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = ( $screen && isset( $screen->id ) ) ? $screen->id : '';
		if ( ! in_array( $id, array( 'update-core', 'plugins', 'plugins-network', 'update-core-network' ), true ) ) {
			return;
		}
		$err = get_site_transient( self::TRANSIENT_KEY . '_err' );
		if ( ! $err ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Essential Support for Gravity Forms:', 'essential-support-gravityforms' ),
			esc_html(
				sprintf(
					/* translators: %s: failure reason */
					__( 'could not check GitHub for updates (%s). This is usually temporary GitHub API rate-limiting on your host; the check retries automatically. If it persists, install the latest release manually from the plugin\'s GitHub Releases page.', 'essential-support-gravityforms' ),
					$err
				)
			)
		);
	}

	/**
	 * After WordPress finishes installing this plugin's update, refresh caches.
	 *
	 * @param WP_Upgrader $upgrader   Upgrader instance (unused).
	 * @param array       $hook_extra Upgrade context (action, type, plugins).
	 */
	public function flush_after_update( $upgrader, $hook_extra ) {
		unset( $upgrader );
		if ( ! is_array( $hook_extra ) ) {
			return;
		}
		if ( ( $hook_extra['action'] ?? '' ) !== 'update' ) {
			return;
		}
		if ( ( $hook_extra['type'] ?? '' ) !== 'plugin' ) {
			return;
		}
		$plugins = isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] )
			? $hook_extra['plugins']
			: array();
		if ( empty( $plugins ) && ! empty( $hook_extra['plugin'] ) ) {
			$plugins = array( (string) $hook_extra['plugin'] );
		}
		if ( ! in_array( $this->plugin_basename, $plugins, true ) ) {
			return;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$main = WP_PLUGIN_DIR . '/' . $this->plugin_basename;
		if ( file_exists( $main ) ) {
			$data = get_plugin_data( $main, false, false );
			if ( ! empty( $data['Version'] ) ) {
				$this->current_version = (string) $data['Version'];
			}
		}

		delete_site_transient( self::TRANSIENT_KEY );
		delete_site_transient( self::TRANSIENT_KEY . '_neg' );

		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		} else {
			delete_site_transient( 'update_plugins' );
		}
	}

	/**
	 * Refuse to download an update package from a non-GitHub host.
	 *
	 * @param mixed       $reply      Filtered reply (default false).
	 * @param string      $package    Package URL (or local path).
	 * @param WP_Upgrader $upgrader   Upgrader instance (unused).
	 * @param array       $hook_extra Upgrade context.
	 * @return mixed
	 */
	public function guard_pre_download( $reply, $package, $upgrader, $hook_extra = array() ) {
		unset( $upgrader );

		if ( ! is_string( $package ) || ! preg_match( '#^https?://#i', $package ) ) {
			return $reply;
		}

		$is_ours = is_array( $hook_extra )
			&& ! empty( $hook_extra['plugin'] )
			&& $hook_extra['plugin'] === $this->plugin_basename;

		if ( ! $is_ours ) {
			$parts           = is_string( $package ) ? wp_parse_url( $package ) : false;
			$path            = ( is_array( $parts ) && ! empty( $parts['path'] ) ) ? $parts['path'] : '';
			$looks_like_ours = ( '' !== $path )
				&& ( false !== stripos( $path, self::REPO ) || false !== stripos( $path, $this->plugin_slug ) );
			if ( ! $looks_like_ours ) {
				return $reply;
			}
		}

		if ( ! is_string( $package ) || '' === $this->validate_package_url( $package ) ) {
			return new WP_Error(
				'esgf_untrusted_package',
				__( 'Refusing to download a plugin update from an untrusted host.', 'essential-support-gravityforms' )
			);
		}
		return $reply;
	}

	/**
	 * Replace the "View details" link with a direct link to the GitHub repo.
	 *
	 * @param string[] $plugin_meta Row meta links.
	 * @param string   $plugin_file Plugin basename being rendered.
	 * @return string[]
	 */
	public function override_view_details( $plugin_meta, $plugin_file ) {
		if ( $plugin_file !== $this->plugin_basename || ! is_array( $plugin_meta ) ) {
			return $plugin_meta;
		}
		$repo_url = 'https://github.com/' . self::REPO;
		foreach ( $plugin_meta as $i => $item ) {
			if ( false !== strpos( $item, 'plugin-install.php?tab=plugin-information' )
				|| false !== strpos( $item, 'class="thickbox' )
				|| false !== strpos( $item, "class='thickbox" ) ) {
				$plugin_meta[ $i ] = sprintf(
					'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
					esc_url( $repo_url ),
					esc_html__( 'View details', 'essential-support-gravityforms' )
				);
			}
		}
		return $plugin_meta;
	}

	/**
	 * If a newer GitHub release exists, advertise it to WordPress.
	 *
	 * @param object $transient The update_plugins transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		$release = $this->get_cached_release();
		if ( ! is_array( $release ) ) {
			$this->maybe_schedule_refresh();
			return $transient;
		}

		$remote_version = $this->normalize_version( $release['tag_name'] );
		if ( '' === $remote_version ) {
			return $transient;
		}

		$update_obj = $this->build_update_obj( $release, $remote_version );

		if ( empty( $update_obj->package ) ) {
			return $transient;
		}

		if ( version_compare( $remote_version, $this->current_version, '<=' ) ) {
			if ( isset( $transient->no_update ) ) {
				$transient->no_update[ $this->plugin_basename ] = $update_obj;
			}
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $this->plugin_basename ] = $update_obj;

		return $transient;
	}

	/**
	 * Build the update object WordPress expects.
	 *
	 * @param array  $release        Release.
	 * @param string $remote_version Version.
	 * @return stdClass
	 */
	private function build_update_obj( $release, $remote_version ) {
		$obj                = new stdClass();
		$obj->id            = self::REPO;
		$obj->slug          = $this->plugin_slug;
		$obj->plugin        = $this->plugin_basename;
		$obj->new_version   = $remote_version;
		$obj->url           = 'https://github.com/' . self::REPO;
		$obj->package       = $this->pick_package_url( $release );
		$obj->icons         = $this->icon_urls();
		$obj->banners       = array();
		$obj->banners_rtl   = array();
		$obj->tested        = '';
		$obj->requires_php  = '';
		$obj->compatibility = new stdClass();
		return $obj;
	}

	/**
	 * Populate the "View details" modal.
	 *
	 * @param mixed  $result Filtered value.
	 * @param string $action Requested action.
	 * @param object $args   Request args.
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		$release = $this->get_cached_release();
		if ( ! is_array( $release ) ) {
			$release = $this->fetch_release_now();
		}
		if ( ! is_array( $release ) ) {
			return $result;
		}

		$body = isset( $release['body'] ) ? (string) $release['body'] : '';
		if ( '' === $body ) {
			$reason = '';
			$rest   = $this->fetch_via_api( $reason );
			if ( is_array( $rest ) && ! empty( $rest['body'] ) ) {
				$body = (string) $rest['body'];
			}
		}

		$info                = new stdClass();
		$info->name          = 'Essential Support for Gravity Forms';
		$info->slug          = $this->plugin_slug;
		$info->version       = $this->normalize_version( $release['tag_name'] );
		$info->author        = '<a href="https://coywolf.com/">Coywolf</a>';
		$info->homepage      = 'https://github.com/' . self::REPO;
		$info->download_link = $this->pick_package_url( $release );
		$info->last_updated  = isset( $release['published_at'] ) ? $release['published_at'] : '';
		$info->sections      = array(
			'description' => 'Turn any Gravity Form into an Essential Support ticket — verify-first by email, with your ticket types and optional file attachments.',
			'changelog'   => $this->render_changelog( $body ),
		);
		$info->icons         = $this->icon_urls();
		return $info;
	}

	/**
	 * Icon URLs for the Plugins / Updates / View-details screens.
	 *
	 * @return array<string,string>
	 */
	private function icon_urls() {
		$base = 'https://raw.githubusercontent.com/' . self::REPO . '/main/.wordpress-org/';
		return array(
			'1x'      => $base . 'icon-128x128.png',
			'2x'      => $base . 'icon-256x256.png',
			'default' => $base . 'icon-256x256.png',
		);
	}

	/**
	 * Convert release notes markdown into simple HTML.
	 *
	 * @param string $markdown Markdown.
	 * @return string
	 */
	private function render_changelog( $markdown ) {
		$md = trim( (string) $markdown );
		if ( '' === $md ) {
			return '<p>See the GitHub release for changelog details.</p>';
		}
		$lines = preg_split( '/\r\n|\r|\n/', $md );
		$html  = '';
		$in_ul = false;
		foreach ( $lines as $line ) {
			$line = rtrim( $line );
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $m ) ) {
				if ( $in_ul ) {
					$html .= '</ul>';
					$in_ul = false; }
				$level = strlen( $m[1] );
				$html .= '<h' . $level . '>' . esc_html( $m[2] ) . '</h' . $level . '>';
			} elseif ( preg_match( '/^[*-]\s+(.*)$/', $line, $m ) ) {
				if ( ! $in_ul ) {
					$html .= '<ul>';
					$in_ul = true; }
				$html .= '<li>' . $this->inline_md( $m[1] ) . '</li>';
			} elseif ( '' === $line ) {
				if ( $in_ul ) {
					$html .= '</ul>';
					$in_ul = false; }
			} else {
				if ( $in_ul ) {
					$html .= '</ul>';
					$in_ul = false; }
				$html .= '<p>' . $this->inline_md( $line ) . '</p>';
			}
		}
		if ( $in_ul ) {
			$html .= '</ul>';
		}
		return $html;
	}

	/**
	 * Inline markdown.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function inline_md( $text ) {
		$text = esc_html( $text );
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
			function ( $m ) {
				return '<a href="' . esc_url( $m[2] ) . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
			},
			$text
		);
		return $text;
	}

	/**
	 * Rename the extracted source directory to the plugin slug.
	 *
	 * @param string      $source        Extracted source path.
	 * @param string      $remote_source Remote source path.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Extra data.
	 * @return string|WP_Error
	 */
	public function fix_source_dirname( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $source;
		}

		global $wp_filesystem;
		$expected = trailingslashit( dirname( $source ) ) . $this->plugin_slug;
		$source   = untrailingslashit( $source );

		if ( $source === $expected ) {
			return trailingslashit( $source );
		}

		if ( $wp_filesystem && $wp_filesystem->move( $source, $expected, true ) ) {
			return trailingslashit( $expected );
		}

		return new WP_Error(
			'esgf_rename_failed',
			__( 'Could not rename the downloaded update folder to match the plugin slug.', 'essential-support-gravityforms' )
		);
	}

	/**
	 * The cached release without touching the network.
	 *
	 * @return array|null
	 */
	private function get_cached_release() {
		$cached = get_site_transient( self::TRANSIENT_KEY );
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Schedule a one-off background refresh of the release cache.
	 */
	private function maybe_schedule_refresh() {
		if ( false !== get_site_transient( self::TRANSIENT_KEY . '_neg' ) ) {
			return;
		}
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + 1, self::CRON_HOOK );
	}

	/**
	 * WP-Cron callback: fetch the latest release off the request path.
	 */
	public function refresh_release_cache() {
		if ( is_array( $this->get_cached_release() ) ) {
			return;
		}
		if ( is_array( $this->fetch_release_now() ) ) {
			delete_site_transient( 'update_plugins' );
		}
	}

	/**
	 * Fetch the latest release synchronously (Atom feed first, REST fallback).
	 *
	 * @return array|null
	 */
	private function fetch_release_now() {
		$cached = $this->get_cached_release();
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( false !== get_site_transient( self::TRANSIENT_KEY . '_neg' ) ) {
			return null;
		}

		$reason_atom = '';
		$reason_api  = '';
		$release     = $this->fetch_via_atom( $reason_atom );
		if ( ! is_array( $release ) ) {
			$release = $this->fetch_via_api( $reason_api );
		}

		if ( ! is_array( $release ) ) {
			$reason = $reason_atom;
			if ( '' !== $reason_api ) {
				$reason = ( '' !== $reason ) ? $reason . '; ' . $reason_api : $reason_api;
			}
			if ( '' === $reason ) {
				$reason = 'unknown error';
			}
			set_site_transient( self::TRANSIENT_KEY . '_neg', $reason, 15 * MINUTE_IN_SECONDS );
			set_site_transient( self::TRANSIENT_KEY . '_err', $reason, self::CACHE_HOURS * HOUR_IN_SECONDS );
			return null;
		}

		delete_site_transient( self::TRANSIENT_KEY . '_err' );
		set_site_transient( self::TRANSIENT_KEY, $release, self::CACHE_HOURS * HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Fetch the latest release from the GitHub REST API.
	 *
	 * @param string $reason Out-param: failure reason.
	 * @return array|null
	 */
	private function fetch_via_api( &$reason ) {
		$reason = '';
		$url    = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';
		$res    = wp_remote_get(
			$url,
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			$reason = 'GitHub API: ' . $res->get_error_message();
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			$reason = ( 403 === $code )
				? 'GitHub API rate limit reached (HTTP 403)'
				: sprintf( 'GitHub API returned HTTP %d', $code );
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			$reason = 'GitHub API: unexpected response';
			return null;
		}
		return $this->shape_release(
			(string) $data['tag_name'],
			isset( $data['name'] ) ? (string) $data['name'] : '',
			isset( $data['body'] ) ? (string) $data['body'] : '',
			isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			isset( $data['html_url'] ) ? (string) $data['html_url'] : '',
			isset( $data['zipball_url'] ) ? (string) $data['zipball_url'] : '',
			( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) ? $data['assets'] : array()
		);
	}

	/**
	 * Fetch the latest release from the GitHub releases Atom feed.
	 *
	 * @param string $reason Out-param: failure reason.
	 * @return array|null
	 */
	private function fetch_via_atom( &$reason ) {
		$reason = '';
		$url    = 'https://github.com/' . self::REPO . '/releases.atom';
		$res    = wp_remote_get(
			$url,
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array(
					'Accept'     => 'application/atom+xml',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			$reason = 'GitHub Atom feed: ' . $res->get_error_message();
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			$reason = sprintf( 'GitHub Atom feed returned HTTP %d', $code );
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $res );
		if ( ! preg_match( '#<entry>(.*?)</entry>#s', $body, $m ) ) {
			$reason = 'GitHub Atom feed: no releases found';
			return null;
		}
		$entry = $m[1];

		$tag = '';
		if ( preg_match( '#/releases/tag/([^"\'<>\s]+)#', $entry, $tm ) ) {
			$tag = html_entity_decode( $tm[1], ENT_QUOTES, 'UTF-8' );
		} elseif ( preg_match( '#<id>[^<]*/([^/<]+)</id>#', $entry, $tm ) ) {
			$tag = $tm[1];
		}
		if ( '' === $tag ) {
			$reason = 'GitHub Atom feed: could not determine the release tag';
			return null;
		}

		$title = $tag;
		if ( preg_match( '#<title>(.*?)</title>#s', $entry, $ttl ) ) {
			$decoded = trim( html_entity_decode( wp_strip_all_tags( $ttl[1] ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' !== $decoded ) {
				$title = $decoded;
			}
		}
		$updated = preg_match( '#<updated>(.*?)</updated>#s', $entry, $up ) ? $up[1] : '';

		$build_slug = basename( $this->plugin_basename, '.php' );
		$asset      = array(
			array(
				'name'                 => $build_slug . '.zip',
				'browser_download_url' => 'https://github.com/' . self::REPO . '/releases/download/' . $tag . '/' . $build_slug . '.zip',
				'content_type'         => 'application/zip',
			),
		);
		$zipball    = 'https://codeload.github.com/' . self::REPO . '/zip/refs/tags/' . $tag;

		return $this->shape_release(
			$tag,
			$title,
			'',
			$updated,
			'https://github.com/' . self::REPO . '/releases/tag/' . $tag,
			$zipball,
			$asset
		);
	}

	/**
	 * Reduce a release to the fields the updater stores.
	 *
	 * @param string $tag       Tag.
	 * @param string $name      Name.
	 * @param string $body      Notes.
	 * @param string $published Published timestamp.
	 * @param string $html_url  Human URL.
	 * @param string $zipball   Fallback zipball URL.
	 * @param array  $assets    Raw assets.
	 * @return array
	 */
	private function shape_release( $tag, $name, $body, $published, $html_url, $zipball, $assets ) {
		$keep = array(
			'tag_name'     => (string) $tag,
			'name'         => (string) $name,
			'body'         => (string) $body,
			'published_at' => (string) $published,
			'html_url'     => (string) $html_url,
			'zipball_url'  => (string) $zipball,
			'assets'       => array(),
		);
		foreach ( (array) $assets as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$keep['assets'][] = array(
				'name'                 => isset( $a['name'] ) ? (string) $a['name'] : '',
				'browser_download_url' => isset( $a['browser_download_url'] ) ? (string) $a['browser_download_url'] : '',
				'content_type'         => isset( $a['content_type'] ) ? (string) $a['content_type'] : '',
			);
		}
		return $keep;
	}

	/**
	 * Pick the best zip URL: a .zip asset, else the auto-zipball.
	 *
	 * @param array $release Release.
	 * @return string
	 */
	private function pick_package_url( $release ) {
		$candidate = '';
		if ( ! empty( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( ! empty( $asset['name'] ) && '.zip' === strtolower( substr( $asset['name'], -4 ) ) ) {
					$candidate = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
					break;
				}
			}
		}
		if ( '' === $candidate ) {
			$candidate = isset( $release['zipball_url'] ) ? (string) $release['zipball_url'] : '';
		}
		return $this->validate_package_url( $candidate );
	}

	/**
	 * Reject a package URL not served by a known GitHub host.
	 *
	 * @param string $url Candidate URL.
	 * @return string Validated URL, or ''.
	 */
	private function validate_package_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return '';
		}
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$host    = strtolower( $parts['host'] );
		$allowed = array(
			'github.com',
			'api.github.com',
			'codeload.github.com',
			'objects.githubusercontent.com',
			'release-assets.githubusercontent.com',
		);
		if ( ! in_array( $host, $allowed, true ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Strip a leading "v" from a tag.
	 *
	 * @param string $tag Tag.
	 * @return string
	 */
	private function normalize_version( $tag ) {
		$tag = trim( (string) $tag );
		if ( '' !== $tag && ( 'v' === $tag[0] || 'V' === $tag[0] ) ) {
			$tag = substr( $tag, 1 );
		}
		return $tag;
	}
}
