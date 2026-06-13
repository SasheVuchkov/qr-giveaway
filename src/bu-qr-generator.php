<?php
/**
 * Plugin Name:  BU QR Code Generator
 * Description:  Generate and manage QR code hashes for the bu-qr-code post type.
 * Version:      1.0.9
 * Author:       Sashe Vuchkov
 * Text Domain:  bu-qr-generator
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Admin menu
// ---------------------------------------------------------------------------

add_action( 'admin_menu', 'buqr_register_menu' );

function buqr_register_menu(): void {
	add_submenu_page(
		'edit.php?post_type=bu-qr-code',
		__( 'Generate QR Codes', 'bu-qr-generator' ),
		__( 'Generate Codes', 'bu-qr-generator' ),
		'manage_options',
		'bu-qr-generator',
		'buqr_render_page'
	);

	add_submenu_page(
		'edit.php?post_type=bu-qr-code',
		__( 'Email Templates', 'bu-qr-generator' ),
		__( 'Email Templates', 'bu-qr-generator' ),
		'manage_options',
		'bu-qr-email-templates',
		'buqr_render_email_templates_page'
	);

	add_submenu_page(
		'edit.php?post_type=bu-qr-code',
		__( 'Generate URLs', 'bu-qr-generator' ),
		__( 'Generate URLs', 'bu-qr-generator' ),
		'manage_options',
		'bu-qr-url-generator',
		'buqr_render_url_generator_page'
	);
}

// ---------------------------------------------------------------------------
// Handle form submission
// ---------------------------------------------------------------------------

add_action( 'admin_post_buqr_generate', 'buqr_handle_generate' );

function buqr_handle_generate(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'Unauthorized.', 'bu-qr-generator' ) );
	}

	check_admin_referer( 'buqr_generate_action', 'buqr_nonce' );

	$count = isset( $_POST['buqr_count'] ) ? absint( $_POST['buqr_count'] ) : 0;

	if ( $count < 1 || $count > 5000 ) {
		wp_safe_redirect( add_query_arg(
			[ 'page' => 'bu-qr-generator', 'buqr_error' => 'invalid_count' ],
			admin_url( 'edit.php?post_type=bu-qr-code' )
		) );
		exit;
	}

	$created_at = current_time( 'mysql' ); // Y-m-d H:i:s in site timezone
	$generated  = 0;

	for ( $i = 0; $i < $count; $i++ ) {
		$hash = buqr_unique_hash();

		$post_id = wp_insert_post( [
			'post_type'   => 'bu-qr-code',
			'post_title'  => buqr_format_code( $hash ), // e.g. ACDF-M3QT-XY79
			'post_status' => 'publish',
		] );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			continue;
		}

		// Store fields directly as post meta (ACF reads these by field name).
		update_post_meta( $post_id, 'bu_qr_code',    $hash );
		update_post_meta( $post_id, 'bu_created_at', $created_at );

		$generated++;
	}

	wp_safe_redirect( add_query_arg(
		[ 'page' => 'bu-qr-generator', 'buqr_generated' => $generated ],
		admin_url( 'edit.php?post_type=bu-qr-code' )
	) );
	exit;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Unambiguous alphabet — removes characters that look alike on stickers/print:
 *   removed: 0 (zero), O, 1, I, L, B (≈8), S (≈5), Z (≈2)
 *   result:  25 characters, 3 groups of 4 → ~56 bits of entropy
 *
 * Codes are stored WITHOUT dashes (e.g. ACDFM3QTXY79) and displayed
 * WITH dashes (ACDF-M3QT-XY79) via buqr_format_code().
 */
const BUQR_ALPHABET    = 'ACDEFGHJKMNPQRTUVWXY34679';
const BUQR_CODE_LENGTH = 12;
const BUQR_GROUP_SIZE  = 4;

/**
 * Returns a single unbiased random character from BUQR_ALPHABET.
 * Uses rejection sampling to eliminate modulo bias.
 */
function buqr_random_char(): string {
	$len = strlen( BUQR_ALPHABET );
	$max = (int) floor( 256 / $len ) * $len - 1;

	do {
		$byte = ord( random_bytes( 1 ) );
	} while ( $byte > $max );

	return BUQR_ALPHABET[ $byte % $len ];
}

/**
 * Generates a unique readable code like ACDFM3QTXY79 (stored without dashes).
 */
function buqr_unique_hash(): string {
	do {
		$code = '';
		for ( $i = 0; $i < BUQR_CODE_LENGTH; $i++ ) {
			$code .= buqr_random_char();
		}
	} while ( buqr_hash_exists( $code ) );

	return $code;
}

/**
 * Formats a stored code for display: ACDFM3QTXY79 → ACDF-M3QT-XY79.
 * Falls back to the raw value for legacy 32-char hex codes.
 */
function buqr_format_code( string $code ): string {
	$clean = strtoupper( str_replace( '-', '', $code ) );

	if ( strlen( $clean ) === BUQR_CODE_LENGTH ) {
		return implode( '-', str_split( $clean, BUQR_GROUP_SIZE ) );
	}

	return $code; // legacy hex code — return as-is
}

/**
 * Normalises user input: strips dashes/spaces and uppercases.
 * Allows users to type codes with or without separators.
 */
function buqr_normalise_code_input( string $raw ): string {
	return strtoupper( preg_replace( '/[\s\-]/', '', sanitize_text_field( $raw ) ) );
}

function buqr_hash_exists( string $hash ): bool {
	global $wpdb;

	return (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'bu_qr_code' AND meta_value = %s",
		$hash
	) );
}

/**
 * Returns the post ID of a bu-qr-code post matching the given hash, or 0 if not found.
 */
function buqr_get_post_id_by_hash( string $hash ): int {
	$posts = get_posts( [
		'post_type'      => 'bu-qr-code',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_query'     => [
			[
				'key'   => 'bu_qr_code',
				'value' => $hash,
			],
		],
	] );

	return ! empty( $posts ) ? (int) $posts[0] : 0;
}

/**
 * Scans Elementor form fields and returns the first valid email address found.
 * Prefers a field whose ID is 'email'; falls back to any field whose type is 'email'.
 */
function buqr_extract_email_from_fields( array $fields ): string {
	if ( ! empty( $fields['email']['value'] ) && is_email( $fields['email']['value'] ) ) {
		return sanitize_email( $fields['email']['value'] );
	}

	foreach ( $fields as $field ) {
		if ( isset( $field['type'], $field['value'] )
			&& 'email' === $field['type']
			&& is_email( $field['value'] )
		) {
			return sanitize_email( $field['value'] );
		}
	}

	return '';
}

