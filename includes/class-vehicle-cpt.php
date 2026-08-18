<?php
/**
 * Vehicle CPT Detector
 *
 * Detects available WordPress Custom Post Types
 * and provides a clean API for the AI Vehicle Manager.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Vehicle_CPT {

	private static $instance = null;

	private $cpt_cache = null;

	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {

		add_action(
			'init',
			array( $this, 'prepare_cpt_cache' ),
			20
		);
	}

	public function prepare_cpt_cache() {

		$this->get_all_cpts( true );
	}

	public function get_all_cpts( $refresh = false ) {

		if ( ! $refresh && null !== $this->cpt_cache ) {
			return $this->cpt_cache;
		}

		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);

		$result = array();

		if ( empty( $post_types ) ) {

			$this->cpt_cache = array();

			return $this->cpt_cache;
		}

		foreach ( $post_types as $post_type ) {

			if (
				in_array(
					$post_type->name,
					array(
						'post',
						'page',
						'attachment',
					),
					true
				)
			) {
				continue;
			}

			$result[ $post_type->name ] = array(
				'name'         => $post_type->name,
				'label'        => $post_type->label,
				'singular'     => $post_type->labels->singular_name,
				'description'  => $post_type->description,
				'public'       => (bool) $post_type->public,
				'show_ui'      => (bool) $post_type->show_ui,
				'show_in_rest' => (bool) $post_type->show_in_rest,
				'has_archive'  => (bool) $post_type->has_archive,
				'menu_icon'    => $post_type->menu_icon,
			);
		}

		$this->cpt_cache = $result;

		return $this->cpt_cache;
	}

	public function get_cpt( $post_type ) {

		$post_type = sanitize_key( $post_type );

		if ( empty( $post_type ) ) {
			return null;
		}

		$cpts = $this->get_all_cpts();

		if ( isset( $cpts[ $post_type ] ) ) {
			return $cpts[ $post_type ];
		}

		return null;
	}

	public function exists( $post_type ) {

		$post_type = sanitize_key( $post_type );

		if ( empty( $post_type ) ) {
			return false;
		}

		return post_type_exists( $post_type );
	}

	public function get_choices() {

		$cpts = $this->get_all_cpts();

		$choices = array();

		foreach ( $cpts as $slug => $data ) {

			$choices[ $slug ] = sprintf(
				'%s (%s)',
				$data['label'],
				$slug
			);
		}

		return $choices;
	}

	public function detect_vehicle_candidates() {

		$cpts = $this->get_all_cpts();

		$candidates = array();

		$keywords = array(
			'vehicle',
			'vehicles',
			'car',
			'cars',
			'auto',
			'automobile',
			'automobiles',
			'خودرو',
			'ماشین',
			'اتومبیل',
		);

		foreach ( $cpts as $slug => $data ) {

			$score = 0;

			$haystack = strtolower(
				$slug . ' ' .
				$data['name'] . ' ' .
				$data['label'] . ' ' .
				$data['singular']
			);

			foreach ( $keywords as $keyword ) {

				if (
					false !== strpos(
						$haystack,
						strtolower( $keyword )
					)
				) {
					$score += 10;
				}
			}

			if ( $score > 0 ) {

				$candidates[] = array(
					'post_type' => $slug,
					'label'     => $data['label'],
					'score'     => $score,
				);
			}
		}

		usort(
			$candidates,
			function ( $a, $b ) {

				if ( $a['score'] === $b['score'] ) {
					return 0;
				}

				return ( $a['score'] > $b['score'] )
					? -1
					: 1;
			}
		);

		return $candidates;
	}

	public function get_selected_vehicle_cpt() {

		$value = get_option(
			'tl_ai_vm_vehicle_cpt',
			''
		);

		return sanitize_key( $value );
	}

	public function set_selected_vehicle_cpt( $post_type ) {

		$post_type = sanitize_key( $post_type );

		if (
			empty( $post_type ) ||
			! $this->exists( $post_type )
		) {
			return false;
		}

		return update_option(
			'tl_ai_vm_vehicle_cpt',
			$post_type,
			false
		);
	}

	public function clear_cache() {

		$this->cpt_cache = null;
	}

}