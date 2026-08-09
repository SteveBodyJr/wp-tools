<?php
/**
 * Builds the system prompt from the admin's settings plus live site knowledge.
 *
 * Every sentence here is assembled from configuration, so the same plugin can
 * run a law firm's front desk, a shop's product helper or a tour operator's
 * trip planner without a single code change.
 *
 * @package BeaverAIChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BAC_Prompt
 */
class BAC_Prompt {

	/**
	 * Replace the placeholders admins can use in any user facing string.
	 *
	 * @param string $text Raw text.
	 * @param array  $s    Settings.
	 * @return string
	 */
	public static function tokens( $text, $s ) {
		$business = '' !== trim( (string) $s['business_name'] )
			? $s['business_name']
			: wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		return strtr(
			(string) $text,
			array(
				'{assistant}' => $s['assistant'],
				'{business}'  => $business,
				'{site}'      => $business,
				'{phone}'     => $s['phone'],
				'{email}'     => $s['contact_email'],
			)
		);
	}

	/**
	 * The full system prompt.
	 *
	 * @param array|null $s     Settings.
	 * @param string     $query What the visitor just asked, so the knowledge
	 *                          sent with this turn can be chosen to match it.
	 * @return string
	 */
	public static function build( $s = null, $query = '' ) {
		$s = null === $s ? BAC_Settings::get() : $s;

		// An override replaces everything except the live knowledge, which is
		// still appended so the assistant stays grounded in real content.
		if ( '' !== trim( (string) $s['prompt_override'] ) ) {
			$prompt = self::tokens( $s['prompt_override'], $s );
			$prompt = self::append_knowledge( $prompt, $s, $query );
			return self::finish( $prompt, $s );
		}

		$assistant = $s['assistant'];
		$business  = self::tokens( '{business}', $s );
		$tones     = BAC_Settings::tones();
		$tone      = isset( $tones[ $s['tone'] ] ) ? $tones[ $s['tone'] ]['prompt'] : $tones['warm']['prompt'];

		$intro = "You are {$assistant}, the AI assistant for {$business}";
		if ( '' !== trim( (string) $s['business_type'] ) ) {
			$intro .= ', ' . trim( (string) $s['business_type'] );
		}
		$intro .= ". You help visitors on the website: answering their questions, explaining what is on offer, and guiding them to the right next step.\n\n";

		$prompt = $intro;

		if ( '' !== trim( (string) $s['business_desc'] ) ) {
			$prompt .= "ABOUT US: " . trim( (string) $s['business_desc'] ) . "\n\n";
		}

		/* ---- Style ------------------------------------------------------ */
		$style = "STYLE: {$tone} Keep replies to " . trim( (string) $s['reply_length'] ) . '.';
		if ( ! empty( $s['no_emoji'] ) ) {
			$style .= ' Never use emojis.';
		}
		if ( ! empty( $s['no_dashes'] ) ) {
			$style .= ' Never use em dashes or en dashes; write with commas, full stops or a plain hyphen instead.';
		}
		if ( ! empty( $s['multilingual'] ) ) {
			$style .= " Reply in the same language the visitor writes in.";
		}
		$style .= " If the visitor tells you their name or what they are looking for, remember it and use it.\n\n";
		$prompt .= $style;

		/* ---- Formatting -------------------------------------------------- */
		$prompt .= "FORMATTING: When your answer contains three or more items of the same kind (options, steps, features, or a list of anything), write them as a bulleted list with one item per line starting with '- ', never as one long comma separated sentence. For a single fact or a one line answer, reply in plain prose with no list. Add a short friendly sentence before a list, and you may add one after it.\n\n";

		/* ---- Contact capture --------------------------------------------- */
		if ( ! empty( $s['ask_contact'] ) ) {
			$prompt .= "GETTING STARTED: Greet the visitor warmly and, in one friendly line, ask for their name and email so the team can follow up with details (mention a phone number is optional). As soon as they share either one, thank them and get straight to helping. If they would rather ask a question first, answer it briefly, then gently ask for their details. Never nag repeatedly.\n\n";
		}

		/* ---- Grounding ---------------------------------------------------- */
		$prompt .= "GROUNDING: Answer from the KNOWLEDGE section below, which is drawn from this website, together with the team notes. Only state facts you are confident about. Never invent prices, availability, dates or specifics that are not listed. Where you are unsure, say so plainly and offer to put the visitor in touch with the team.\n\n";

		if ( ! empty( $s['scope_lock'] ) ) {
			$prompt .= "SCOPE: Stay on topics related to {$business} and what it offers. If asked something unrelated, answer briefly if it is harmless, then steer back to how you can help them here.\n\n";
		}

		$prompt .= "NEVER: Do not make promises on behalf of the team, quote figures that are not in your knowledge, give legal, medical or financial advice, or ask for passwords or payment details. If a request needs a human, say so and point the visitor to the contact options.\n\n";

		$prompt  = self::append_knowledge( $prompt, $s, $query );
		$prompt .= self::contact_block( $s );

		if ( '' !== trim( (string) $s['prompt_extra'] ) ) {
			$prompt .= "\nADDITIONAL INSTRUCTIONS (treat as authoritative):\n" . trim( (string) $s['prompt_extra'] ) . "\n";
		}

		return self::finish( $prompt, $s );
	}