/**
 * Returns a text field value by trying a list of candidate IDs in order.
 * Falls back to the first field whose type matches $fallback_type.
 */
function buqr_extract_field_from_fields( array $fields, array $candidate_ids, string $fallback_type = '' ): string {
	foreach ( $candidate_ids as $id ) {
		if ( ! empty( $fields[ $id ]['value'] ) ) {
			return sanitize_text_field( $fields[ $id ]['value'] );
		}
	}

	if ( $fallback_type ) {
		foreach ( $fields as $field ) {
			if ( isset( $field['type'], $field['value'] )
				&& $fallback_type === $field['type']
				&& '' !== $field['value']
			) {
				return sanitize_text_field( $field['value'] );
			}
		}
	}

	return '';
}

/**
 * Generates a confirmation hash: md5( participant_email + qr_code ).
 */
function buqr_make_confirmation_hash( string $email, string $qr_code ): string {
	return md5( $email . $qr_code );
}

/**
 * Looks up a bu-qr-code post by its bu_confirmation_hash meta value.
 * Returns the post ID or 0.
 */
function buqr_get_post_id_by_confirmation_hash( string $c_hash ): int {
	$posts = get_posts( [
		'post_type'      => 'bu-qr-code',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_query'     => [
			[
				'key'   => 'bu_confirmation_hash',
				'value' => $c_hash,
			],
		],
	] );

	return ! empty( $posts ) ? (int) $posts[0] : 0;
}

/**
 * Replaces template placeholders and sends an email.
 * Returns true on successful wp_mail dispatch.
 *
 * Handles both raw {{key}} and TinyMCE/wp_kses HTML-entity-encoded
 * variants (&#123;&#123;key&#125;&#125;) so placeholders are always
 * substituted regardless of how the editor stored them.
 */
function buqr_send_email( string $type, string $to, array $placeholders ): bool {
	$tpl = buqr_get_email_template( $type );

	if ( empty( $tpl['subject'] ) || empty( $tpl['body'] ) || empty( $tpl['from_email'] ) ) {
		return false;
	}

	$keys    = array_keys( $placeholders );
	$replace = array_values( $placeholders );

	// Build both the raw and HTML-entity-encoded search patterns.
	$search_raw     = array_map( fn( $k ) => '{{' . $k . '}}',                              $keys );
	$search_encoded = array_map( fn( $k ) => '&#123;&#123;' . $k . '&#125;&#125;',          $keys );
	$search_curly   = array_map( fn( $k ) => "\u{007B}\u{007B}" . $k . "\u{007D}\u{007D}",  $keys );

	$subject = $tpl['subject'];
	$body    = $tpl['body'];

	foreach ( [ $search_raw, $search_encoded, $search_curly ] as $search ) {
		$subject = str_replace( $search, $replace, $subject );
		$body    = str_replace( $search, $replace, $body );
	}

	$from    = sprintf( '%s <%s>', $tpl['from_name'], $tpl['from_email'] );
	$headers = [
		'Content-Type: text/html; charset=UTF-8',
		"From: {$from}",
		"Reply-To: {$tpl['reply_to']}",
	];

	return wp_mail( $to, $subject, $body, $headers );
}

// ---------------------------------------------------------------------------
// Elementor Pro form hook
// ---------------------------------------------------------------------------

add_action( 'elementor_pro/forms/new_record', 'buqr_handle_elementor_form_submission', 10, 2 );

function buqr_handle_elementor_form_submission( $record, $ajax_handler ): void {
	$fields = $record->get( 'fields' );

	// Not a QR form — no field defined at all, nothing to do.
	if ( ! array_key_exists( 'unique_qr_code', $fields ) ) {
		return;
	}

	$raw_value = $fields['unique_qr_code']['value'] ?? '';

	// ── 1. Field is present but empty ──────────────────────────────────────
	if ( '' === trim( $raw_value ) ) {
		$ajax_handler->add_error_message(
			__( 'Please enter your QR code.', 'bu-qr-generator' )
		);
		return;
	}

	// Strip dashes/spaces and uppercase — accepts ACDF-M3QT-XY79 or ACDFM3QTXY79.
	$hash = buqr_normalise_code_input( $raw_value );

	// ── 2. Format check — new 12-char readable format OR legacy 32-char hex ─
	$is_new_format = (bool) preg_match( '/^[ACDEFGHJKMNPQRTUVWXY34679]{12}$/', $hash );
	$is_old_format = (bool) preg_match( '/^[A-F0-9]{32}$/', $hash );

	if ( ! $is_new_format && ! $is_old_format ) {
		$ajax_handler->add_error_message(
			__( 'This QR code is not valid.', 'bu-qr-generator' )
		);
		return;
	}

	// ── 3. Code must exist in the database ─────────────────────────────────
	$post_id = buqr_get_post_id_by_hash( $hash );

	if ( ! $post_id ) {
		$ajax_handler->add_error_message(
			__( 'This QR code was not found. Please check and try again.', 'bu-qr-generator' )
		);
		return;
	}

	// ── 4. Code must not already be claimed ────────────────────────────────
	$claimed_at = get_post_meta( $post_id, 'bu_claimed_at', true );

	if ( ! empty( $claimed_at ) ) {
		$ajax_handler->add_error_message(
			__( 'This QR code has already been used.', 'bu-qr-generator' )
		);
		return;
	}

	// ── 5. Extract participant details ─────────────────────────────────────
	$email = buqr_extract_email_from_fields( $fields );
	$name  = buqr_extract_field_from_fields( $fields, [ 'name', 'full_name', 'your-name' ] );
	$phone = buqr_extract_field_from_fields( $fields, [ 'phone', 'telephone', 'mobile' ], 'tel' );

	if ( ! $email ) {
		// No email — stamp claimed only, nothing to send.
		update_post_meta( $post_id, 'bu_claimed_at', current_time( 'mysql' ) );
		return;
	}

	// ── 6. Record participant data & generate confirmation hash ────────────
	$c_hash = buqr_make_confirmation_hash( $email, $hash );

	update_post_meta( $post_id, 'bu_participant_email', $email );
	update_post_meta( $post_id, 'bu_participant_name',  $name );
	update_post_meta( $post_id, 'bu_participant_phone', $phone );
	update_post_meta( $post_id, 'bu_confirmation_hash', $c_hash );

	// ── 7. Send confirmation email ─────────────────────────────────────────
	$confirmation_link = add_query_arg(
		[ 'c_hash' => $c_hash ],
		buqr_get_page_url( 'buqr_confirm_page_id' )
	);

	$placeholders = [
		'qr_code'           => $hash,
		'site_name'         => get_bloginfo( 'name' ),
		'participant_name'  => $name,
		'participant_email' => $email,
		'participant_phone' => $phone,
		'confirmation_link' => $confirmation_link,
	];

	$now = current_time( 'mysql' );

	buqr_send_email( 'confirmation', $email, $placeholders );

	// ── 8. Stamp claimed + emailed timestamps ──────────────────────────────
	update_post_meta( $post_id, 'bu_claimed_at', $now );
	update_post_meta( $post_id, 'bu_emailed_at', $now );
}

