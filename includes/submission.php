<?php

class WPCF8_Submission {

	private static $instance;

	private $contact_form;
	private $status = 'init';
	private $posted_data = array();
	private $uploaded_files = array();
	private $skip_mail = false;
	private $response = '';
	private $invalid_fields = array();
	private $meta = array();

	private function __construct() {}

	public static function get_instance( wpcf8_ContactForm $contact_form = null ) {
		if ( empty( self::$instance ) ) {
			if ( null == $contact_form ) {
				return null;
			}

			self::$instance = new self;
			self::$instance->contact_form = $contact_form;
			self::$instance->skip_mail = $contact_form->in_demo_mode();
			self::$instance->setup_posted_data();
			self::$instance->submit();
		} elseif ( null != $contact_form ) {
			return null;
		}

		return self::$instance;
	}

	public function get_status() {
		return $this->status;
	}

	public function is( $status ) {
		return $this->status == $status;
	}

	public function get_response() {
		return $this->response;
	}

	public function get_invalid_field( $name ) {
		if ( isset( $this->invalid_fields[$name] ) ) {
			return $this->invalid_fields[$name];
		} else {
			return false;
		}
	}

	public function get_invalid_fields() {
		return $this->invalid_fields;
	}

	public function get_posted_data( $name = '' ) {
		if ( ! empty( $name ) ) {
			if ( isset( $this->posted_data[$name] ) ) {
				return $this->posted_data[$name];
			} else {
				return null;
			}
		}

		return $this->posted_data;
	}

	private function setup_posted_data() {
		$posted_data = (array) $_POST;
		$posted_data = array_diff_key( $posted_data, array( '_wpnonce' => '' ) );
		$posted_data = $this->sanitize_posted_data( $posted_data );

		$tags = $this->contact_form->scan_form_tags();

		foreach ( (array) $tags as $tag ) {
			if ( empty( $tag['name'] ) ) {
				continue;
			}

			$name = $tag['name'];
			$value = '';

			if ( isset( $posted_data[$name] ) ) {
				$value = $posted_data[$name];
			}

			$pipes = $tag['pipes'];

			if ( wpcf8_USE_PIPE
			&& $pipes instanceof wpcf8_Pipes
			&& ! $pipes->zero() ) {
				if ( is_array( $value) ) {
					$new_value = array();

					foreach ( $value as $v ) {
						$new_value[] = $pipes->do_pipe( wp_unslash( $v ) );
					}

					$value = $new_value;
				} else {
					$value = $pipes->do_pipe( wp_unslash( $value ) );
				}
			}

			$posted_data[$name] = $value;
		}

		$this->posted_data = apply_filters( 'wpcf8_posted_data', $posted_data );

		return $this->posted_data;
	}

	private function sanitize_posted_data( $value ) {
		if ( is_array( $value ) ) {
			$value = array_map( array( $this, 'sanitize_posted_data' ), $value );
		} elseif ( is_string( $value ) ) {
			$value = wp_check_invalid_utf8( $value );
			$value = wp_kses_no_null( $value );
		}

		return $value;
	}

