<?php
/**
 * Reading WC Fields Factory's configuration, so the plugin never prices anything.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * A read-only window onto WC Fields Factory.
 *
 * The shop prices every product with Fields Factory surcharges applied to the
 * line item — base + sheet type + AI (D-036). We add none of that: the wizard's
 * add-to-cart form posts `wccpf_<key>=<value>` like any other field, and WCFF's
 * own persister and negotiator do the pricing, the cart display, the order meta
 * and the email.
 *
 * The only thing we need from WCFF is **which key to post**, and that cannot be
 * hardcoded: keys are randomly generated when the admin adds a field
 * (`wccpf_qkKQtVWBjYfI` on the testbed). So they are resolved at runtime, by
 * the label the admin typed.
 *
 * Nothing here writes. If this class ever needs a setter, the design has gone
 * wrong — see D-036 on why hand-building WCFF's cart-item array is the thing
 * not to do.
 */
class FieldsFactory {

	/**
	 * The `wccpf` post holding this shop's AI fields.
	 *
	 * Slug rather than ID, so the testbed and production can differ.
	 */
	public const GROUP_SLUG = 'ai_image';

	/**
	 * Postmeta keys inside a group that configure the group rather than
	 * describe a field. Anything else prefixed `wccpf_` is a field.
	 */
	private const NON_FIELD_META = array(
		'wccpf_condition_rules',
		'wccpf_layout_meta',
		'wccpf_target_stock_status',
		'wccpf_use_custom_layout',
		'wccpf_field_location_on_product',
		'wccpf_field_location_on_archive',
		'wccpf_is_this_group_clonable',
		'wccpf_show_group_title',
		'wccpf_fields_label_alignement',
		'wccpf_is_this_group_for_authorized_only',
		'wccpf_wcff_group_preference_target_roles',
	);

	/**
	 * Is Fields Factory present and loaded?
	 *
	 * Checked rather than assumed because the plugin has to degrade to a
	 * plain base price rather than fatal if the shop disables WCFF.
	 */
	public function is_active(): bool {
		return function_exists( 'wcff' );
	}

	/**
	 * Every field in a group, keyed by the WCFF field key.
	 *
	 * @param string $group_slug Group post slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields( string $group_slug = self::GROUP_SLUG ): array {
		global $wpdb;

		$group_id = $this->group_id( $group_slug );

		if ( 0 === $group_id ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta}
				 WHERE post_id = %d AND meta_key LIKE %s",
				$group_id,
				$wpdb->esc_like( 'wccpf_' ) . '%'
			)
		);

		$fields = array();

		foreach ( (array) $rows as $row ) {
			if ( in_array( $row->meta_key, self::NON_FIELD_META, true ) ) {
				continue;
			}

			$field = json_decode( (string) $row->meta_value, true );

			// A field entry carries its own key and a label; group config does not.
			if ( ! is_array( $field ) || ! isset( $field['key'], $field['label'] ) ) {
				continue;
			}

			$fields[ (string) $field['key'] ] = $field;
		}

		return $fields;
	}

	/**
	 * The key to post for a field, found by the label the admin typed.
	 *
	 * Comparison is trimmed and case-insensitive because the label is free
	 * text in an admin screen, and the testbed already carries a leading
	 * space in one choice label.
	 *
	 * @param string $label      Field label, e.g. „Lakšto tipas".
	 * @param string $group_slug Group post slug.
	 */
	public function field_key( string $label, string $group_slug = self::GROUP_SLUG ): ?string {
		$wanted = $this->normalise( $label );

		foreach ( $this->fields( $group_slug ) as $key => $field ) {
			if ( $this->normalise( (string) $field['label'] ) === $wanted ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * What a field's price rules add, for a given chosen value.
	 *
	 * Read-only, and *not* used to charge anything — WCFF does that. This
	 * exists so the wizard can show the customer a running price before the
	 * cart exists, and so a check can assert that what we display and what
	 * WCFF charges are the same number.
	 *
	 * @param string $label      Field label.
	 * @param string $value      The value that would be posted.
	 * @param string $group_slug Group post slug.
	 */
	public function surcharge( string $label, string $value, string $group_slug = self::GROUP_SLUG ): float {
		$key = $this->field_key( $label, $group_slug );

		if ( null === $key ) {
			return 0.0;
		}

		$fields = $this->fields( $group_slug );
		$rules  = $fields[ $key ]['pricing_rules'] ?? array();
		$total  = 0.0;

		foreach ( (array) $rules as $rule ) {
			if ( ! isset( $rule['expected_value'], $rule['amount'] ) ) {
				continue;
			}

			// Only the equal/cost shape the shop actually uses is honoured.
			// Anything else returns 0 rather than guessing, so a mismatch
			// shows up as a visibly wrong price rather than a plausible one.
			if ( ( $rule['logic'] ?? 'equal' ) !== 'equal' || ( $rule['tprice'] ?? '' ) !== 'cost' ) {
				continue;
			}

			if ( $this->normalise( (string) $rule['expected_value'] ) !== $this->normalise( $value ) ) {
				continue;
			}

			$amount = (float) $rule['amount'];
			$total += ( 'sub' === ( $rule['ptype'] ?? 'add' ) ) ? -$amount : $amount;
		}

		return $total;
	}

	/**
	 * The group post id, or 0.
	 *
	 * @param string $slug Group post slug.
	 */
	public function group_id( string $slug = self::GROUP_SLUG ): int {
		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'wccpf' AND post_name = %s AND post_status = 'publish'
				 LIMIT 1",
				$slug
			)
		);

		return null === $id ? 0 : (int) $id;
	}

	/**
	 * The product ids this group is bound to.
	 *
	 * WCFF stores `-1` for "not bound", which is what a freshly created
	 * group carries and what makes every surcharge silently absent.
	 *
	 * @param string $slug Group post slug.
	 *
	 * @return int[]
	 */
	public function bound_product_ids( string $slug = self::GROUP_SLUG ): array {
		$group_id = $this->group_id( $slug );

		if ( 0 === $group_id ) {
			return array();
		}

		$rules = json_decode( (string) get_post_meta( $group_id, 'wccpf_condition_rules', true ), true );
		$ids   = array();

		foreach ( (array) $rules as $group ) {
			foreach ( (array) $group as $rule ) {
				if ( ( $rule['context'] ?? '' ) !== 'product' ) {
					continue;
				}

				$id = (int) ( $rule['endpoint'] ?? -1 );

				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Fold case and whitespace for label comparison.
	 *
	 * @param string $value Raw label or value.
	 */
	private function normalise( string $value ): string {
		return function_exists( 'mb_strtolower' )
			? mb_strtolower( trim( $value ), 'UTF-8' )
			: strtolower( trim( $value ) );
	}
}