// ---------------------------------------------------------------------------
// Confirmation page — template_redirect hook
// ---------------------------------------------------------------------------

/**
 * Returns the permalink for a page stored as an option, falling back to home_url('/').
 */
function buqr_get_page_url( string $option_key ): string {
	$page_id = (int) get_option( $option_key, 0 );

	if ( $page_id ) {
		$url = get_permalink( $page_id );
		if ( $url ) {
			return $url;
		}
	}

	return home_url( '/' );
}

/**
 * Intercepts any page request that carries ?c_hash=<value>.
 *
 * Valid hash   → stamps bu_confirmed_at and redirects to the congratulations page.
 * Invalid hash → redirects to the invalid-code page.
 */
add_action( 'template_redirect', 'buqr_handle_confirmation_page' );

function buqr_handle_confirmation_page(): void {
	if ( empty( $_GET['c_hash'] ) ) {
		return;
	}

	// Tell caching plugins / CDNs not to cache this response.
	nocache_headers();

	$c_hash = sanitize_text_field( wp_unslash( $_GET['c_hash'] ) );

	$post_id = buqr_get_post_id_by_confirmation_hash( $c_hash );

	if ( ! $post_id ) {
		wp_safe_redirect( buqr_get_page_url( 'buqr_invalid_page_id' ) );
		exit;
	}

	$qr_code      = get_post_meta( $post_id, 'bu_qr_code',      true );
	$confirmed_at = get_post_meta( $post_id, 'bu_confirmed_at', true );

	// Stamp the first confirmation visit; allow re-visits without overwriting.
	if ( empty( $confirmed_at ) ) {
		$now = current_time( 'mysql' );
		update_post_meta( $post_id, 'bu_confirmed_at', $now );

		$email = get_post_meta( $post_id, 'bu_participant_email', true );
		$name  = get_post_meta( $post_id, 'bu_participant_name',  true );
		$phone = get_post_meta( $post_id, 'bu_participant_phone', true );

		if ( is_email( $email ) ) {
			$placeholders = [
				'qr_code'           => buqr_format_code( $qr_code ),
				'site_name'         => get_bloginfo( 'name' ),
				'participant_name'  => $name,
				'participant_email' => $email,
				'participant_phone' => (string) $phone,
			];

			buqr_send_email( 'welcome', $email, $placeholders );
		}
	}

	wp_safe_redirect( add_query_arg(
		[ 'code' => buqr_format_code( $qr_code ) ],
		buqr_get_page_url( 'buqr_congratulations_page_id' )
	) );
	exit;
}

// ---------------------------------------------------------------------------
// Post list — custom columns
// ---------------------------------------------------------------------------

add_filter( 'manage_bu-qr-code_posts_columns', 'buqr_add_list_columns' );

function buqr_add_list_columns( array $columns ): array {
	// Insert after the title column.
	$insert = [
		'buqr_status'    => __( 'Status', 'bu-qr-generator' ),
		'buqr_claimed'   => __( 'Claimed', 'bu-qr-generator' ),
		'buqr_emailed'   => __( 'Emailed', 'bu-qr-generator' ),
		'buqr_confirmed' => __( 'Confirmed', 'bu-qr-generator' ),
	];

	$pos     = array_search( 'title', array_keys( $columns ), true );
	$before  = array_slice( $columns, 0, $pos + 1, true );
	$after   = array_slice( $columns, $pos + 1, null, true );

	return $before + $insert + $after;
}

add_action( 'manage_bu-qr-code_posts_custom_column', 'buqr_render_list_column', 10, 2 );

function buqr_render_list_column( string $column, int $post_id ): void {
	switch ( $column ) {

		case 'buqr_status':
			$claimed    = get_post_meta( $post_id, 'bu_claimed_at',   true );
			$confirmed  = get_post_meta( $post_id, 'bu_confirmed_at', true );

			if ( $confirmed ) {
				echo '<span class="buqr-badge buqr-badge--confirmed">'
					. esc_html__( 'Confirmed', 'bu-qr-generator' )
					. '</span>';
			} elseif ( $claimed ) {
				echo '<span class="buqr-badge buqr-badge--claimed">'
					. esc_html__( 'Claimed', 'bu-qr-generator' )
					. '</span>';
			} else {
				echo '<span class="buqr-badge buqr-badge--free">'
					. esc_html__( 'Available', 'bu-qr-generator' )
					. '</span>';
			}
			break;

		case 'buqr_claimed':
			buqr_render_date_cell( get_post_meta( $post_id, 'bu_claimed_at', true ) );
			break;

		case 'buqr_emailed':
			buqr_render_date_cell( get_post_meta( $post_id, 'bu_emailed_at', true ) );
			break;

		case 'buqr_confirmed':
			buqr_render_date_cell( get_post_meta( $post_id, 'bu_confirmed_at', true ) );
			break;
	}
}

function buqr_render_date_cell( string $datetime ): void {
	if ( ! $datetime ) {
		echo '<span class="buqr-none">—</span>';
		return;
	}

	$ts      = strtotime( $datetime );
	$display = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );

	printf( '<span title="%s">%s</span>', esc_attr( $datetime ), esc_html( $display ) );
}

// ── Sortable columns ────────────────────────────────────────────────────────

add_filter( 'manage_edit-bu-qr-code_sortable_columns', 'buqr_sortable_columns' );

function buqr_sortable_columns( array $columns ): array {
	$columns['buqr_status']    = 'buqr_status';
	$columns['buqr_claimed']   = 'buqr_claimed';
	$columns['buqr_emailed']   = 'buqr_emailed';
	$columns['buqr_confirmed'] = 'buqr_confirmed';

	return $columns;
}

add_action( 'pre_get_posts', 'buqr_sort_by_column' );

