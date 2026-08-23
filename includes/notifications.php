<?php
/**
 * E-mail notifications.
 *
 * A form has a list of notifications, each with its own recipients, subject,
 * body and conditional logic. Multiple conditional notifications are a paid
 * feature everywhere else; here they are a list.
 *
 * The default, when a form has no notifications configured, is one e-mail to the
 * site administrator containing every answer. A form that collects an enquiry
 * and tells nobody is the single most common way a forms plugin fails in
 * production, and it fails silently.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends every notification whose conditions are met.
 *
 * @since 0.1.0
 *
 * @param array $schema   The form schema.
 * @param array $values   The accepted values.
 * @param int   $entry_id The stored entry, or 0.
 * @param int   $form_id  The form.
 * @return void
 */
function alltfo_send_notifications( $schema, $values, $entry_id, $form_id ) {
	$notifications = $schema['notifications'];

	if ( ! $notifications ) {
		$notifications = array( alltfo_default_notification( $form_id ) );
	}

	$context = array(
		'schema'   => $schema,
		'values'   => $values,
		'form_id'  => $form_id,
		'entry_id' => $entry_id,
		'entry'    => $entry_id ? alltfo_prepare_entry( $entry_id ) : array(),
		'format'   => 'html',
	);

	foreach ( $notifications as $notification ) {
		if ( empty( $notification['enabled'] ) ) {
			continue;
		}

		if ( ! alltfo_logic_conditions_met( $notification['logic'], $values, $schema ) ) {
			continue;
		}

		alltfo_send_notification( $notification, $context );
	}
}

/**
 * The notification a form gets when nobody configured one.
 *
 * @since 0.1.0
 *
 * @param int $form_id The form.
 * @return array A normalised notification.
 */
function alltfo_default_notification( $form_id ) {
	$notification = array(
		'id'          => 'default',
		'enabled'     => true,
		'name'        => __( 'Admin notification', 'allterrain-forms' ),
		'to'          => '{admin_email}',
		'cc'          => '',
		'bcc'         => '',
		// Replies go to whoever filled the form in, when the form asked for an
		// address. Without this, hitting Reply in a mail client answers the
		// website rather than the person.
		'replyTo'     => '{field:email}',
		'fromName'    => '',
		'fromEmail'   => '',
		/* translators: %s: the form's title. */
		'subject'     => sprintf( __( 'New submission: %s', 'allterrain-forms' ), get_the_title( $form_id ) ),
		'message'     => '{all_fields}',
		'attachFiles' => false,
		'logic'       => array(
			'enabled' => false,
			'action'  => 'show',
			'match'   => 'all',
			'rules'   => array(),
		),
	);

	/**
	 * Filters the notification used when a form configures none.
	 *
	 * @since 0.1.0
	 *
	 * @param array $notification The default notification.
	 * @param int   $form_id      The form.
	 */
	return apply_filters( 'alltfo_default_notification', $notification, $form_id );
}

/**
 * Sends one notification.
 *
 * @since 0.1.0
 *
 * @param array $notification A normalised notification.
 * @param array $context      The merge-tag context.
 * @return bool Whether `wp_mail()` accepted it.
 */
function alltfo_send_notification( $notification, $context ) {
	$to = alltfo_resolve_recipients( $notification['to'], $context );

	if ( ! $to ) {
		return false;
	}

	$subject = alltfo_replace_merge_tags( $notification['subject'], array_merge( $context, array( 'format' => 'text' ) ) );
	$subject = '' !== trim( $subject ) ? $subject : __( 'New form submission', 'allterrain-forms' );

	$body = alltfo_replace_merge_tags(
		'' !== trim( $notification['message'] ) ? $notification['message'] : '{all_fields}',
		$context
	);

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	foreach ( array(
		'cc'  => 'Cc',
		'bcc' => 'Bcc',
	) as $key => $header ) {
		$addresses = alltfo_resolve_recipients( $notification[ $key ], $context );

		foreach ( $addresses as $address ) {
			$headers[] = $header . ': ' . $address;
		}
	}

	$reply_to = alltfo_resolve_recipients( $notification['replyTo'], $context );

	if ( $reply_to ) {
		$headers[] = 'Reply-To: ' . $reply_to[0];
	}

	$from_email = alltfo_replace_merge_tags( $notification['fromEmail'], array_merge( $context, array( 'format' => 'text' ) ) );
	$from_name  = alltfo_replace_merge_tags( $notification['fromName'], array_merge( $context, array( 'format' => 'text' ) ) );

	// A From address on a domain the site does not own is the fastest way into a
	// spam folder, so a visitor's own address is never used as the sender --
	// that is what Reply-To is for. Only an explicitly configured From is
	// honoured, and only when it is a real address.
	if ( '' !== $from_email && is_email( $from_email ) ) {
		$headers[] = '' !== $from_name
			? sprintf( 'From: %s <%s>', $from_name, $from_email )
			: 'From: ' . $from_email;
	}

	$attachments = array();

	if ( ! empty( $notification['attachFiles'] ) ) {
		$attachments = alltfo_notification_attachments( $context );
	}

	$mail = array(
		'to'          => $to,
		'subject'     => $subject,
		'message'     => alltfo_wrap_email_body( $body, $context ),
		'headers'     => $headers,
		'attachments' => $attachments,
	);

	/**
	 * Filters a notification e-mail just before it is sent.
	 *
	 * Returning an empty `to` cancels the send.
	 *
	 * @since 0.1.0
	 *
	 * @param array $mail         { to, subject, message, headers, attachments }.
	 * @param array $notification The notification.
	 * @param array $context      The merge-tag context.
	 */
	$mail = apply_filters( 'alltfo_notification_email', $mail, $notification, $context );

	if ( empty( $mail['to'] ) ) {
		return false;
	}

	$sent = wp_mail( $mail['to'], $mail['subject'], $mail['message'], $mail['headers'], $mail['attachments'] );

	/**
	 * Fires after a notification has been handed to `wp_mail()`.
	 *
	 * @since 0.1.0
	 *
	 * @param bool  $sent         Whether `wp_mail()` accepted it.
	 * @param array $mail         The message.
	 * @param array $notification The notification.
	 * @param array $context      The merge-tag context.
	 */
	do_action( 'alltfo_notification_sent', $sent, $mail, $notification, $context );

	return $sent;
}

