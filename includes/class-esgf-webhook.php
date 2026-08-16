<?php
/**
 * Receives the Essential Support ticket.created webhook and uploads any files the
 * matching Gravity Forms entry was holding.
 *
 * @package EssentialSupportGravityForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A verify-first ticket doesn't exist until the customer confirms by email, so files
 * uploaded on the form can't be attached at submit time. Instead we hold them on the
 * Gravity Forms entry and, when Essential Support fires ticket.created (carrying the
 * entry id back as sourceRef), upload them onto the now-real ticket.
 */
class ESGF_Webhook {

	const NAMESPACE_V1  = 'essential-support/v1';
	const ROUTE         = '/webhook';
	const FRESHNESS_SEC = 300;

	/**
	 * Register the REST route. Auth is the HMAC signature, not a WordPress capability, so
	 * the permission callback is open — the handler verifies the signature first.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The URL to register as a webhook endpoint in Essential Support.
	 *
	 * @return string
	 */
	public static function callback_url() {
		return rest_url( self::NAMESPACE_V1 . self::ROUTE );
	}

	/**
	 * Handle an incoming webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$settings = esgf_settings();
		$secret   = isset( $settings['webhook_secret'] ) ? $settings['webhook_secret'] : '';
		$raw      = $request->get_body();

		if ( '' === (string) $secret ) {
			return new WP_REST_Response( 'not configured', 503 );
		}
		if ( ! self::verify_signature( $secret, $request->get_header( 'x-es-signature' ), $raw ) ) {
			return new WP_REST_Response( 'bad signature', 401 );
		}

		$body = json_decode( $raw, true );
		if ( ! is_array( $body ) || 'ticket.created' !== rgar( $body, 'event' ) ) {
			return new WP_REST_Response( 'ok', 200 ); // Not ours to act on — ack so ES stops.
		}

		// Idempotency: ES delivers at least once. The deliveryId lives in the signed body.
		$delivery_id = rgar( $body, 'deliveryId' );
		if ( $delivery_id ) {
			$seen_key = 'esgf_seen_' . md5( (string) $delivery_id );
			if ( get_transient( $seen_key ) ) {
				return new WP_REST_Response( 'ok', 200 );
			}
			set_transient( $seen_key, 1, DAY_IN_SECONDS );
		}

		$ticket     = is_array( rgar( $body, 'ticket' ) ) ? $body['ticket'] : array();
		$source_ref = rgar( $ticket, 'sourceRef' );
		$ticket_ref = rgar( $ticket, 'ref' );
		if ( empty( $ticket_ref ) ) {
			$ticket_ref = rgar( $ticket, 'id' );
		}
		if ( empty( $source_ref ) || empty( $ticket_ref ) ) {
			return new WP_REST_Response( 'ok', 200 ); // No entry to correlate.
		}

		$result = self::attach_entry_files( (int) $source_ref, (string) $ticket_ref );

		/**
		 * Fires after a ticket.created webhook has been processed (files uploaded, if any).
		 *
		 * @param array $body   The verified webhook payload.
		 * @param int   $entry  The correlated Gravity Forms entry id.
		 */
		do_action( 'esgf_ticket_created', $body, (int) $source_ref );