	/**
	 * Append the live site knowledge plus any extra notes the admin typed.
	 *
	 * @param string $prompt Prompt so far.
	 * @param array  $s      Settings.
	 * @param string $query  The visitor's latest message, when known.
	 * @return string
	 */
	private static function append_knowledge( $prompt, $s, $query = '' ) {
		$knowledge = BAC_Knowledge::get( $s, $query );

		if ( '' !== trim( (string) $knowledge ) ) {
			$prompt .= "KNOWLEDGE (from this website):\n" . $knowledge . "\n\n";
		}

		if ( '' !== trim( (string) $s['context'] ) ) {
			$prompt .= "NOTES FROM THE TEAM (treat as authoritative, they override the knowledge above):\n" . trim( (string) $s['context'] ) . "\n\n";
		}

		return $prompt;
	}

	/**
	 * Contact details and helpful links the assistant is allowed to share.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	private static function contact_block( $s ) {
		$lines = array();

		if ( '' !== trim( (string) $s['phone'] ) ) {
			$lines[] = 'Phone: ' . $s['phone'];
		}
		if ( '' !== trim( (string) $s['whatsapp'] ) ) {
			$lines[] = 'WhatsApp: ' . $s['whatsapp'];
		}
		if ( '' !== trim( (string) $s['contact_email'] ) ) {
			$lines[] = 'Email: ' . $s['contact_email'];
		}

		foreach ( self::links( $s ) as $link ) {
			$lines[] = $link['label'] . ': ' . $link['url'];
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "CONTACT DETAILS AND LINKS you may share (use the paths exactly as written):\n- " . implode( "\n- ", $lines ) . "\n";
	}

	/**
	 * Parse the "Label | /path" list into structured links.
	 *
	 * @param array $s Settings.
	 * @return array
	 */
	public static function links( $s ) {
		$out = array();

		foreach ( preg_split( '/[\r\n]+/', (string) $s['links'] ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$bits  = array_map( 'trim', explode( '|', $line, 2 ) );
			$label = $bits[0];
			$url   = isset( $bits[1] ) ? $bits[1] : '';

			if ( '' === $url ) {
				continue;
			}

			$out[] = array(
				'label' => sanitize_text_field( $label ),
				'url'   => $url,
			);
		}

		return $out;
	}

	/**
	 * Final guard rails that apply whatever the admin configured, then the
	 * filter that lets a child theme or another plugin adjust the prompt.
	 *
	 * @param string $prompt Prompt.
	 * @param array  $s      Settings.
	 * @return string
	 */
	private static function finish( $prompt, $s ) {
		// Some models will otherwise leak internal markup into visible replies
		// when reasoning is switched off. Stated generically on purpose.
		$prompt .= "\nDo not include internal or system XML tags in your response. Reply with the answer for the visitor only.\n";

		/**
		 * Filter the finished system prompt.
		 *
		 * @param string $prompt System prompt.
		 * @param array  $s      Settings.
		 */
		return (string) apply_filters( 'bac_system_prompt', $prompt, $s );
	}

	/**
	 * The prompt used to pull structured details out of a finished chat.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	public static function extraction_prompt( $s ) {
		$assistant = $s['assistant'];

		return 'You extract contact and enquiry details from a chat between an assistant named ' . $assistant . ' and a website visitor. '
			. 'Return ONLY a compact JSON object, with no prose and no code fences, using exactly these keys: '
			. 'name (the visitor\'s name, empty string if not given), email, phone, '
			. 'interest (a short phrase describing what they want), '
			. 'summary (two or three sentences on what the visitor is after, including any dates, quantities, budget or preferences they mentioned), '
			. 'unanswered (an array of the visitor\'s questions that the assistant could not answer from what it knew: where it said it was unsure, '
			. 'had no information, gave only a general answer, or referred the visitor to a human instead of answering. '
			. 'Rewrite each one as a short standalone question in the visitor\'s own words. Use an empty array if the assistant answered everything).';
	}
}