/**
 * Turns a recipient string into a list of valid addresses.
 *
 * Merge tags are resolved first, so `{field:f2}` becomes whatever the visitor
 * typed -- and then validated, because what the visitor typed is not necessarily
 * an e-mail address even after the field said it was.
 *
 * @since 0.1.0
 *
 * @param string $recipients Comma-separated addresses, possibly with merge tags.
 * @param array  $context    The merge-tag context.
 * @return string[] Valid addresses.
 */
function alltfo_resolve_recipients( $recipients, $context ) {
	$resolved = alltfo_replace_merge_tags( (string) $recipients, array_merge( $context, array( 'format' => 'text' ) ) );
	$parts    = preg_split( '/[,;]+/', $resolved );
	$valid    = array();

	foreach ( (array) $parts as $part ) {
		$address = trim( $part );

		// A display name is allowed through as-is once its address checks out,
		// because `wp_mail()` understands `Name <a@b.c>` and stripping it would
		// make every notification arrive from nobody in particular.
		if ( preg_match( '/^(.*)<([^>]+)>$/', $address, $matches ) ) {
			if ( is_email( trim( $matches[2] ) ) ) {
				$valid[] = $address;
			}

			continue;
		}

		if ( is_email( $address ) ) {
			$valid[] = $address;
		}
	}

	return array_values( array_unique( $valid ) );
}

/**
 * The files uploaded with a submission, as paths for `wp_mail()`.
 *
 * @since 0.1.0
 *
 * @param array $context The merge-tag context.
 * @return string[] Absolute paths.
 */
function alltfo_notification_attachments( $context ) {
	$paths = array();

	foreach ( alltfo_input_fields( $context['schema'] ) as $field ) {
		if ( 'file' !== $field['type'] ) {
			continue;
		}

		$ids = isset( $context['values'][ $field['id'] ] ) ? (array) $context['values'][ $field['id'] ] : array();

		foreach ( $ids as $attachment_id ) {
			$path = get_attached_file( absint( $attachment_id ) );

			if ( $path && file_exists( $path ) ) {
				$paths[] = $path;
			}
		}
	}

	return $paths;
}

/**
 * Wraps a notification body in a minimal HTML document.
 *
 * Inline styles and a table layout, because that is what mail clients render
 * predictably -- Outlook still does not support a `<style>` block reliably, and
 * a stylesheet link reaches nothing at all.
 *
 * @since 0.1.0
 *
 * @param string $body    The message body, already merged.
 * @param array  $context The merge-tag context.
 * @return string
 */
function alltfo_wrap_email_body( $body, $context ) {
	$title = $context['form_id'] ? get_the_title( $context['form_id'] ) : get_bloginfo( 'name' );

	$html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
		. '<body style="margin:0;padding:24px;background:#f6f7f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#1e1e1e;line-height:1.5">'
		. '<table role="presentation" cellpadding="0" cellspacing="0" style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden">'
		. '<tr><td style="padding:24px 28px;border-bottom:1px solid #e0e0e0">'
		. '<h1 style="margin:0;font-size:18px;font-weight:600">' . esc_html( $title ) . '</h1>'
		. '</td></tr><tr><td style="padding:24px 28px">'
		. $body
		. '</td></tr><tr><td style="padding:16px 28px;background:#fafafa;font-size:12px;color:#646970">'
		. esc_html(
			sprintf(
				/* translators: %s: the site's name. */
				__( 'Sent from %s', 'allterrain-forms' ),
				wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES )
			)
		)
		. '</td></tr></table></body></html>';

	/**
	 * Filters the complete HTML of a notification e-mail.
	 *
	 * @since 0.1.0
	 *
	 * @param string $html    The document.
	 * @param string $body    The merged body it wraps.
	 * @param array  $context The merge-tag context.
	 */
	return (string) apply_filters( 'alltfo_email_html', $html, $body, $context );
}
