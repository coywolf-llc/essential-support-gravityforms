<?php
/**
 * The Essential Support Gravity Forms Feed Add-On.
 *
 * @package EssentialSupportGravityForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

GFForms::include_feed_addon_framework();

/**
 * Maps Gravity Forms submissions to Essential Support tickets via the verify-first public
 * form. One feed = one mapping; a form can have several (e.g. different ticket types).
 */
class ESGF_AddOn extends GFFeedAddOn {

	/**
	 * Add-on version.
	 *
	 * @var string
	 */
	protected $_version = ESGF_VERSION;

	/**
	 * Minimum required Gravity Forms version.
	 *
	 * @var string
	 */
	protected $_min_gravityforms_version = '2.5';

	/**
	 * Add-on slug (also the settings option key).
	 *
	 * @var string
	 */
	protected $_slug = ESGF_SLUG;

	/**
	 * Plugin basename.
	 *
	 * @var string
	 */
	protected $_path = ESGF_BASENAME;

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	protected $_full_path = ESGF_FILE;

	/**
	 * Add-on title.
	 *
	 * @var string
	 */
	protected $_title = 'Essential Support for Gravity Forms';

	/**
	 * Add-on short title.
	 *
	 * @var string
	 */
	protected $_short_title = 'Essential Support';

