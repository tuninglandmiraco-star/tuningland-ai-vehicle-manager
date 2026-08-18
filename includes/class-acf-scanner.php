<?php
/**
 * ACF Scanner
 *
 * Dynamically discovers ACF Field Groups and Fields
 * associated with the selected Vehicle CPT.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_ACF_Scanner {

	private static $instance = null;

	private $scan_cache = array();

	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
	}

	public function is_acf_available() {

		return function_exists( 'acf_get_field_groups' )
			&& function_exists( 'acf_get_fields' );
	}

	public function scan( $post_type, $refresh = false ) {

		$post_type = sanitize_key( $post_type );

		if ( empty( $post_type ) ) {
			return array(
				'success' => false,
				'error'   => 'No post type was provided.',
				'groups'  => array(),
			);
		}

		if ( ! $this->is_acf_available() ) {
			return array(
				'success' => false,
				'error'   => 'Advanced Custom Fields (ACF) is not available.',
				'groups'  => array(),
			);
		}

		$cache_key = $post_type;

		if (
			! $refresh &&
			isset( $this->scan_cache[ $cache_key ] )
		) {
			return $this->scan_cache[ $cache_key ];
		}

		$field_groups = acf_get_field_groups(
			array(
				'post_type' => $post_type,
			)
		);

		$result = array(
			'success'      => true,
			'post_type'    => $post_type,
			'total_groups' => 0,
			'total_fields' => 0,
			'groups'       => array(),
		);

		if ( empty( $field_groups ) ) {

			$this->scan_cache[ $cache_key ] = $result;

			return $result;
		}

		foreach ( $field_groups as $group ) {

			if (
				empty( $group['key'] )
			) {
				continue;
			}

			$group_key = $group['key'];

			$fields = acf_get_fields(
				$group_key
			);

			$group_data = array(
				'key'          => $group_key,
				'id'           => isset( $group['ID'] )
					? (int) $group['ID']
					: 0,
				'title'        => isset( $group['title'] )
					? $group['title']
					: '',
				'active'       => isset( $group['active'] )
					? (bool) $group['active']
					: true,
				'menu_order'   => isset( $group['menu_order'] )
					? (int) $group['menu_order']
					: 0,
				'fields'       => array(),
				'total_fields' => 0,
			);

			if (
				! empty( $fields ) &&
				is_array( $fields )
			) {

				foreach ( $fields as $field ) {

					$field_data = $this->normalize_field(
						$field
					);

					$group_data['fields'][] = $field_data;

					$group_data['total_fields']++;

					$result['total_fields']++;
				}
			}

			$result['groups'][] = $group_data;

			$result['total_groups']++;
		}

		$this->scan_cache[ $cache_key ] = $result;

		return $result;
	}

	private function normalize_field( $field ) {

		$data = array(
			'key' => isset( $field['key'] )
				? sanitize_text_field(
					$field['key']
				)
				: '',

			'name' => isset( $field['name'] )
				? sanitize_key(
					$field['name']
				)
				: '',

			'label' => isset( $field['label'] )
				? sanitize_text_field(
					$field['label']
				)
				: '',

			'type' => isset( $field['type'] )
				? sanitize_key(
					$field['type']
				)
				: '',

			'instructions' => isset(
				$field['instructions']
			)
				? wp_strip_all_tags(
					$field['instructions']
				)
				: '',

			'required' => ! empty(
				$field['required']
			),

			'default_value' => isset(
				$field['default_value']
			)
				? $field['default_value']
				: null,

			'placeholder' => isset(
				$field['placeholder']
			)
				? sanitize_text_field(
					$field['placeholder']
				)
				: '',

			'parent' => isset(
				$field['parent']
			)
				? sanitize_text_field(
					$field['parent']
				)
				: '',

			'wrapper' => isset(
				$field['wrapper']
			)
				? $field['wrapper']
				: array(),

			'choices' => isset(
				$field['choices']
			)
				? $field['choices']
				: array(),

			'min' => isset(
				$field['min']
			)
				? $field['min']
				: null,

			'max' => isset(
				$field['max']
			)
				? $field['max']
				: null,

			'step' => isset(
				$field['step']
			)
				? $field['step']
				: null,

			'maxlength' => isset(
				$field['maxlength']
			)
				? $field['maxlength']
				: null,

			'conditional_logic' => isset(
				$field['conditional_logic']
			)
				? $field['conditional_logic']
				: array(),

			'sub_fields' => array(),
		);

		if (
			! empty( $field['sub_fields'] ) &&
			is_array( $field['sub_fields'] )
		) {

			foreach (
				$field['sub_fields']
				as $sub_field
			) {

				$data['sub_fields'][] =
					$this->normalize_field(
						$sub_field
					);
			}
		}

		return $data;
	}

	public function get_flat_fields( $post_type ) {

		$scan = $this->scan(
			$post_type
		);

		if (
			empty( $scan['success'] ) ||
			empty( $scan['groups'] )
		) {
			return array();
		}

		$fields = array();

		foreach ( $scan['groups'] as $group ) {

			if ( empty( $group['fields'] ) ) {
				continue;
			}

			foreach ( $group['fields'] as $field ) {

				$this->flatten_field(
					$field,
					$fields,
					$group['key'],
					$group['title']
				);
			}
		}

		return $fields;
	}

	private function flatten_field(
		$field,
		&$result,
		$group_key = '',
		$group_title = '',
		$parent_name = ''
	) {

		$field_name = isset(
			$field['name']
		)
			? $field['name']
			: '';

		$result[] = array(
			'group_key' => $group_key,

			'group_title' => $group_title,

			'parent_name' => $parent_name,

			'key' => isset(
				$field['key']
			)
				? $field['key']
				: '',

			'name' => $field_name,

			'label' => isset(
				$field['label']
			)
				? $field['label']
				: '',

			'type' => isset(
				$field['type']
			)
				? $field['type']
				: '',

			'required' => ! empty(
				$field['required']
			),
		);

		if (
			! empty( $field['sub_fields'] ) &&
			is_array( $field['sub_fields'] )
		) {

			foreach (
				$field['sub_fields']
				as $sub_field
			) {

				$this->flatten_field(
					$sub_field,
					$result,
					$group_key,
					$group_title,
					$field_name
				);
			}
		}
	}

	public function clear_cache( $post_type = null ) {

		if ( null === $post_type ) {

			$this->scan_cache = array();

			return;
		}

		$post_type = sanitize_key(
			$post_type
		);

		if (
			isset(
				$this->scan_cache[ $post_type ]
			)
		) {

			unset(
				$this->scan_cache[ $post_type ]
			);
		}
	}

	public function get_summary( $post_type ) {

		$scan = $this->scan(
			$post_type
		);

		return array(
			'success' => ! empty(
				$scan['success']
			),

			'post_type' => $post_type,

			'total_groups' => isset(
				$scan['total_groups']
			)
				? (int) $scan['total_groups']
				: 0,

			'total_fields' => isset(
				$scan['total_fields']
			)
				? (int) $scan['total_fields']
				: 0,
		);
	}

}