function buqr_sort_by_column( WP_Query $query ): void {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( 'bu-qr-code' !== $query->get( 'post_type' ) ) {
		return;
	}

	$orderby_map = [
		'buqr_status'    => 'bu_claimed_at',
		'buqr_claimed'   => 'bu_claimed_at',
		'buqr_emailed'   => 'bu_emailed_at',
		'buqr_confirmed' => 'bu_confirmed_at',
	];

	$orderby = $query->get( 'orderby' );

	if ( ! isset( $orderby_map[ $orderby ] ) ) {
		return;
	}

	$query->set( 'meta_key', $orderby_map[ $orderby ] );
	$query->set( 'orderby',  'meta_value' );
}

// ── Column styles (inline, scoped to the list screen) ──────────────────────

add_action( 'admin_head', 'buqr_column_styles' );

function buqr_column_styles(): void {
	$screen = get_current_screen();

	if ( ! $screen || 'edit-bu-qr-code' !== $screen->id ) {
		return;
	}
	?>
	<style>
		.column-buqr_status    { width: 90px; }
		.column-buqr_claimed,
		.column-buqr_emailed,
		.column-buqr_confirmed { width: 140px; font-size: 12px; color: #50575e; }

		.buqr-badge {
			display: inline-block;
			padding: 2px 9px;
			border-radius: 10px;
			font-size: 11px;
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: .4px;
		}

		.buqr-badge--free {
			background: #edfaef;
			color: #1a7734;
			border: 1px solid #b8e6c1;
		}

		.buqr-badge--claimed {
			background: #fef8ec;
			color: #8a5500;
			border: 1px solid #f5d97a;
		}

		.buqr-badge--confirmed {
			background: #e8f4fc;
			color: #1a5276;
			border: 1px solid #aed6f1;
		}

		.buqr-none { color: #c3c4c7; }
	</style>
	<?php
}

// ---------------------------------------------------------------------------
// Post list — Resend Confirmation Email row action
// ---------------------------------------------------------------------------

add_filter( 'post_row_actions', 'buqr_add_resend_row_action', 10, 2 );

function buqr_add_resend_row_action( array $actions, WP_Post $post ): array {
	if ( 'bu-qr-code' !== $post->post_type ) {
		return $actions;
	}

	// Only show the action when the post has a participant email to send to.
	$email = get_post_meta( $post->ID, 'bu_participant_email', true );

	if ( ! $email ) {
		return $actions;
	}

	$url = wp_nonce_url(
		add_query_arg(
			[
				'action'  => 'buqr_resend_email',
				'post_id' => $post->ID,
			],
			admin_url( 'admin-post.php' )
		),
		'buqr_resend_email_' . $post->ID
	);

	$actions['buqr_resend'] = sprintf(
		'<a href="%s">%s</a>',
		esc_url( $url ),
		esc_html__( 'Resend Confirmation', 'bu-qr-generator' )
	);

	return $actions;
}

// ── Handler ────────────────────────────────────────────────────────────────

add_action( 'admin_post_buqr_resend_email', 'buqr_handle_resend_email' );

function buqr_handle_resend_email(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'Unauthorized.', 'bu-qr-generator' ) );
	}

	$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

	check_admin_referer( 'buqr_resend_email_' . $post_id );

	$redirect_base = admin_url( 'edit.php?post_type=bu-qr-code' );

	if ( ! $post_id || 'bu-qr-code' !== get_post_type( $post_id ) ) {
		wp_safe_redirect( add_query_arg( 'buqr_resend' , 'invalid_post', $redirect_base ) );
		exit;
	}

	$email   = get_post_meta( $post_id, 'bu_participant_email', true );
	$name    = get_post_meta( $post_id, 'bu_participant_name',  true );
	$qr_code = get_post_meta( $post_id, 'bu_qr_code',          true );
	$c_hash  = get_post_meta( $post_id, 'bu_confirmation_hash', true );

	if ( ! is_email( $email ) || ! $c_hash ) {
		wp_safe_redirect( add_query_arg( 'buqr_resend', 'missing_data', $redirect_base ) );
		exit;
	}

	$confirmation_link = add_query_arg(
		[ 'c_hash' => $c_hash ],
		buqr_get_page_url( 'buqr_confirm_page_id' )
	);

	$phone = get_post_meta( $post_id, 'bu_participant_phone', true );

	$placeholders = [
		'qr_code'           => $qr_code,
		'site_name'         => get_bloginfo( 'name' ),
		'participant_name'  => $name,
		'participant_email' => $email,
		'participant_phone' => (string) $phone,
		'confirmation_link' => $confirmation_link,
	];

	$sent = buqr_send_email( 'confirmation', $email, $placeholders );

	if ( $sent ) {
		update_post_meta( $post_id, 'bu_emailed_at', current_time( 'mysql' ) );
		wp_safe_redirect( add_query_arg( 'buqr_resend', 'success', $redirect_base ) );
	} else {
		wp_safe_redirect( add_query_arg( 'buqr_resend', 'failed', $redirect_base ) );
	}

	exit;
}

// ── Admin notice feedback ───────────────────────────────────────────────────

add_action( 'admin_notices', 'buqr_resend_admin_notice' );

function buqr_resend_admin_notice(): void {
	$screen = get_current_screen();

	if ( ! $screen || 'edit-bu-qr-code' !== $screen->id ) {
		return;
	}

	if ( empty( $_GET['buqr_resend'] ) ) {
		return;
	}

	$status   = sanitize_key( $_GET['buqr_resend'] );
	$messages = [
		'success'      => [ 'success', __( 'Confirmation email resent successfully.', 'bu-qr-generator' ) ],
		'failed'       => [ 'error',   __( 'The email could not be sent. Please check your site\'s mail configuration.', 'bu-qr-generator' ) ],
		'missing_data' => [ 'warning', __( 'Cannot resend: this QR code has no participant email or confirmation hash on record.', 'bu-qr-generator' ) ],
		'invalid_post' => [ 'error',   __( 'Cannot resend: invalid QR code record.', 'bu-qr-generator' ) ],
	];

	if ( ! isset( $messages[ $status ] ) ) {
		return;
	}

	[ $type, $text ] = $messages[ $status ];

	printf(
		'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
		esc_attr( $type ),
		esc_html( $text )
	);
}

// ---------------------------------------------------------------------------
// Stats helpers
// ---------------------------------------------------------------------------

function buqr_count_total(): int {
	$q = new WP_Query( [
		'post_type'      => 'bu-qr-code',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	] );
	return (int) $q->found_posts;
}

function buqr_count_with_meta( string $meta_key ): int {
	$q = new WP_Query( [
		'post_type'      => 'bu-qr-code',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => [
			[
				'key'     => $meta_key,
				'value'   => '',
				'compare' => '!=',
			],
		],
	] );
	return (int) $q->found_posts;
}