	/**
	 * Singleton.
	 *
	 * @var ESGF_AddOn|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @param mixed $add_on Unused; present only to match the parent signature.
	 * @return ESGF_AddOn
	 */
	public static function get_instance( $add_on = null ) {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks. Fills any Drop Down / Radio field a feed maps as its "type field"
	 * with the workspace's live ticket types — at render, validation, submission, and in
	 * the form editor — so the customer can pick the type in the form itself.
	 *
	 * @return void
	 */
	public function init() {
		parent::init();
		add_filter( 'gform_pre_render', array( $this, 'populate_type_field' ) );
		add_filter( 'gform_pre_validation', array( $this, 'populate_type_field' ) );
		add_filter( 'gform_pre_submission_filter', array( $this, 'populate_type_field' ) );
		add_filter( 'gform_admin_pre_render', array( $this, 'populate_type_field' ) );
	}

	// ── Global settings ──────────────────────────────────────────────────────────────

	/**
	 * Connection settings (workspace URL + API key + optional webhook secret for files).
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		$callback = ESGF_Webhook::callback_url();

		return array(
			array(
				'title'       => esc_html__( 'Essential Support connection', 'essential-support-gravityforms' ),
				'description' => esc_html__( 'Connect this site to your Essential Support workspace. Create an API key under Settings → Integrations in Essential Support, then paste your workspace address and key below.', 'essential-support-gravityforms' ),
				'fields'      => array(
					array(
						'name'              => 'api_base',
						'label'             => esc_html__( 'Workspace URL', 'essential-support-gravityforms' ),
						'type'              => 'text',
						'class'             => 'medium',
						'placeholder'       => 'https://yourworkspace.essential.support',
						'tooltip'           => esc_html__( 'Your workspace address, including https://', 'essential-support-gravityforms' ),
						'feedback_callback' => array( $this, 'is_valid_base' ),
					),
					array(
						'name'       => 'api_key',
						'label'      => esc_html__( 'API key', 'essential-support-gravityforms' ),
						'type'       => 'text',
						'input_type' => 'password',
						'class'      => 'medium',
						'tooltip'    => esc_html__( 'A workspace API key. Stored on your server and never exposed to visitors.', 'essential-support-gravityforms' ),
					),
				),
			),
			array(
				'title'       => esc_html__( 'File attachments (optional)', 'essential-support-gravityforms' ),
				'description' => sprintf(
					/* translators: %s: the webhook callback URL. */
					esc_html__( 'To attach files uploaded on your form, your workspace must have Images & Files enabled. Then, in Essential Support, add a webhook for the ticket.created event pointing at this URL, and paste its signing secret below: %s', 'essential-support-gravityforms' ),
					'<br><code>' . esc_html( $callback ) . '</code>'
				),
				'fields'      => array(
					array(
						'name'       => 'webhook_secret',
						'label'      => esc_html__( 'Webhook signing secret', 'essential-support-gravityforms' ),
						'type'       => 'text',
						'input_type' => 'password',
						'class'      => 'medium',
						'tooltip'    => esc_html__( 'Copy this from the webhook you created in Essential Support. Only needed for attachments.', 'essential-support-gravityforms' ),
					),
				),
			),
		);
	}

	/**
	 * Feedback callback: valid https workspace URL?
	 *
	 * @param string $value Field value.
	 * @return bool
	 */
	public function is_valid_base( $value ) {
		return (bool) preg_match( '#^https://[^\s/]+#i', trim( (string) $value ) );
	}

	// ── Feed settings ────────────────────────────────────────────────────────────────

	/**
	 * A feed maps this form's fields to a ticket. Ticket types come live from the
	 * workspace (form-config); the file field only appears when uploads are enabled.
	 *
	 * @return array
	 */
	public function feed_settings_fields() {
		$config     = $this->get_form_config();
		$has_config = is_array( $config );
		$uploads_on = $has_config && ! empty( $config['uploads']['enabled'] );

		$fields = array(
			array(
				'name'    => 'feed_name',
				'label'   => esc_html__( 'Name', 'essential-support-gravityforms' ),
				'type'    => 'text',
				'class'   => 'medium',
				'tooltip' => esc_html__( 'An optional label for this feed (e.g. “Contact form”).', 'essential-support-gravityforms' ),
			),
			array(
				'name'    => 'field_email',
				'label'   => esc_html__( 'Customer email', 'essential-support-gravityforms' ),
				'type'    => 'field_select',
				'args'    => array( 'input_types' => array( 'email', 'text', 'hidden' ) ),
				'tooltip' => esc_html__( 'Auto-detected from your email field — set only to override. The confirmation link is sent here; nothing is created until they confirm.', 'essential-support-gravityforms' ),
			),
			array(
				'name'    => 'field_subject',
				'label'   => esc_html__( 'Subject', 'essential-support-gravityforms' ),
				'type'    => 'field_select',
				'tooltip' => esc_html__( 'Auto-detected — set only to override. Falls back to the form title if there’s no subject field.', 'essential-support-gravityforms' ),
			),
			array(
				'name'    => 'field_message',
				'label'   => esc_html__( 'Message', 'essential-support-gravityforms' ),
				'type'    => 'field_select',
				'tooltip' => esc_html__( 'Auto-detected from your paragraph field — set only to override.', 'essential-support-gravityforms' ),
			),
			array(
				'name'    => 'field_name',
				'label'   => esc_html__( 'Customer name', 'essential-support-gravityforms' ),
				'type'    => 'field_select',
				'tooltip' => esc_html__( 'Optional. Auto-detected from a name field — set only to override.', 'essential-support-gravityforms' ),
			),
			array(
				'name'    => 'type_field',
				'label'   => esc_html__( 'Ticket type field', 'essential-support-gravityforms' ),
				'type'    => 'field_select',
				'args'    => array( 'input_types' => array( 'select', 'radio' ) ),
				'tooltip' => esc_html__( 'A Drop Down or Radio field the customer uses to pick the type — the add-on fills it with your workspace’s types automatically. Auto-detected from a field named “type”, “category”, etc.; set only to override.', 'essential-support-gravityforms' ),
			),
			array(
				'name'    => 'ticket_type',
				'label'   => esc_html__( 'Default ticket type', 'essential-support-gravityforms' ),
				'type'    => 'select',
				'choices' => $this->ticket_type_choices( $config ),
				'tooltip' => esc_html__( 'Used when the customer doesn’t choose a type. Types are pulled live from your workspace.', 'essential-support-gravityforms' ),
			),
		);

		if ( $uploads_on ) {
			$fields[] = array(
				'name'    => 'field_files',
				'label'   => esc_html__( 'Attach uploaded files', 'essential-support-gravityforms' ),
				'type'    => 'select',
				'choices' => $this->file_field_choices(),
				'tooltip' => esc_html__( 'Auto-detected from your File Upload field — set only to override. Files attach to the ticket after the customer verifies their email.', 'essential-support-gravityforms' ),
			);
		} elseif ( $has_config ) {
			$fields[] = array(
				'name'  => 'field_files_disabled',
				'label' => esc_html__( 'Attach uploaded files', 'essential-support-gravityforms' ),
				'type'  => 'html',
				'html'  => '<em>' . esc_html__( 'Turn on Images & Files in your Essential Support workspace to attach files here.', 'essential-support-gravityforms' ) . '</em>',
			);
		}

		return array(
			array(
				'title'       => esc_html__( 'Essential Support ticket', 'essential-support-gravityforms' ),
				'description' => esc_html__( 'Field mapping is automatic — the add-on detects your email, subject, message, ticket-type, and file-upload fields, so you can usually just save. Override any below only if it guesses wrong. Tip: set this form’s confirmation to tell people to check their email; nothing is submitted until they confirm the link.', 'essential-support-gravityforms' ),
				'fields'      => $fields,
			),
		);
	}

	/**
	 * Columns shown in the feed list table.
	 *
	 * @return array
	 */
	public function feed_list_columns() {
		return array(
			'feed_name'   => esc_html__( 'Name', 'essential-support-gravityforms' ),
			'field_email' => esc_html__( 'Email field', 'essential-support-gravityforms' ),
		);
	}

	/**
	 * Only offer to create a feed once the connection is set.
	 *
	 * @return bool
	 */
	public function can_create_feed() {
		return null !== ESGF_Client::from_settings();
	}

	// ── Choice builders ──────────────────────────────────────────────────────────────

	/**
	 * Ticket-type <select> choices, live from the workspace. Value is the type id (stable
	 * across renames); a leading "None" leaves the ticket untyped.
	 *
	 * @param array|WP_Error|null $config form-config result.
	 * @return array
	 */
	private function ticket_type_choices( $config ) {
		$choices = array(
			array(
				'label' => esc_html__( '— None —', 'essential-support-gravityforms' ),
				'value' => '',
			),
		);
		if ( is_array( $config ) && ! empty( $config['ticketTypes'] ) ) {
			foreach ( $config['ticketTypes'] as $type ) {
				if ( empty( $type['id'] ) ) {
					continue;
				}
				$choices[] = array(
					'label' => isset( $type['name'] ) ? esc_html( $type['name'] ) : esc_html( $type['id'] ),
					'value' => esc_attr( $type['id'] ),
				);
			}
		}
		return $choices;
	}

	/**
	 * <select> of the current form's File Upload fields.
	 *
	 * @return array
	 */
	private function file_field_choices() {
		$choices = array(
			array(
				'label' => esc_html__( '— None —', 'essential-support-gravityforms' ),
				'value' => '',
			),
		);
		$form    = $this->get_current_form();
		if ( is_array( $form ) && ! empty( $form['fields'] ) ) {
			foreach ( $form['fields'] as $field ) {
				if ( 'fileupload' === rgobj( $field, 'type' ) ) {
					$choices[] = array(
						'label' => esc_html( GFCommon::get_label( $field ) ),
						'value' => (string) $field->id,
					);
				}
			}
		}
		return $choices;
	}

	/**
	 * Fill a mapped "type field" (Drop Down / Radio) with the workspace's ticket types so
	 * the customer picks one in the form. No-op when no active feed maps a type field, the
	 * workspace isn't connected, or it returns no types (the field keeps its own choices).
	 *
	 * @param array $form The form.
	 * @return array
	 */
	public function populate_type_field( $form ) {
		if ( empty( $form['fields'] ) ) {
			return $form;
		}
		$targets = array();
		foreach ( $this->get_feeds( rgar( $form, 'id' ) ) as $feed ) {
			if ( empty( $feed['is_active'] ) ) {
				continue;
			}
			$tf = rgars( $feed, 'meta/type_field' );
			if ( '' === (string) $tf ) {
				$tf = $this->guess_field_id( $form, 'type' );
			}
			if ( ! empty( $tf ) ) {
				$targets[ (string) $tf ] = true;
			}
		}
		if ( empty( $targets ) ) {
			return $form;
		}

		$config  = $this->get_form_config();
		$types   = ( is_array( $config ) && ! empty( $config['ticketTypes'] ) ) ? $config['ticketTypes'] : array();
		$choices = array();
		foreach ( $types as $t ) {
			if ( empty( $t['name'] ) ) {
				continue;
			}
			// Value = name: readable in Gravity Forms entries, and ES resolves a type by
			// name or id. The list is re-fetched each render, so it tracks renames.
			$choices[] = array(
				'text'       => $t['name'],
				'value'      => $t['name'],
				'isSelected' => false,
			);
		}
		if ( empty( $choices ) ) {
			return $form;
		}

		foreach ( $form['fields'] as $field ) {
			if ( ! isset( $targets[ (string) $field->id ] ) ) {
				continue;
			}
			$field->choices = $choices;
			if ( empty( $field->placeholder ) && 'select' === $field->get_input_type() ) {
				$field->placeholder = esc_html__( 'Select a type…', 'essential-support-gravityforms' );
			}
		}
		return $form;
	}

	// ── Submission ───────────────────────────────────────────────────────────────────

	/**
	 * Submit the mapped entry to the verify-first public form. Nothing is created in
	 * Essential Support until the customer confirms by email — this only requests the
	 * confirmation. If files are mapped, the ticket.created webhook attaches them later.
	 *
	 * @param array $feed  The feed.
	 * @param array $entry The entry.
	 * @param array $form  The form.
	 * @return void
	 */
	public function process_feed( $feed, $entry, $form ) {
		$client = ESGF_Client::from_settings();
		if ( null === $client ) {
			$this->add_feed_error( esc_html__( 'Essential Support is not connected — check the plugin settings.', 'essential-support-gravityforms' ), $feed, $entry, $form );
			return;
		}

		$email = $this->mapped_value( $form, $entry, $feed, 'field_email', 'email' );
		if ( '' === $email || ! is_email( $email ) ) {
			$this->add_feed_error( esc_html__( 'No valid customer email in the submission — skipped.', 'essential-support-gravityforms' ), $feed, $entry, $form );
			return;
		}

		$subject = $this->mapped_value( $form, $entry, $feed, 'field_subject', 'subject' );
		$message = $this->mapped_value( $form, $entry, $feed, 'field_message', 'message' );
		$name    = $this->mapped_value( $form, $entry, $feed, 'field_name', 'name' );

		$payload = array(
			'email'     => $email,
			'subject'   => ( '' !== $subject ) ? $subject : rgar( $form, 'title' ),
			'message'   => $message,
			'sourceRef' => (string) rgar( $entry, 'id' ),
		);
		// Type precedence: the customer's in-form choice (mapped type field) wins; the
		// feed's default type is the fallback. ES resolves either by name or id.
		$type       = '';
		$type_field = rgars( $feed, 'meta/type_field' );
		if ( '' === (string) $type_field ) {
			$type_field = $this->guess_field_id( $form, 'type' );
		}
		if ( '' !== (string) $type_field ) {
			$type = trim( (string) $this->get_field_value( $form, $entry, $type_field ) );
		}
		if ( '' === $type ) {
			$type = (string) rgars( $feed, 'meta/ticket_type' );
		}
		if ( '' !== $type ) {
			$payload['type'] = $type;
		}
		if ( '' !== $name ) {
			$payload['name'] = $name;
		}

		/**
		 * Filter the ticket payload before it's sent (e.g. to add an externalId your host
		 * app resolved). Return the modified payload.
		 *
		 * @param array $payload The payload.
		 * @param array $entry   The entry.
		 * @param array $form    The form.
		 * @param array $feed    The feed.
		 */
		$payload = apply_filters( 'esgf_ticket_payload', $payload, $entry, $form, $feed );

		$res = $client->submit_public_ticket( $payload );
		if ( is_wp_error( $res ) ) {
			$this->add_feed_error(
				sprintf(
					/* translators: %s: error message. */
					esc_html__( 'Could not send to Essential Support: %s', 'essential-support-gravityforms' ),
					$res->get_error_message()
				),
				$feed,
				$entry,
				$form
			);
			return;
		}

		// Remember which file field (if any) holds attachments so the ticket.created
		// webhook can upload them once the customer verifies and the ticket exists.
		$files_field = rgars( $feed, 'meta/field_files' );
		if ( '' === (string) $files_field ) {
			$files_field = $this->guess_field_id( $form, 'files' );
		}
		if ( ! empty( $files_field ) && '' !== (string) rgar( $entry, $files_field ) ) {
			gform_update_meta( rgar( $entry, 'id' ), 'esgf_files_field', $files_field );
		}

		$this->add_note(
			rgar( $entry, 'id' ),
			esc_html__( 'Sent to Essential Support. Awaiting the customer’s email confirmation.', 'essential-support-gravityforms' ),
			'success'
		);
	}

	/**
	 * Read a mapped field's value from the entry, auto-detecting the field when the feed
	 * doesn't map one (so a feed needs no manual mapping).
	 *
	 * @param array  $form    The form.
	 * @param array  $entry   The entry.
	 * @param array  $feed    The feed.
	 * @param string $setting The feed setting holding the field id.
	 * @param string $kind    What to auto-detect when unmapped (email|subject|message|name|files|type).
	 * @return string
	 */
	private function mapped_value( $form, $entry, $feed, $setting, $kind = '' ) {
		$field_id = rgars( $feed, 'meta/' . $setting );
		if ( '' === (string) $field_id && '' !== $kind ) {
			$field_id = $this->guess_field_id( $form, $kind );
		}
		if ( '' === (string) $field_id ) {
			return '';
		}
		return trim( (string) $this->get_field_value( $form, $entry, $field_id ) );
	}

	/**
	 * Best-guess the form field for a given kind, so the add-on works with little or no
	 * manual feed mapping. Matches first on input type + label keyword, then label keyword
	 * alone, then input type. Returns the field id, or '' if nothing fits. For 'type' a
	 * label keyword is required — a bare Drop Down is never assumed to be the ticket type.
	 *
	 * @param array  $form The form.
	 * @param string $kind email|subject|message|name|files|type.
	 * @return string
	 */
	private function guess_field_id( $form, $kind ) {
		$fields = rgar( $form, 'fields' );
		if ( empty( $fields ) ) {
			return '';
		}
		$types      = array(
			'email'   => array( 'email' ),
			'subject' => array( 'text' ),
			'message' => array( 'textarea' ),
			'name'    => array( 'name' ),
			'files'   => array( 'fileupload' ),
			'type'    => array( 'select', 'radio' ),
		);
		$words      = array(
			'email'   => array( 'email', 'e-mail' ),
			'subject' => array( 'subject', 'topic', 'title', 'regarding' ),
			'message' => array( 'message', 'comment', 'description', 'details', 'inquiry', 'question', 'body' ),
			'name'    => array( 'name' ),
			'type'    => array( 'type', 'category', 'topic', 'reason', 'department', 'about' ),
		);
		$want_types = isset( $types[ $kind ] ) ? $types[ $kind ] : array();
		$want_words = isset( $words[ $kind ] ) ? $words[ $kind ] : array();

		$label_of = static function ( $f ) {
			return strtolower( (string) GFCommon::get_label( $f ) );
		};
		$type_ok  = static function ( $f ) use ( $want_types ) {
			return in_array( $f->get_input_type(), $want_types, true );
		};

		// 1. Right input type AND a matching label keyword (strongest signal).
		foreach ( $fields as $f ) {
			if ( ! $type_ok( $f ) ) {
				continue;
			}
			$label = $label_of( $f );
			foreach ( $want_words as $kw ) {
				if ( false !== strpos( $label, $kw ) ) {
					return (string) $f->id;
				}
			}
		}
		// 2. A matching label keyword, any type (e.g. an email typed into a text field).
		foreach ( $fields as $f ) {
			$label = $label_of( $f );
			foreach ( $want_words as $kw ) {
				if ( false !== strpos( $label, $kw ) ) {
					return (string) $f->id;
				}
			}
		}
		// 3. First field of the right input type — but never guess the ticket type from a
		// bare Drop Down (it might be Country, etc.); a label keyword is required there.
		if ( 'type' !== $kind ) {
			foreach ( $fields as $f ) {
				if ( $type_ok( $f ) ) {
					return (string) $f->id;
				}
			}
		}
		return '';
	}

	/**
	 * Fetch + cache the workspace form-config (ticket types + uploads capability). Cached
	 * briefly per connection so the feed UI doesn't call the API on every render.
	 *
	 * @return array|null
	 */
	private function get_form_config() {
		$client = ESGF_Client::from_settings();
		if ( null === $client ) {
			return null;
		}
		$s   = esgf_settings();
		$key = 'esgf_formcfg_' . md5( ( isset( $s['api_base'] ) ? $s['api_base'] : '' ) . '|' . ( isset( $s['api_key'] ) ? $s['api_key'] : '' ) );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$res = $client->get_form_config();
		if ( is_wp_error( $res ) ) {
			$this->log_error( __METHOD__ . '(): form-config failed: ' . $res->get_error_message() );
			return null;
		}
		set_transient( $key, $res, 5 * MINUTE_IN_SECONDS );
		return $res;
	}
}
