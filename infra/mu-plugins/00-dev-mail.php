<?php
/**
 * Plugin Name: Dev — route mail to Mailpit
 * Description: Testbed only. Sends all WordPress/WooCommerce mail to the Mailpit
 *              container instead of the internet, so order and approval emails
 *              can be read at http://100.127.55.45:8025 without any risk of a
 *              real customer receiving a test message.
 *
 * This file must NOT be deployed to valgomosdekoracijos.lt.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'phpmailer_init',
	static function ( $mailer ) {
		$mailer->isSMTP();
		$mailer->Host        = 'mailpit';
		$mailer->Port        = 1025;
		$mailer->SMTPAuth    = false;
		$mailer->SMTPAutoTLS = false;
	}
);
