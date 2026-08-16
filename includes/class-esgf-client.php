<?php
/**
 * HTTP client for the Essential Support ticket API.
 *
 * @package EssentialSupportGravityForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin, workspace-scoped client. Every call authenticates with the workspace API key
 * (Bearer); the base URL is the workspace host, e.g. https://acme.essential.support.
 * The key stays server-side — it is never printed to a page or sent to a visitor.
 */
class ESGF_Client {

	/**
	 * Workspace base URL (no trailing slash).
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Workspace API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Construct a client for one workspace.
	 *
	 * @param string $base    Workspace URL.
	 * @param string $api_key Workspace API key.
	 */
	public function __construct( $base, $api_key ) {
		$this->base    = untrailingslashit( trim( (string) $base ) );
		$this->api_key = trim( (string) $api_key );
	}

	/**
	 * Build a client from the saved plugin settings, or null if not configured.
	 *
	 * @return ESGF_Client|null
	 */
	public static function from_settings() {
		$s      = esgf_settings();
		$base   = isset( $s['api_base'] ) ? $s['api_base'] : '';
		$key    = isset( $s['api_key'] ) ? $s['api_key'] : '';
		$client = new self( $base, $key );
		return $client->is_configured() ? $client : null;
	}

	/**
	 * Is the client usable (base URL + key present, base is https)?
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->base && '' !== $this->api_key && $this->is_valid_base();
	}

	/**
	 * The base must be an https URL — the API key travels on it.
	 *
	 * @return bool
	 */
	public function is_valid_base() {
		return (bool) preg_match( '#^https://[^\s/]+#i', $this->base );
	}

	/**
	 * Build a full API URL from a path.
	 *
	 * @param string $path Absolute API path (e.g. /api/form-config).
	 * @return string
	 */
	private function url( $path ) {
		return $this->base . $path;
	}

	/**
	 * Bearer auth headers, merged with any extras.
	 *
	 * @param array $extra Extra headers.
	 * @return array
	 */
	private function auth_headers( $extra = array() ) {
		return array_merge(
			array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Accept'        => 'application/json',
			),
			$extra
		);
	}

	/**
	 * GET /api/form-config — the workspace's ticket types + whether attachments are on.
	 *
	 * @return array|WP_Error
	 */
	public function get_form_config() {
		$res = wp_remote_get(
			$this->url( '/api/form-config' ),
			array(
				'headers' => $this->auth_headers(),
				'timeout' => 15,
			)
		);
		return $this->decode( $res );
	}

	/**
	 * POST /api/public/ticket — the verify-first public form. Nothing is created until the
	 * customer confirms by email; the response is a generic acknowledgement.
	 *
	 * @param array $payload { email, subject, message, type?, name?, sourceRef? }.
	 * @return array|WP_Error
	 */
	public function submit_public_ticket( $payload ) {
		$res = wp_remote_post(
			$this->url( '/api/public/ticket' ),
			array(
				'headers' => $this->auth_headers( array( 'Content-Type' => 'application/json' ) ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 20,
			)
		);
		return $this->decode( $res );
	}

	/**
	 * POST /api/tickets/{idOrRef}/attachments — upload one file (multipart) onto the
	 * ticket's opening customer message. Only works once the ticket exists (post-verify).
	 *
	 * @param string $ticket_ref Ticket id or human ref.
	 * @param string $file_path  Absolute local path.
	 * @param string $file_name  Display filename.
	 * @param string $mime       Content type.
	 * @return array|WP_Error
	 */
	public function upload_attachment( $ticket_ref, $file_path, $file_name, $mime ) {
		$boundary = wp_generate_password( 24, false );
		$body     = $this->multipart_body( $boundary, $file_path, $file_name, $mime );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$res = wp_remote_post(
			$this->url( '/api/tickets/' . rawurlencode( $ticket_ref ) . '/attachments' ),
			array(
				'headers' => $this->auth_headers(
					array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary )
				),
				'body'    => $body,
				'timeout' => 60,
			)
		);
		return $this->decode( $res );
	}

	/**
	 * Assemble a single-file multipart/form-data body. The whole file is read into memory
	 * (WordPress's HTTP API can't stream a request body); callers must respect the
	 * workspace's max upload size from form-config before calling this.
	 *
	 * @param string $boundary  Multipart boundary.
	 * @param string $file_path Absolute local path.
	 * @param string $file_name Display filename.
	 * @param string $mime      Content type.
	 * @return string|WP_Error
	 */
	private function multipart_body( $boundary, $file_path, $file_name, $mime ) {
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'esgf_unreadable', 'File not readable: ' . $file_name );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local upload to relay as a multipart body.
		$contents = file_get_contents( $file_path );
		if ( false === $contents ) {
			return new WP_Error( 'esgf_read_failed', 'Could not read file: ' . $file_name );
		}
		$eol   = "\r\n";
		$data  = '--' . $boundary . $eol;
		$data .= 'Content-Disposition: form-data; name="file"; filename="' . $this->header_safe( $file_name ) . '"' . $eol;
		$data .= 'Content-Type: ' . $mime . $eol . $eol;
		$data .= $contents . $eol;
		$data .= '--' . $boundary . '--' . $eol;
		return $data;
	}

	/**
	 * Strip characters that would break a header value.
	 *
	 * @param string $name Filename.
	 * @return string
	 */
	private function header_safe( $name ) {
		return str_replace( array( '"', "\r", "\n", '\\' ), '_', (string) $name );
	}

	/**
	 * Decode a wp_remote_* response into an array, or a WP_Error on transport/HTTP failure.
	 *
	 * @param array|WP_Error $res Response.
	 * @return array|WP_Error
	 */
	private function decode( $res ) {
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = ( is_array( $body ) && isset( $body['message'] ) ) ? $body['message'] : 'HTTP ' . $code;
			return new WP_Error(
				'esgf_http_' . $code,
				$msg,
				array(
					'status' => $code,
					'body'   => $body,
				)
			);
		}
		return is_array( $body ) ? $body : array();
	}
}