// ---------------------------------------------------------------------------
// Admin page renderer
// ---------------------------------------------------------------------------

function buqr_render_page(): void {
	$total     = buqr_count_total();
	$emailed   = buqr_count_with_meta( 'bu_emailed_at' );
	$claimed   = buqr_count_with_meta( 'bu_claimed_at' );
	$confirmed = buqr_count_with_meta( 'bu_confirmed_at' );

	$generated = isset( $_GET['buqr_generated'] ) ? absint( $_GET['buqr_generated'] ) : null;
	$error     = isset( $_GET['buqr_error'] )     ? sanitize_key( $_GET['buqr_error'] ) : null;

	$stats = [
		[
			'label' => __( 'Total Created', 'bu-qr-generator' ),
			'value' => $total,
			'color' => '#2271b1',
			'icon'  => 'dashicons-qr-code',
		],
		[
			'label' => __( 'Emailed', 'bu-qr-generator' ),
			'value' => $emailed,
			'color' => '#8764b8',
			'icon'  => 'dashicons-email-alt',
		],
		[
			'label' => __( 'Claimed', 'bu-qr-generator' ),
			'value' => $claimed,
			'color' => '#d63638',
			'icon'  => 'dashicons-saved',
		],
		[
			'label' => __( 'Confirmed', 'bu-qr-generator' ),
			'value' => $confirmed,
			'color' => '#00a32a',
			'icon'  => 'dashicons-yes-alt',
		],
	];
	?>
	<div class="wrap" id="buqr-wrap">
		<h1><?php esc_html_e( 'QR Code Generator', 'bu-qr-generator' ); ?></h1>

		<?php if ( null !== $generated ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						/* translators: %d = number of codes generated */
						esc_html__( 'Successfully generated %d QR code(s).', 'bu-qr-generator' ),
						$generated
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( 'invalid_count' === $error ) : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Please enter a number between 1 and 1,000.', 'bu-qr-generator' ); ?></p>
			</div>
		<?php endif; ?>

		<!-- ── Stats ── -->
		<div id="buqr-stats">
			<?php foreach ( $stats as $stat ) : ?>
				<div class="buqr-stat-card" style="border-top-color: <?php echo esc_attr( $stat['color'] ); ?>">
					<span class="buqr-stat-icon dashicons <?php echo esc_attr( $stat['icon'] ); ?>"
						  style="color: <?php echo esc_attr( $stat['color'] ); ?>"></span>
					<span class="buqr-stat-value"><?php echo esc_html( number_format_i18n( $stat['value'] ) ); ?></span>
					<span class="buqr-stat-label"><?php echo esc_html( $stat['label'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<!-- ── Generator form ── -->
		<div id="buqr-form-card" class="postbox">
			<div class="postbox-header">
				<h2 class="hndle"><?php esc_html_e( 'Generate New QR Codes', 'bu-qr-generator' ); ?></h2>
			</div>
			<div class="inside">
				<p class="description">
					<?php esc_html_e( 'Enter how many unique QR code hashes to generate. Each code is stamped with the current date and time.', 'bu-qr-generator' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="buqr_generate">
					<?php wp_nonce_field( 'buqr_generate_action', 'buqr_nonce' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="buqr_count">
									<?php esc_html_e( 'Number of codes', 'bu-qr-generator' ); ?>
								</label>
							</th>
							<td>
								<input
									type="number"
									id="buqr_count"
									name="buqr_count"
									class="small-text"
									value="10"
									min="1"
									max="1000"
									required
								>
								<p class="description"><?php esc_html_e( 'Maximum 1,000 per batch.', 'bu-qr-generator' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Generate QR Codes', 'bu-qr-generator' ), 'primary large' ); ?>
				</form>
			</div>
		</div>
	</div><!-- .wrap -->

	<style>
		#buqr-wrap { max-width: 900px; }

		#buqr-stats {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
			gap: 16px;
			margin: 24px 0 28px;
		}

		.buqr-stat-card {
			background: #fff;
			border: 1px solid #c3c4c7;
			border-top-width: 4px;
			border-radius: 4px;
			padding: 20px 22px 18px;
			display: flex;
			flex-direction: column;
			align-items: flex-start;
			gap: 4px;
			box-shadow: 0 1px 3px rgba(0,0,0,.06);
		}

		.buqr-stat-icon {
			font-size: 26px;
			width: 26px;
			height: 26px;
			margin-bottom: 6px;
		}

		.buqr-stat-value {
			font-size: 36px;
			font-weight: 700;
			line-height: 1;
			color: #1d2327;
		}

		.buqr-stat-label {
			font-size: 13px;
			color: #646970;
			margin-top: 2px;
		}

		#buqr-form-card .inside {
			padding: 16px 20px 20px;
		}

	#buqr-form-card .description {
		margin-bottom: 14px;
	}
</style>
	<?php
}

// ---------------------------------------------------------------------------
// Email Templates — defaults
// ---------------------------------------------------------------------------

function buqr_email_types(): array {
	return [
		'confirmation' => __( 'Confirmation Email', 'bu-qr-generator' ),
		'welcome'      => __( 'Welcome Email', 'bu-qr-generator' ),
	];
}

function buqr_email_defaults( string $type ): array {
	$site = get_bloginfo( 'name' );
	$from = get_bloginfo( 'admin_email' );

	$defaults = [
		'confirmation' => [
			'subject'    => sprintf( __( 'Please confirm your QR code – %s', 'bu-qr-generator' ), $site ),
			'from_name'  => $site,
			'from_email' => $from,
			'reply_to'   => $from,
			'body'       => implode( "\n", [
				'<p>Hello {{participant_name}},</p>',
				'<p>Please click the link below to confirm your QR code:</p>',
				'<p><a href="{{confirmation_link}}">Confirm my code</a></p>',
				'<p>Your code: <strong>{{qr_code}}</strong></p>',
				'<p>Thanks,<br>{{site_name}}</p>',
			] ),
		],
		'welcome'      => [
			'subject'    => sprintf( __( 'Welcome to %s', 'bu-qr-generator' ), $site ),
			'from_name'  => $site,
			'from_email' => $from,
			'reply_to'   => $from,
			'body'       => implode( "\n", [
				'<p>Welcome {{participant_name}},</p>',
				'<p>Your QR code <strong>{{qr_code}}</strong> is now active.</p>',
				'<p>Thanks,<br>{{site_name}}</p>',
			] ),
		],
	];

	return $defaults[ $type ] ?? [];
}

function buqr_get_email_template( string $type ): array {
	$saved    = get_option( "buqr_email_{$type}", [] );
	$defaults = buqr_email_defaults( $type );
	return wp_parse_args( $saved, $defaults );
}

// ---------------------------------------------------------------------------
// Email Templates — save handler
// ---------------------------------------------------------------------------

add_action( 'admin_post_buqr_save_email_templates', 'buqr_handle_save_email_templates' );

function buqr_handle_save_email_templates(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'Unauthorized.', 'bu-qr-generator' ) );
	}

	check_admin_referer( 'buqr_email_templates_action', 'buqr_email_nonce' );

	foreach ( array_keys( buqr_email_types() ) as $type ) {
		$data = [
			'subject'    => sanitize_text_field( $_POST[ "{$type}_subject" ]    ?? '' ),
			'from_name'  => sanitize_text_field( $_POST[ "{$type}_from_name" ]  ?? '' ),
			'from_email' => sanitize_email(      $_POST[ "{$type}_from_email" ] ?? '' ),
			'reply_to'   => sanitize_email(      $_POST[ "{$type}_reply_to" ]   ?? '' ),
			'body'       => wp_kses_post(         $_POST[ "{$type}_body" ]       ?? '' ),
		];

		update_option( "buqr_email_{$type}", $data );
	}

	update_option( 'buqr_confirm_page_id',        absint( $_POST['buqr_confirm_page_id']        ?? 0 ) );
	update_option( 'buqr_congratulations_page_id', absint( $_POST['buqr_congratulations_page_id'] ?? 0 ) );
	update_option( 'buqr_invalid_page_id',         absint( $_POST['buqr_invalid_page_id']         ?? 0 ) );

	wp_safe_redirect( add_query_arg(
		[ 'page' => 'bu-qr-email-templates', 'buqr_saved' => '1' ],
		admin_url( 'edit.php?post_type=bu-qr-code' )
	) );
	exit;
}

