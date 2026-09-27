<?php
/**
 * Newsletter drafts written by Claude (Anthropic's Messages API), from a short brief.
 *
 * The request goes straight to POST /v1/messages with wp_remote_post, like the Stripe and Zoom
 * calls in this plugin (no Composer dependencies, so the plugin stays an uploadable zip).
 * The answer is structured JSON (`output_config.format` json_schema): subject, preheader, body
 * (in the newsletter's small text format), button label. The upcoming classes and events are
 * given as context so the draft can mention real dates. Refusals come back as HTTP 200 with
 * stop_reason "refusal"; server-side fallbacks ("default") re-run a declined request.
 *
 * Settings: `ai_api_key` (or the OYS_ANTHROPIC_API_KEY constant), `ai_model` (default claude-opus-5).
 */

defined( 'ABSPATH' ) || exit;

class OYS_AI {

	const DEFAULT_MODEL = 'claude-opus-5';

	public static function api_url() {
		return defined( 'OYS_ANTHROPIC_API_URL' ) ? OYS_ANTHROPIC_API_URL : 'https://api.anthropic.com/v1/messages';
	}

	public static function key() {
		return defined( 'OYS_ANTHROPIC_API_KEY' ) ? OYS_ANTHROPIC_API_KEY : (string) OYS_Settings::get( 'ai_api_key' );
	}

	public static function ready() {
		return '' !== trim( self::key() );
	}

	/** Upcoming classes and events for the prompt (studio time, prices, places). */
	public static function schedule_context( $days = 21 ) {
		$lines = array();
		foreach ( OYS_Schedule::upcoming_public( $days ) as $s ) {
			$where   = oys_is_online( $s ) ? 'live online' : ( $s->location ?: 'studio' ) . ( oys_is_hybrid( $s ) ? ' + live online' : '' );
			$lines[] = sprintf( '- %s: %s, %s, %s', 'event' === $s->kind ? 'Event' : 'Class', oys_session_title( $s ), oys_date( $s->starts_at, 'D M j, g:i a' ), $where . ', ' . oys_price_label( $s ) );
		}
		return array_slice( $lines, 0, 60 );
	}

	/**
	 * Draft a newsletter. $brief = what it's about; $draft = an existing text to improve (optional).
	 * @return array|WP_Error [ subject, preheader, body, button_label ]
	 */
	public static function draft_newsletter( $brief, $draft = '' ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'oys_ai', __( 'Add an Anthropic API key in Studio → Newsletter → AI settings first.', 'olivia-studio' ) );
		}
		$brief = trim( wp_strip_all_tags( (string) $brief ) );
		if ( '' === $brief && '' === trim( (string) $draft ) ) {
			return new WP_Error( 'oys_ai', __( 'Write a few words about what the newsletter should say.', 'olivia-studio' ) );
		}
		$studio = OYS_Settings::get( 'email_from_name' );
		$system = "You write the email newsletter for {$studio}, a small yoga studio run by Olivia (Fort Myers, Florida): group classes, private sessions, events and live online classes. "
			. "Write as Olivia, in warm, simple, natural English, first person, short paragraphs. Friendly and calm, never salesy, no hype or exclamation-mark overload, at most one or two emoji. "
			. "Only mention dates, times, places and prices that are in the schedule below or in Olivia's brief; never invent them. "
			. "Format the body with this small syntax only: a blank line between paragraphs, '## ' at the start of a line for a short heading, '- ' for list items, **bold**, and [link text](https://address) for links. "
			. "Start the body with 'Hi {first_name},' on its own line ({first_name} is filled in for each reader). Keep it under 250 words. End with a short sign-off from Olivia. "
			. 'Subject: under 60 characters, specific, no clickbait. Preheader: one sentence under 90 characters that complements the subject.';
		$context = "Studio website: " . home_url( '/' ) . "\nBooking page: " . oys_page_url( 'book' )
			. ( oys_fb_group_url() ? "\nFacebook group: " . oys_fb_group_url() : '' )
			. "\n\nSchedule for the next three weeks:\n" . ( implode( "\n", self::schedule_context() ) ?: '(nothing scheduled yet)' );
		$ask = $draft
			? "Improve this newsletter draft following Olivia's note. Keep what she wrote unless the note says otherwise.\n\nOlivia's note: " . ( $brief ?: 'Polish it.' ) . "\n\nCurrent draft:\n" . $draft
			: "Olivia's brief for this newsletter:\n" . $brief;

		$body = array(
			'model'         => OYS_Settings::get( 'ai_model' ) ?: self::DEFAULT_MODEL,
			'max_tokens'    => 16000,
			'fallbacks'     => 'default',
			'system'        => $system,
			'output_config' => array(
				'effort' => 'medium',
				'format' => array(
					'type'   => 'json_schema',
					'schema' => array(
						'type'                 => 'object',
						'properties'           => array(
							'subject'      => array( 'type' => 'string' ),
							'preheader'    => array( 'type' => 'string' ),
							'body'         => array( 'type' => 'string' ),
							'button_label' => array( 'type' => 'string', 'description' => 'Short call to action for a button to the booking page, or an empty string for none.' ),
						),
						'required'             => array( 'subject', 'preheader', 'body', 'button_label' ),
						'additionalProperties' => false,
					),
				),
			),
			'messages'      => array( array( 'role' => 'user', 'content' => $context . "\n\n" . $ask ) ),
		);
		$res = wp_remote_post( self::api_url(), array(
			'timeout' => 180,
			'headers' => array(
				'content-type'      => 'application/json',
				'x-api-key'         => self::key(),
				'anthropic-version' => '2023-06-01',
				'anthropic-beta'    => 'server-side-fallback-2026-07-01',
			),
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'oys_ai', sprintf( __( 'Could not reach the AI service: %s', 'olivia-studio' ), $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $json ) ) {
			$msg = is_array( $json ) ? ( $json['error']['message'] ?? '' ) : '';
			oys_log( 'AI draft failed', array( 'status' => $code, 'error' => $msg ) );
			return new WP_Error( 'oys_ai', 401 === $code ? __( 'The AI service did not accept the API key. Check it in the AI settings.', 'olivia-studio' ) : sprintf( __( 'The AI service answered with an error (%1$d). %2$s', 'olivia-studio' ), $code, $msg ) );
		}
		if ( 'refusal' === ( $json['stop_reason'] ?? '' ) ) {
			return new WP_Error( 'oys_ai', __( 'The AI declined to write this one. Try rephrasing the brief.', 'olivia-studio' ) );
		}
		if ( 'max_tokens' === ( $json['stop_reason'] ?? '' ) ) {
			return new WP_Error( 'oys_ai', __( 'The draft was cut off. Try a shorter brief.', 'olivia-studio' ) );
		}
		$text = '';
		foreach ( (array) ( $json['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= $block['text'];
			}
		}
		$out = json_decode( $text, true );
		if ( ! is_array( $out ) || empty( $out['subject'] ) || empty( $out['body'] ) ) {
			return new WP_Error( 'oys_ai', __( 'The AI answer could not be read. Please try again.', 'olivia-studio' ) );
		}
		return array(
			'subject'      => sanitize_text_field( $out['subject'] ),
			'preheader'    => sanitize_text_field( $out['preheader'] ?? '' ),
			'body'         => sanitize_textarea_field( $out['body'] ),
			'button_label' => sanitize_text_field( $out['button_label'] ?? '' ),
		);
	}
}