		// On an upload failure, return 5xx so ES retries (already-uploaded files are
		// remembered and skipped); otherwise ack 200.
		return $result ? new WP_REST_Response( 'ok', 200 ) : new WP_REST_Response( 'retry', 500 );
	}

	/**
	 * Upload the entry's held files onto the ticket. Returns true if there was nothing to
	 * do or everything succeeded; false if any upload failed (so the caller can 5xx).
	 *
	 * @param int    $entry_id   Gravity Forms entry id (the sourceRef).
	 * @param string $ticket_ref Ticket id or human ref.
	 * @return bool
	 */
	private static function attach_entry_files( $entry_id, $ticket_ref ) {
		if ( ! class_exists( 'GFAPI' ) ) {
			return true; // GF not loaded — nothing we can do; don't force endless retries.
		}
		$files_field = gform_get_meta( $entry_id, 'esgf_files_field' );
		if ( empty( $files_field ) ) {
			return true; // This submission had no files mapped.
		}
		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			return true; // Entry gone (deleted) — nothing to attach.
		}

		$client = ESGF_Client::from_settings();
		if ( null === $client ) {
			return true; // Not configured — can't upload; don't retry forever.
		}

		$urls    = self::field_file_urls( rgar( $entry, (string) $files_field ) );
		$done    = gform_get_meta( $entry_id, 'esgf_uploaded' );
		$done    = is_array( $done ) ? $done : array();
		$all_ok  = true;
		$uploads = wp_upload_dir();

		foreach ( $urls as $url ) {
			if ( in_array( $url, $done, true ) ) {
				continue; // Already uploaded on an earlier delivery.
			}
			$path = self::url_to_path( $url, $uploads );
			if ( '' === $path ) {
				continue; // Not a local upload we can resolve — skip.
			}
			$name = basename( $path );
			$type = wp_check_filetype( $name );
			$mime = ! empty( $type['type'] ) ? $type['type'] : 'application/octet-stream';

			$res = $client->upload_attachment( $ticket_ref, $path, $name, $mime );
			if ( is_wp_error( $res ) ) {
				$all_ok = false; // Leave it out of $done so a retry tries again.
				continue;
			}
			$done[] = $url;
		}

		gform_update_meta( $entry_id, 'esgf_uploaded', $done );
		return $all_ok;
	}

	/**
	 * Normalise a Gravity Forms file-upload field value to a list of URLs. A single-file
	 * field stores one URL string; a multi-file field stores a JSON array.
	 *
	 * @param mixed $value Entry field value.
	 * @return array
	 */
	private static function field_file_urls( $value ) {
		if ( empty( $value ) ) {
			return array();
		}
		$decoded = json_decode( (string) $value, true );
		if ( is_array( $decoded ) ) {
			return array_values( array_filter( array_map( 'strval', $decoded ) ) );
		}
		return array( (string) $value );
	}

	/**
	 * Resolve an uploads URL to a local path, refusing anything outside the uploads dir
	 * (defence against a crafted URL escaping the tree).
	 *
	 * @param string $url     File URL.
	 * @param array  $uploads wp_upload_dir() result.
	 * @return string Empty string if it can't be safely resolved.
	 */
	private static function url_to_path( $url, $uploads ) {
		$baseurl = isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '';
		$basedir = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';
		if ( '' === $baseurl || '' === $basedir || 0 !== strpos( $url, $baseurl ) ) {
			return '';
		}
		$path = $basedir . substr( $url, strlen( $baseurl ) );
		$real = realpath( $path );
		$root = realpath( $basedir );
		if ( false === $real || false === $root || 0 !== strpos( $real, $root ) ) {
			return '';
		}
		return $real;
	}

	/**
	 * Verify the Essential Support signature:
	 * `X-ES-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<rawBody>")>`.
	 *
	 * @param string $secret Signing secret.
	 * @param string $header Signature header.
	 * @param string $raw    Raw request body.
	 * @return bool
	 */
	private static function verify_signature( $secret, $header, $raw ) {
		if ( empty( $secret ) || empty( $header ) || null === $raw ) {
			return false;
		}
		$parts = array();
		foreach ( explode( ',', $header ) as $kv ) {
			$i = strpos( $kv, '=' );
			if ( false !== $i && $i > 0 ) {
				$parts[ trim( substr( $kv, 0, $i ) ) ] = trim( substr( $kv, $i + 1 ) );
			}
		}
		$t  = isset( $parts['t'] ) ? intval( $parts['t'] ) : 0;
		$v1 = isset( $parts['v1'] ) ? $parts['v1'] : '';
		if ( $t <= 0 || '' === $v1 ) {
			return false;
		}
		if ( abs( time() - $t ) > self::FRESHNESS_SEC ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $t . '.' . $raw, $secret );
		return hash_equals( $expected, $v1 );
	}
}