// ---------------------------------------------------------------------------
// Email Templates — page renderer
// ---------------------------------------------------------------------------

function buqr_render_email_templates_page(): void {
	$saved  = isset( $_GET['buqr_saved'] ) && '1' === $_GET['buqr_saved'];
	$active = isset( $_GET['buqr_tab'] ) ? sanitize_key( $_GET['buqr_tab'] ) : 'confirmation';
	$types  = buqr_email_types();

	if ( ! array_key_exists( $active, $types ) ) {
		$active = 'confirmation';
	}
	?>
	<div class="wrap" id="buqr-tpl-wrap">
		<h1><?php esc_html_e( 'Email Templates', 'bu-qr-generator' ); ?></h1>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Email templates saved.', 'bu-qr-generator' ); ?></p>
			</div>
		<?php endif; ?>

		<!-- ── Tabs ── -->
		<nav class="nav-tab-wrapper buqr-tabs" aria-label="<?php esc_attr_e( 'Email type tabs', 'bu-qr-generator' ); ?>">
			<?php foreach ( $types as $key => $label ) :
				$tab_url = add_query_arg(
					[ 'post_type' => 'bu-qr-code', 'page' => 'bu-qr-email-templates', 'buqr_tab' => $key ],
					admin_url( 'edit.php' )
				);
				$is_active = $key === $active;
				?>
				<a href="<?php echo esc_url( $tab_url ); ?>"
				   class="nav-tab<?php echo $is_active ? ' nav-tab-active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<!-- ── Form ── -->
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="buqr_save_email_templates">
			<input type="hidden" name="buqr_tab"  value="<?php echo esc_attr( $active ); ?>">
			<?php wp_nonce_field( 'buqr_email_templates_action', 'buqr_email_nonce' ); ?>

			<?php foreach ( $types as $type => $label ) :
				$tpl    = buqr_get_email_template( $type );
				$hidden = $type !== $active ? ' buqr-tab-panel--hidden' : '';
				?>
				<div class="buqr-tab-panel<?php echo esc_attr( $hidden ); ?>" id="buqr-panel-<?php echo esc_attr( $type ); ?>">

					<div class="postbox" style="margin-top: 16px;">
						<div class="postbox-header">
							<h2 class="hndle"><?php echo esc_html( $label ); ?></h2>
						</div>
						<div class="inside buqr-tpl-inside">

							<table class="form-table" role="presentation">

								<tr>
									<th scope="row">
										<label for="<?php echo esc_attr( "{$type}_subject" ); ?>">
											<?php esc_html_e( 'Subject Line', 'bu-qr-generator' ); ?>
										</label>
									</th>
									<td>
										<input
											type="text"
											id="<?php echo esc_attr( "{$type}_subject" ); ?>"
											name="<?php echo esc_attr( "{$type}_subject" ); ?>"
											value="<?php echo esc_attr( $tpl['subject'] ); ?>"
											class="large-text"
										>
									</td>
								</tr>

								<tr>
									<th scope="row">
										<label for="<?php echo esc_attr( "{$type}_from_name" ); ?>">
											<?php esc_html_e( 'From Name', 'bu-qr-generator' ); ?>
										</label>
									</th>
									<td>
										<input
											type="text"
											id="<?php echo esc_attr( "{$type}_from_name" ); ?>"
											name="<?php echo esc_attr( "{$type}_from_name" ); ?>"
											value="<?php echo esc_attr( $tpl['from_name'] ); ?>"
											class="regular-text"
										>
									</td>
								</tr>

								<tr>
									<th scope="row">
										<label for="<?php echo esc_attr( "{$type}_from_email" ); ?>">
											<?php esc_html_e( 'From Email', 'bu-qr-generator' ); ?>
										</label>
									</th>
									<td>
										<input
											type="email"
											id="<?php echo esc_attr( "{$type}_from_email" ); ?>"
											name="<?php echo esc_attr( "{$type}_from_email" ); ?>"
											value="<?php echo esc_attr( $tpl['from_email'] ); ?>"
											class="regular-text"
										>
									</td>
								</tr>

								<tr>
									<th scope="row">
										<label for="<?php echo esc_attr( "{$type}_reply_to" ); ?>">
											<?php esc_html_e( 'Reply-To', 'bu-qr-generator' ); ?>
										</label>
									</th>
									<td>
										<input
											type="email"
											id="<?php echo esc_attr( "{$type}_reply_to" ); ?>"
											name="<?php echo esc_attr( "{$type}_reply_to" ); ?>"
											value="<?php echo esc_attr( $tpl['reply_to'] ); ?>"
											class="regular-text"
										>
									</td>
								</tr>

								<tr>
									<th scope="row">
										<label for="<?php echo esc_attr( "{$type}_body" ); ?>">
											<?php esc_html_e( 'HTML Body', 'bu-qr-generator' ); ?>
										</label>
									</th>
									<td>
										<?php
										wp_editor(
											$tpl['body'],
											"{$type}_body",
											[
												'textarea_name' => "{$type}_body",
												'textarea_rows' => 18,
												'media_buttons' => false,
												'teeny'         => false,
												'tinymce'       => [
													'toolbar1' => 'bold,italic,underline,separator,link,unlink,separator,bullist,numlist,separator,undo,redo,separator,code',
													'toolbar2' => '',
												],
											]
										);
										?>
										<?php
										$all_vars = [
											'{{participant_name}}'  => __( 'Participant\'s name', 'bu-qr-generator' ),
											'{{participant_email}}' => __( 'Participant\'s email', 'bu-qr-generator' ),
											'{{participant_phone}}' => __( 'Participant\'s phone', 'bu-qr-generator' ),
											'{{qr_code}}'           => __( 'QR hash', 'bu-qr-generator' ),
											'{{site_name}}'         => __( 'Site name', 'bu-qr-generator' ),
										];
										if ( 'confirmation' === $type ) {
											$all_vars = [ '{{confirmation_link}}' => __( 'Confirmation URL', 'bu-qr-generator' ) ] + $all_vars;
										}
										?>
										<p class="description" style="margin-top:10px;">
											<strong><?php esc_html_e( 'Available placeholders:', 'bu-qr-generator' ); ?></strong>
										</p>
										<table class="buqr-vars-table">
											<?php foreach ( $all_vars as $tag => $desc ) : ?>
												<tr>
													<td><code><?php echo esc_html( $tag ); ?></code></td>
													<td><?php echo esc_html( $desc ); ?></td>
												</tr>
											<?php endforeach; ?>
										</table>
									</td>
								</tr>

							</table>
						</div><!-- .inside -->
					</div><!-- .postbox -->

				</div><!-- .buqr-tab-panel -->
			<?php endforeach; ?>

			<!-- ── Page Settings ── -->
			<div class="postbox" style="margin-top: 24px;">
				<div class="postbox-header">
					<h2 class="hndle"><?php esc_html_e( 'Page Settings', 'bu-qr-generator' ); ?></h2>
				</div>
				<div class="inside buqr-tpl-inside">
					<p class="description" style="margin-bottom:14px;">
						<?php esc_html_e( 'Select the WordPress pages used for QR code confirmation and invalid-code redirects.', 'bu-qr-generator' ); ?>
					</p>

					<table class="form-table" role="presentation">

						<tr>
							<th scope="row">
								<label for="buqr_confirm_page_id">
									<?php esc_html_e( 'Confirmation Page', 'bu-qr-generator' ); ?>
								</label>
							</th>
							<td>
								<?php
								wp_dropdown_pages( [
									'name'              => 'buqr_confirm_page_id',
									'id'                => 'buqr_confirm_page_id',
									'selected'          => (int) get_option( 'buqr_confirm_page_id', 0 ),
									'show_option_none'  => __( '— Select a page —', 'bu-qr-generator' ),
									'option_none_value' => '0',
								] );
								?>
								<p class="description">
									<?php esc_html_e( 'The page that processes the confirmation link. The ?c_hash= parameter is appended to its URL.', 'bu-qr-generator' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="buqr_congratulations_page_id">
									<?php esc_html_e( 'Congratulations Page', 'bu-qr-generator' ); ?>
								</label>
							</th>
							<td>
								<?php
								wp_dropdown_pages( [
									'name'              => 'buqr_congratulations_page_id',
									'id'                => 'buqr_congratulations_page_id',
									'selected'          => (int) get_option( 'buqr_congratulations_page_id', 0 ),
									'show_option_none'  => __( '— Select a page —', 'bu-qr-generator' ),
									'option_none_value' => '0',
								] );
								?>
								<p class="description">
									<?php esc_html_e( 'Users land here after a valid confirmation. The formatted QR code is appended as ?code=XXXX-XXXX-XXXX.', 'bu-qr-generator' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="buqr_invalid_page_id">
									<?php esc_html_e( 'Invalid Code Page', 'bu-qr-generator' ); ?>
								</label>
							</th>
							<td>
								<?php
								wp_dropdown_pages( [
									'name'              => 'buqr_invalid_page_id',
									'id'                => 'buqr_invalid_page_id',
									'selected'          => (int) get_option( 'buqr_invalid_page_id', 0 ),
									'show_option_none'  => __( '— Select a page —', 'bu-qr-generator' ),
									'option_none_value' => '0',
								] );
								?>
								<p class="description">
									<?php esc_html_e( 'Users are redirected here when a confirmation link carries an unrecognised hash.', 'bu-qr-generator' ); ?>
								</p>
							</td>
						</tr>

					</table>
				</div><!-- .inside -->
			</div><!-- .postbox -->

			<?php submit_button( __( 'Save Templates', 'bu-qr-generator' ), 'primary large' ); ?>
		</form>

	</div><!-- .wrap -->

	<style>
		#buqr-tpl-wrap { max-width: 960px; }

		.buqr-tab-panel--hidden { display: none; }

		.buqr-tpl-inside { padding: 8px 20px 20px; }

		.buqr-tpl-inside .form-table th {
			width: 160px;
			padding-top: 20px;
		}

		/* Keep the editor label vertically aligned with the top of the editor */
		.buqr-tpl-inside .form-table tr:last-child th {
			padding-top: 14px;
		}

		.buqr-vars-table {
			border-collapse: collapse;
			margin-top: 6px;
		}

		.buqr-vars-table td {
			padding: 3px 12px 3px 0;
			vertical-align: middle;
			font-size: 12px;
			color: #50575e;
		}

		.buqr-vars-table td:first-child code {
			font-size: 12px;
			background: #f0f0f1;
			padding: 2px 5px;
			border-radius: 3px;
			white-space: nowrap;
		}
	</style>
	<?php
}

// ---------------------------------------------------------------------------
// URL Generator — handler
// ---------------------------------------------------------------------------

add_action( 'admin_post_buqr_generate_urls', 'buqr_handle_generate_urls' );

function buqr_handle_generate_urls(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'Unauthorized.', 'bu-qr-generator' ) );
	}

	check_admin_referer( 'buqr_generate_urls_action', 'buqr_urls_nonce' );

	$url_template = sanitize_text_field( wp_unslash( $_POST['buqr_url_template'] ?? '' ) );
	$redirect_base = admin_url( 'edit.php?post_type=bu-qr-code&page=bu-qr-url-generator' );

	if ( empty( $url_template ) || strpos( $url_template, '{{code}}' ) === false ) {
		wp_safe_redirect( add_query_arg( 'buqr_url_error', 'missing_placeholder', $redirect_base ) );
		exit;
	}

	// Fetch all unclaimed QR codes.
	$posts = get_posts( [
		'post_type'      => 'bu-qr-code',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => [
			'relation' => 'OR',
			[
				'key'     => 'bu_claimed_at',
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => 'bu_claimed_at',
				'value'   => '',
				'compare' => '=',
			],
		],
	] );

	if ( empty( $posts ) ) {
		wp_safe_redirect( add_query_arg( 'buqr_url_error', 'no_codes', $redirect_base ) );
		exit;
	}

	$urls = [];

	foreach ( $posts as $post_id ) {
		$raw_code = get_post_meta( $post_id, 'bu_qr_code', true );

		if ( ! $raw_code ) {
			continue;
		}

		$urls[] = str_replace( '{{code}}', buqr_format_code( $raw_code ), $url_template );
	}

	// Persist results for the redirect — keyed per user, expires in 5 min.
	$transient_key = 'buqr_urls_' . get_current_user_id();
	set_transient( $transient_key, [
		'urls'     => $urls,
		'template' => $url_template,
	], 5 * MINUTE_IN_SECONDS );

	wp_safe_redirect( add_query_arg( 'buqr_urls_ready', '1', $redirect_base ) );
	exit;
}