	private function submit() {
		if ( ! $this->is( 'init' ) ) {
			return $this->status;
		}

		$this->meta = array(
			'remote_ip' => $this->get_remote_ip_addr(),
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] )
				? substr( $_SERVER['HTTP_USER_AGENT'], 0, 254 ) : '',
			'url' => preg_replace( '%(?<!:|/)/.*$%', '',
				untrailingslashit( home_url() ) ) . wpcf8_get_request_uri(),
			'timestamp' => current_time( 'timestamp' ),
			'unit_tag' =>
				isset( $_POST['_wpcf8_unit_tag'] ) ? $_POST['_wpcf8_unit_tag'] : '',
		);

		$contact_form = $this->contact_form;

		if ( ! $this->validate() ) { // Validation error occured
			$this->status = 'validation_failed';
			$this->response = $contact_form->message( 'validation_error' );

		} elseif ( ! $this->accepted() ) { // Not accepted terms
			$this->status = 'acceptance_missing';
			$this->response = $contact_form->message( 'accept_terms' );

		} elseif ( $this->spam() ) { // Spam!
			$this->status = 'spam';
			$this->response = $contact_form->message( 'spam' );

		} elseif ( $this->mail() ) {
			$this->status = 'mail_sent';
			$this->response = $contact_form->message( 'mail_sent_ok' );

			do_action( 'wpcf8_mail_sent', $contact_form );

		} else {
			$this->status = 'mail_failed';
			$this->response = $contact_form->message( 'mail_sent_ng' );

			do_action( 'wpcf8_mail_failed', $contact_form );
		}

		$this->remove_uploaded_files();

		return $this->status;
	}

	private function get_remote_ip_addr() {
		if ( isset( $_SERVER['REMOTE_ADDR'] )
		&& WP_Http::is_ip_address( $_SERVER['REMOTE_ADDR'] ) ) {
			return $_SERVER['REMOTE_ADDR'];
		}

		return '';
	}

	private function validate() {
		if ( $this->invalid_fields ) {
			return false;
		}

		require_once wpcf8_PLUGIN_DIR . '/includes/validation.php';
		$result = new wpcf8_Validation();

		$tags = $this->contact_form->scan_form_tags();

		foreach ( $tags as $tag ) {
			$type = $tag['type'];
			$result = apply_filters( "wpcf8_validate_{$type}", $result, $tag );
		}

		$result = apply_filters( 'wpcf8_validate', $result, $tags );

		$this->invalid_fields = $result->get_invalid_fields();

		return $result->is_valid();
	}

	private function accepted() {
		return apply_filters( 'wpcf8_acceptance', true );
	}

	private function spam() {
		$spam = false;

		$user_agent = (string) $this->get_meta( 'user_agent' );

		if ( strlen( $user_agent ) < 2 ) {
			$spam = true;
		}

		if ( wpcf8_VERIFY_NONCE && ! $this->verify_nonce() ) {
			$spam = true;
		}

		if ( $this->blacklist_check() ) {
			$spam = true;
		}

		return apply_filters( 'wpcf8_spam', $spam );
	}

	private function verify_nonce() {
		return wpcf8_verify_nonce( $_POST['_wpnonce'], $this->contact_form->id() );
	}

	private function blacklist_check() {
		$target = wpcf8_array_flatten( $this->posted_data );
		$target[] = $this->get_meta( 'remote_ip' );
		$target[] = $this->get_meta( 'user_agent' );

		$target = implode( "\n", $target );

		return wpcf8_blacklist_check( $target );
	}

	/* Mail */

	private function mail() {
		$contact_form = $this->contact_form;

		do_action( 'wpcf8_before_send_mail', $contact_form );

		$skip_mail = $this->skip_mail || ! empty( $contact_form->skip_mail );
		$skip_mail = apply_filters( 'wpcf8_skip_mail', $skip_mail, $contact_form );

		if ( $skip_mail ) {
			return true;
		}

		$result = wpcf8_Mail::send( $contact_form->prop( 'mail' ), 'mail' );

		if ( $result ) {
			$additional_mail = array();

			if ( ( $mail_2 = $contact_form->prop( 'mail_2' ) ) && $mail_2['active'] ) {
				$additional_mail['mail_2'] = $mail_2;
			}

			$additional_mail = apply_filters( 'wpcf8_additional_mail',
				$additional_mail, $contact_form );

			foreach ( $additional_mail as $name => $template ) {
				wpcf8_Mail::send( $template, $name );
			}

			return true;
		}

		return false;
	}

	public function uploaded_files() {
		return $this->uploaded_files;
	}

	public function add_uploaded_file( $name, $file_path ) {
		$this->uploaded_files[$name] = $file_path;

		if ( empty( $this->posted_data[$name] ) ) {
			$this->posted_data[$name] = basename( $file_path );
		}
	}

	public function remove_uploaded_files() {
		foreach ( (array) $this->uploaded_files as $name => $path ) {
			wpcf8_rmdir_p( $path );
			@rmdir( dirname( $path ) ); // remove parent dir if it's removable (empty).
		}
	}

	public function get_meta( $name ) {
		if ( isset( $this->meta[$name] ) ) {
			return $this->meta[$name];
		}
	}
}