// ---------------------------------------------------------------------------
// URL Generator — page renderer
// ---------------------------------------------------------------------------

function buqr_render_url_generator_page(): void {
	$transient_key = 'buqr_urls_' . get_current_user_id();
	$result        = get_transient( $transient_key );
	$ready         = isset( $_GET['buqr_urls_ready'] ) && '1' === $_GET['buqr_urls_ready'];
	$error         = isset( $_GET['buqr_url_error'] ) ? sanitize_key( $_GET['buqr_url_error'] ) : '';

	// Restore the template the user last used so it survives the redirect.
	$last_template = is_array( $result ) ? $result['template'] : '';
	$urls          = ( $ready && is_array( $result ) ) ? $result['urls'] : [];

	$error_messages = [
		'missing_placeholder' => __( 'Your URL must contain the <code>{{code}}</code> placeholder.', 'bu-qr-generator' ),
		'no_codes'            => __( 'There are no unused QR codes available.', 'bu-qr-generator' ),
	];

	$total_unused = count( get_posts( [
		'post_type'      => 'bu-qr-code',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => [
			'relation' => 'OR',
			[ 'key' => 'bu_claimed_at', 'compare' => 'NOT EXISTS' ],
			[ 'key' => 'bu_claimed_at', 'value'   => '', 'compare' => '=' ],
		],
	] ) );
	?>
	<div class="wrap" id="buqr-url-wrap">
		<h1><?php esc_html_e( 'Generate URLs', 'bu-qr-generator' ); ?></h1>

		<?php if ( $error && isset( $error_messages[ $error ] ) ) : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo wp_kses_post( $error_messages[ $error ] ); ?></p>
			</div>
		<?php endif; ?>

		<div class="postbox" style="max-width:860px;margin-top:16px;">
			<div class="postbox-header">
				<h2 class="hndle"><?php esc_html_e( 'URL Template', 'bu-qr-generator' ); ?></h2>
			</div>
			<div class="inside" style="padding:16px 20px 20px;">

				<p class="description" style="margin-bottom:14px;">
					<?php
					printf(
						/* translators: %s = {{code}} */
						esc_html__( 'Enter a URL containing %s. One URL will be generated per unused QR code (%d available).', 'bu-qr-generator' ),
						'<code>{{code}}</code>',
						$total_unused
					);
					?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="buqr_generate_urls">
					<?php wp_nonce_field( 'buqr_generate_urls_action', 'buqr_urls_nonce' ); ?>

					<input
						type="url"
						name="buqr_url_template"
						id="buqr_url_template"
						value="<?php echo esc_attr( $last_template ); ?>"
						placeholder="https://example.com/claim/?qr={{code}}"
						class="large-text"
						style="font-family:monospace;font-size:14px;"
						required
					>

					<div style="margin-top:14px;">
						<?php submit_button( __( 'Generate URLs', 'bu-qr-generator' ), 'primary large', 'submit', false ); ?>
					</div>
				</form>

			</div>
		</div>

		<?php if ( $ready && ! empty( $urls ) ) : ?>
			<div class="postbox" style="max-width:860px;margin-top:20px;">
				<div class="postbox-header" style="display:flex;align-items:center;justify-content:space-between;">
					<h2 class="hndle" style="margin:0;">
						<?php
						printf(
							/* translators: %d = number of URLs */
							esc_html__( '%d URL(s) generated', 'bu-qr-generator' ),
							count( $urls )
						);
						?>
					</h2>
					<button type="button" id="buqr-copy-btn" class="button" style="margin-right:12px;">
						<?php esc_html_e( 'Copy All', 'bu-qr-generator' ); ?>
					</button>
				</div>
				<div class="inside" style="padding:0;">
					<textarea
						id="buqr-url-output"
						readonly
						style="width:100%;height:420px;font-family:monospace;font-size:13px;border:none;border-top:1px solid #c3c4c7;resize:vertical;padding:12px;box-sizing:border-box;line-height:1.7;"
					><?php echo esc_textarea( implode( "\n", $urls ) ); ?></textarea>
				</div>
			</div>

			<script>
			document.getElementById( 'buqr-copy-btn' ).addEventListener( 'click', function () {
				const ta  = document.getElementById( 'buqr-url-output' );
				const btn = this;

				ta.select();
				ta.setSelectionRange( 0, ta.value.length );

				navigator.clipboard.writeText( ta.value ).then( function () {
					btn.textContent = '<?php echo esc_js( __( 'Copied!', 'bu-qr-generator' ) ); ?>';
					setTimeout( function () {
						btn.textContent = '<?php echo esc_js( __( 'Copy All', 'bu-qr-generator' ) ); ?>';
					}, 2000 );
				} );
			} );
			</script>
		<?php elseif ( $ready && empty( $urls ) ) : ?>
			<div class="notice notice-warning" style="max-width:860px;">
				<p><?php esc_html_e( 'No URLs were generated — all matching codes had empty QR values.', 'bu-qr-generator' ); ?></p>
			</div>
		<?php endif; ?>

	</div><!-- .wrap -->
	<?php
}
