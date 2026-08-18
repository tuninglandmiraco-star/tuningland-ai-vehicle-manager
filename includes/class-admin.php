<?php
/**
 * Admin Panel
 *
 * Administration interface for the Tuningland AI Vehicle Manager.
 *
 * Current responsibilities:
 * - Add admin menu
 * - Select Vehicle CPT
 * - Scan ACF structure
 * - Display discovered Field Groups and Fields
 * - Display Field Schema status
 * - Display AI Field Analyzer status
 * - Display Web Research Engine status
 * - Display vehicle research statistics
 * - Display system information
 *
 * Architecture:
 *
 * Vehicle CPT
 *      ↓
 * ACF Scanner
 *      ↓
 * Field Schema
 *      ↓
 * AI Field Analyzer
 *      ↓
 * Web Research Engine
 *      ↓
 * Research Result
 *      ↓
 * Validation / Confidence
 *      ↓
 * Review
 *      ↓
 * ACF Writer
 *
 * IMPORTANT:
 * This admin class does NOT directly write AI results
 * into ACF fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_Admin|null
	 */
	private static $instance = null;

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'tl-ai-vm';

	/**
	 * Get singleton instance.
	 *
	 * @return TL_AI_VM_Admin
	 */
	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {

		add_action(
			'admin_menu',
			array( $this, 'register_menu' )
		);

		add_action(
			'admin_enqueue_scripts',
			array( $this, 'enqueue_assets' )
		);

		add_action(
			'admin_post_tl_ai_vm_save_cpt',
			array( $this, 'save_vehicle_cpt' )
		);

		add_action(
			'admin_post_tl_ai_vm_scan_acf',
			array( $this, 'scan_acf' )
		);

		add_action(
			'admin_post_tl_ai_vm_start_vehicle_research',
			array( $this, 'start_vehicle_research' )
		);

		add_action(
			'add_meta_boxes',
			array( $this, 'register_vehicle_meta_box' )
		);

		add_action(
			'admin_post_tl_ai_vm_approve_research',
			array( $this, 'approve_research' )
		);

		add_action(
			'admin_post_tl_ai_vm_reject_research',
			array( $this, 'reject_research' )
		);

		add_action( 'wp_ajax_tl_ai_vm_start_research_async', array( $this, 'ajax_start_research_async' ) );
		add_action( 'wp_ajax_tl_ai_vm_research_tick', array( $this, 'ajax_research_tick' ) );
		add_action( 'wp_ajax_tl_ai_vm_research_status', array( $this, 'ajax_research_status' ) );
		add_action( 'wp_ajax_tl_ai_vm_cancel_research', array( $this, 'ajax_cancel_research' ) );
		add_action( 'wp_ajax_tl_ai_vm_research_results', array( $this, 'ajax_research_results' ) );
		add_action( 'wp_ajax_tl_ai_vm_approve_result', array( $this, 'ajax_approve_result' ) );
		add_action( 'wp_ajax_tl_ai_vm_approve_high_confidence', array( $this, 'ajax_approve_high_confidence' ) );
		add_action( 'wp_ajax_tl_ai_vm_correct_result', array( $this, 'ajax_correct_result' ) );
		add_action( 'wp_ajax_tl_ai_vm_reject_result', array( $this, 'ajax_reject_result' ) );
		add_action( 'admin_post_tl_ai_vm_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_tl_ai_vm_test_openai', array( $this, 'test_openai' ) );
		add_action( 'admin_post_tl_ai_vm_test_ai_provider', array( $this, 'test_ai_provider' ) );
		add_action( 'wp_ajax_tl_ai_vm_test_ai_ajax', array( $this, 'test_ai_provider_ajax' ) );
		add_action( 'admin_post_tl_ai_vm_save_sources', array( $this, 'save_sources' ) );
		add_action( 'admin_post_tl_ai_vm_save_field_intelligence', array( $this, 'save_field_intelligence' ) );
	}

	/**
	 * Register admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {

		add_menu_page(
			'Tuningland AI Vehicle Manager',
			'AI Vehicle Manager',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-car',
			58
		);

		add_submenu_page( self::PAGE_SLUG, 'AI Settings', 'AI Settings', 'manage_options', self::PAGE_SLUG . '-settings', array( $this, 'render_settings' ) );
		add_submenu_page( self::PAGE_SLUG, 'Source Data', 'Source Data', 'manage_options', self::PAGE_SLUG . '-sources', array( $this, 'render_sources' ) );
		add_submenu_page( self::PAGE_SLUG, 'Field Intelligence', 'Field Intelligence', 'manage_options', self::PAGE_SLUG . '-fields', array( $this, 'render_field_intelligence' ) );
	}

	/**
	 * Load admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 */
	public function enqueue_assets( $hook ) {

		$allowed = array( 'toplevel_page_' . self::PAGE_SLUG, 'ai-vehicle-manager_page_' . self::PAGE_SLUG . '-settings', 'ai-vehicle-manager_page_' . self::PAGE_SLUG . '-sources', 'ai-vehicle-manager_page_' . self::PAGE_SLUG . '-fields' );
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			$selected = sanitize_key( get_option( 'tl_ai_vm_vehicle_cpt', '' ) );
			if ( $selected && isset( $_GET['post'] ) ) {
				$post = get_post( absint( $_GET['post'] ) );
				if ( $post && $post->post_type === $selected ) { $allowed[] = $hook; }
			}
		}
		if ( ! in_array( $hook, $allowed, true ) ) {
			return;
		}

		wp_enqueue_style(
			'tl-ai-vm-admin',
			TL_AI_VM_URL . 'assets/admin.css',
			array(),
			file_exists( TL_AI_VM_PATH . 'assets/admin.css' ) ? filemtime( TL_AI_VM_PATH . 'assets/admin.css' ) : TL_AI_VM_VERSION
		);

		$screen = get_current_screen();
		$selected_cpt = sanitize_key( get_option( 'tl_ai_vm_vehicle_cpt', '' ) );
		$is_vehicle_edit = ( 'post.php' === $hook || 'post-new.php' === $hook ) && $screen && $selected_cpt && $screen->post_type === $selected_cpt;
		$is_plugin_screen = $screen && ( strpos( (string) $screen->id, self::PAGE_SLUG ) !== false || strpos( (string) $screen->base, 'tl-ai-vm' ) !== false );
		$is_dashboard = 'toplevel_page_' . self::PAGE_SLUG === $hook || $is_plugin_screen;
		if ( $is_vehicle_edit || $is_dashboard ) {
			$asset = TL_AI_VM_PATH . 'assets/research.js';
			$ver = file_exists( $asset ) ? (string) filemtime( $asset ) : TL_AI_VM_VERSION;
			wp_enqueue_script( 'tl-ai-vm-research', TL_AI_VM_URL . 'assets/research.js', array( 'jquery' ), $ver, true );
			wp_localize_script( 'tl-ai-vm-research', 'TL_AI_VM_RESEARCH', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'tl_ai_vm_async_research' ),
				'pollMs' => 1200,
				'autoThreshold' => (int) get_option( 'tl_ai_vm_auto_threshold', 90 ),
			) );
		}
	}

	/**
	 * Render dashboard.
	 *
	 * @return void
	 */
	public function render_dashboard() {

		if ( ! current_user_can( 'manage_options' ) ) {

			wp_die(
				esc_html__(
					'You do not have permission to access this page.',
					'tuningland-ai-vehicle-manager'
				)
			);
		}

		$cpt_manager = TL_AI_VM_Vehicle_CPT::instance();

		$scanner = TL_AI_VM_ACF_Scanner::instance();

		$schema_manager = TL_AI_VM_Field_Schema::instance();

		$cpts = $cpt_manager->get_all_cpts();

		$selected_cpt =
			$cpt_manager->get_selected_vehicle_cpt();

		$scan_result = null;

		$schema_result = null;

		$research_stats = array(
			'total'       => 0,
			'pending'     => 0,
			'researching' => 0,
			'analyzing'   => 0,
			'completed'   => 0,
			'failed'      => 0,
			'review'      => 0,
		);

		$approval_records = array();
		$approval_stats = array(
			'pending'  => 0,
			'approved' => 0,
			'rejected' => 0,
		);

		if ( class_exists( 'TL_AI_VM_Research_Approval' ) ) {
			$approval_manager = TL_AI_VM_Research_Approval::instance();
			$approval_records = $approval_manager->get_records( '', 100 );

			foreach ( $approval_records as $approval_record ) {
				$status = isset( $approval_record['status'] )
					? $approval_record['status']
					: 'pending';

				if ( isset( $approval_stats[ $status ] ) ) {
					$approval_stats[ $status ]++;
				}
			}
		}

		/**
		 * ---------------------------------------------------------
		 * Scan selected CPT
		 * ---------------------------------------------------------
		 */
		if ( ! empty( $selected_cpt ) ) {

			$scan_result =
				$scanner->scan(
					$selected_cpt
				);

			/**
			 * Field Schema.
			 *
			 * Do not write anything to ACF here.
			 * Schema is an intermediate representation only.
			 */
			if ( method_exists( $schema_manager, 'build' ) ) {

				$schema_result =
					$schema_manager->build(
						$selected_cpt
					);
			}

			/**
			 * Research statistics.
			 *
			 * The research engine is optional at this stage.
			 */
			if (
				class_exists(
					'TL_AI_VM_Web_Research_Engine'
				)
			) {

				$research_engine =
					TL_AI_VM_Web_Research_Engine::instance();

				/**
				 * Research statistics are vehicle-post based,
				 * therefore they require an actual vehicle ID.
				 *
				 * The dashboard currently has no selected vehicle,
				 * so global research statistics are intentionally
				 * not calculated here.
				 */
			}
		}

		/**
		 * ---------------------------------------------------------
		 * Dependency status
		 * ---------------------------------------------------------
		 */

		$acf_available =
			$scanner->is_acf_available();

		$analyzer_available =
			class_exists(
				'TL_AI_VM_AI_Field_Analyzer'
			);

		$research_available =
			class_exists(
				'TL_AI_VM_Web_Research_Engine'
			);

		$pipeline_status = array(
			'research_result' => class_exists( 'TL_AI_VM_Research_Result' ),
			'validation'      => class_exists( 'TL_AI_VM_Research_Validator' ),
			'confidence'      => class_exists( 'TL_AI_VM_Confidence' ),
			'decision'        => class_exists( 'TL_AI_VM_Decision' ),
			'approval'        => class_exists( 'TL_AI_VM_Research_Approval' ),
			'writer'          => class_exists( 'TL_AI_VM_ACF_Writer' ),
			'bulk'            => class_exists( 'TL_AI_VM_Bulk_Processor' ),
		);

		?>

		<div class="wrap tl-ai-vm-admin">

			<h1>
				<?php
				esc_html_e(
					'Tuningland AI Vehicle Manager',
					'tuningland-ai-vehicle-manager'
				);
				?>
			</h1>

			<p class="description">
				<?php
				esc_html_e(
					'Dynamic vehicle database, semantic field analysis and research architecture.',
					'tuningland-ai-vehicle-manager'
				);
				?>
			</p>

			<?php $this->render_notice(); ?>

			<hr>

			<!-- =====================================================
			     Vehicle CPT Selection
			====================================================== -->

			<div class="tl-ai-vm-card">

				<div class="tl-ai-vm-card-header">

					<div>

						<h2>
							<?php
							esc_html_e(
								'Vehicle CPT',
								'tuningland-ai-vehicle-manager'
							);
							?>
						</h2>

						<p>
							<?php
							esc_html_e(
								'Select the Custom Post Type that contains your vehicle records.',
								'tuningland-ai-vehicle-manager'
							);
							?>
						</p>

					</div>

				</div>

				<form
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				>

					<input
						type="hidden"
						name="action"
						value="tl_ai_vm_save_cpt"
					>

					<?php
					wp_nonce_field(
						'tl_ai_vm_save_cpt',
						'tl_ai_vm_nonce'
					);
					?>

					<select
						name="vehicle_cpt"
						class="tl-ai-vm-select"
					>

						<option value="">
							<?php
							esc_html_e(
								'— Select Vehicle CPT —',
								'tuningland-ai-vehicle-manager'
							);
							?>
						</option>

						<?php foreach ( $cpts as $slug => $cpt ) : ?>

							<option
								value="<?php echo esc_attr( $slug ); ?>"
								<?php selected(
									$selected_cpt,
									$slug
								); ?>
							>
								<?php
								echo esc_html(
									$cpt['label'] . ' (' . $slug . ')'
								);
								?>
							</option>

						<?php endforeach; ?>

					</select>

					<button
						type="submit"
						class="button button-primary"
					>
						<?php
						esc_html_e(
							'Save Vehicle CPT',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</button>

				</form>

			</div>

			<!-- =====================================================
			     System Status
			====================================================== -->

			<div class="tl-ai-vm-grid">

				<?php
				$this->render_status_card(
					'ACF',
					$acf_available
						? 'Available'
						: 'Not Available',
					$acf_available
				);
				?>

				<?php
				$this->render_status_card(
					'Vehicle CPT',
					! empty( $selected_cpt )
						? $selected_cpt
						: '—',
					! empty( $selected_cpt )
				);
				?>

				<?php
				$this->render_status_card(
					'AI Field Analyzer',
					$analyzer_available
						? 'Loaded'
						: 'Not Loaded',
					$analyzer_available
				);
				?>

				<?php
				$this->render_status_card(
					'Web Research Engine',
					$research_available
						? 'Loaded'
						: 'Not Loaded',
					$research_available
				);
				?>

			</div>

			<!-- =====================================================
			     ACF / Schema Statistics
			====================================================== -->

			<?php if ( ! empty( $selected_cpt ) ) : ?>

				<div class="tl-ai-vm-grid">

					<div class="tl-ai-vm-stat">

						<span class="tl-ai-vm-stat-title">
							<?php
							esc_html_e(
								'ACF Groups',
								'tuningland-ai-vehicle-manager'
							);
							?>
						</span>

						<strong>
							<?php
							echo $scan_result &&
								isset(
									$scan_result['total_groups']
								)
								? esc_html(
									$scan_result['total_groups']
								)
								: '0';
							?>
						</strong>

					</div>

					<div class="tl-ai-vm-stat">

						<span class="tl-ai-vm-stat-title">
							<?php
							esc_html_e(
								'ACF Fields',
								'tuningland-ai-vehicle-manager'
							);
							?>
						</span>

						<strong>
							<?php
							echo $scan_result &&
								isset(
									$scan_result['total_fields']
								)
								? esc_html(
									$scan_result['total_fields']
								)
								: '0';
							?>
						</strong>

					</div>

					<div class="tl-ai-vm-stat">

						<span class="tl-ai-vm-stat-title">
							<?php
							esc_html_e(
								'Schema Status',
								'tuningland-ai-vehicle-manager'
							);
							?>
						</span>

						<strong>

							<?php
							if (
								is_array( $schema_result ) &&
								! empty(
									$schema_result['success']
								)
							) :
								?>

								<span class="tl-ai-vm-status-success">
									<?php
									esc_html_e(
										'Ready',
										'tuningland-ai-vehicle-manager'
									);
									?>
								</span>

							<?php else : ?>

								<span class="tl-ai-vm-status-error">
									<?php
									esc_html_e(
										'Pending',
										'tuningland-ai-vehicle-manager'
									);
									?>
								</span>

							<?php endif; ?>

						</strong>

					</div>

					<div class="tl-ai-vm-stat">

						<span class="tl-ai-vm-stat-title">
							<?php
							esc_html_e(
								'Research Architecture',
								'tuningland-ai-vehicle-manager'
							);
							?>
						</span>

						<strong>

							<?php if ( $research_available ) : ?>

								<span class="tl-ai-vm-status-success">
									<?php
									esc_html_e(
										'Ready',
										'tuningland-ai-vehicle-manager'
									);
									?>
								</span>

							<?php else : ?>

								<span class="tl-ai-vm-status-error">
									<?php
									esc_html_e(
										'Pending',
										'tuningland-ai-vehicle-manager'
									);
									?>
								</span>

							<?php endif; ?>

						</strong>

					</div>

				</div>

			<?php endif; ?>

			<!-- =====================================================
			     Vehicle Research Runner
			====================================================== -->

			<div class="tl-ai-vm-card tl-ai-vm-research-launcher">

				<div class="tl-ai-vm-card-header">
					<div>
						<h2>AI Vehicle Research</h2>
						<p>Choose an existing vehicle and run the complete research pipeline. Empty ACF fields are targeted first.</p>
					</div>
				</div>

				<?php if ( empty( $selected_cpt ) ) : ?>
					<div class="tl-ai-vm-notice"><p>Select and save a Vehicle CPT first.</p></div>
				<?php else : ?>
					<?php
					$vehicle_posts = get_posts( array(
						'post_type' => $selected_cpt,
						'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
						'posts_per_page' => 100,
						'orderby' => 'title',
						'order' => 'ASC',
					) );
					?>
					<div class="tl-ai-vm-research-box" data-vehicle-id="" data-research-nonce="<?php echo esc_attr( wp_create_nonce( 'tl_ai_vm_async_research' ) ); ?>">
						<div class="tl-ai-vm-research-controls">
							<select class="tl-ai-vm-select tl-ai-vm-dashboard-vehicle" required>
								<option value="">— Select Vehicle —</option>
								<?php foreach ( $vehicle_posts as $vehicle_post ) : ?>
									<option value="<?php echo esc_attr( $vehicle_post->ID ); ?>"><?php echo esc_html( $vehicle_post->post_title . ' (#' . $vehicle_post->ID . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<label class="tl-ai-vm-checkbox"><input type="checkbox" class="tl-ai-vm-only-empty" value="1" checked> Research empty fields only</label>
						</div>

						<div class="tl-ai-vm-research-groups">
							<strong>Research Field Groups</strong>
							<p class="description">Uncheck groups such as Banner / Images when you only want technical vehicle data researched.</p>
							<?php $this->render_research_group_selector( $selected_cpt ); ?>
						</div>

						<button type="button" class="button button-primary tl-ai-vm-start-research">🔍 Research This Vehicle</button>
						<div class="tl-ai-vm-progress-wrap" style="display:none;">
							<div class="tl-ai-vm-progress-bar"><span></span></div>
							<div class="tl-ai-vm-progress-text">Preparing…</div>
							<div class="tl-ai-vm-progress-stage"></div>
							<div class="tl-ai-vm-progress-stats"></div>
							<button type="button" class="button tl-ai-vm-cancel-research" style="margin-top:8px;">Cancel</button>
							<div class="tl-ai-vm-progress-errors"></div>
							<div class="tl-ai-vm-live-activity" style="display:none;"></div>
							<div class="tl-ai-vm-live-results" style="display:none;"></div>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<!-- =====================================================
			     ACF Scanner
			====================================================== -->

			<div class="tl-ai-vm-card">

				<div class="tl-ai-vm-card-header">

					<div>

						<h2>
							<?php
							esc_html_e(
								'ACF Schema Scanner',
								'tuningland-ai-vehicle-manager'
							);
							?>
						</h2>

						<p>
							<?php
							esc_html_e(
								'Discover ACF Field Groups and Fields dynamically. No vehicle field names are hard-coded.',
								'tuningland-ai-vehicle-manager'
							);
							?>
						</p>

					</div>

					<?php if ( ! empty( $selected_cpt ) ) : ?>

						<form
							method="post"
							action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						>

							<input
								type="hidden"
								name="action"
								value="tl_ai_vm_scan_acf"
							>

							<input
								type="hidden"
								name="vehicle_cpt"
								value="<?php echo esc_attr( $selected_cpt ); ?>"
							>

							<?php
							wp_nonce_field(
								'tl_ai_vm_scan_acf',
								'tl_ai_vm_scan_nonce'
							);
							?>

							<button
								type="submit"
								class="button button-secondary"
							>
								<?php
								esc_html_e(
									'Rescan ACF',
									'tuningland-ai-vehicle-manager'
								);
								?>
							</button>

						</form>

					<?php endif; ?>

				</div>

				<?php if ( empty( $selected_cpt ) ) : ?>

					<div class="tl-ai-vm-notice">

						<p>
							<?php
							esc_html_e(
								'First select your Vehicle CPT above.',
								'tuningland-ai-vehicle-manager'
							);
							?>
						</p>

					</div>

				<?php elseif ( ! $acf_available ) : ?>

					<div class="tl-ai-vm-notice tl-ai-vm-notice-error">

						<p>
							<?php
							esc_html_e(
								'ACF was not detected. Please make sure Advanced Custom Fields is installed and active.',
								'tuningland-ai-vehicle-manager'
							);
							?>
						</p>

					</div>

				<?php elseif (
					empty( $scan_result['groups'] )
				) : ?>

					<div class="tl-ai-vm-notice">

						<p>
							<?php
							esc_html_e(
								'No ACF Field Groups were found for this CPT.',
								'tuningland-ai-vehicle-manager'
							);
							?>
						</p>

					</div>

				<?php else : ?>

					<div class="tl-ai-vm-groups">

						<?php foreach ( $scan_result['groups'] as $group ) : ?>

							<div class="tl-ai-vm-group">

								<div class="tl-ai-vm-group-header">

									<div>

										<h3>
											<?php
											echo esc_html(
												$group['title']
											);
											?>
										</h3>

										<code>
											<?php
											echo esc_html(
												$group['key']
											);
											?>
										</code>

									</div>

									<span>
										<?php
										printf(
											esc_html__(
												'%d fields',
												'tuningland-ai-vehicle-manager'
											),
											(int) $group['total_fields']
										);
										?>
									</span>

								</div>

								<?php if ( ! empty( $group['fields'] ) ) : ?>

									<div class="tl-ai-vm-fields">

										<?php
										$this->render_fields(
											$group['fields']
										);
										?>

									</div>

								<?php else : ?>

									<p class="tl-ai-vm-muted">
										<?php
										esc_html_e(
											'No fields found in this group.',
											'tuningland-ai-vehicle-manager'
										);
										?>
									</p>

								<?php endif; ?>

							</div>

						<?php endforeach; ?>

					</div>

				<?php endif; ?>

			</div>

			<!-- =====================================================
			     AI Architecture Status
			====================================================== -->

			<div class="tl-ai-vm-card">

				<div class="tl-ai-vm-card-header">

					<div>

						<h2>
							<?php
							esc_html_e(
								'AI Vehicle Data Pipeline',
								'tuningland-ai-vehicle-manager'
							);
							?>
						</h2>

						<p>
							<?php
							esc_html_e(
								'The system is designed to discover, understand, research, validate and only then write vehicle data.',
								'tuningland-ai-vehicle-manager'
							);
							?>
						</p>

					</div>

				</div>

				<ul class="tl-ai-vm-roadmap">

					<li class="done">
						<span>✓</span>
						<?php
						esc_html_e(
							'Dynamic Vehicle CPT Detection',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</li>

					<li class="done">
						<span>✓</span>
						<?php
						esc_html_e(
							'Dynamic ACF Field Discovery',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</li>

					<li class="done">
						<span>✓</span>
						<?php
						esc_html_e(
							'Dynamic Field Schema',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</li>

					<li class="<?php echo $analyzer_available ? 'done' : ''; ?>">
						<span>
							<?php echo $analyzer_available ? '✓' : '○'; ?>
						</span>
						<?php
						esc_html_e(
							'AI Field Semantic Analyzer',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</li>

					<li class="<?php echo $research_available ? 'done' : ''; ?>">
						<span>
							<?php echo $research_available ? '✓' : '○'; ?>
						</span>
						<?php
						esc_html_e(
							'Web Research Engine',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</li>

					<li class="<?php echo ! empty( $pipeline_status['research_result'] ) ? 'done' : ''; ?>">
						<span>
							<?php echo ! empty( $pipeline_status['research_result'] ) ? '✓' : '○'; ?>
						</span>
						<?php
						esc_html_e(
							'Research Result Intermediate Layer',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</li>

					<li class="<?php echo ! empty( $pipeline_status['validation'] ) ? 'done' : ''; ?>">
						<span>
							<?php echo ! empty( $pipeline_status['validation'] ) ? '✓' : '○'; ?>
						</span>
						<?php
						esc_html_e(
							'Multi-Source Validation',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</li>

					<li class="<?php echo ! empty( $pipeline_status['confidence'] ) ? 'done' : ''; ?>">
						<span>
							<?php echo ! empty( $pipeline_status['confidence'] ) ? '✓' : '○'; ?>
						</span>
						<?php
						esc_html_e(
							'AI Confidence System',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</li>

					<li class="<?php echo ! empty( $pipeline_status['decision'] ) ? 'done' : ''; ?>">
						<span>
							<?php echo ! empty( $pipeline_status['decision'] ) ? '✓' : '○'; ?>
						</span>
						<?php
						esc_html_e(
							'Auto / Review / Ignore Decision Layer',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</li>

					<li class="<?php echo ! empty( $pipeline_status['approval'] ) ? 'done' : ''; ?>">
						<span>
							<?php echo ! empty( $pipeline_status['approval'] ) ? '✓' : '○'; ?>
						</span>
						<?php
						esc_html_e(
							'Research Approval System',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</li>

					<li class="<?php echo ! empty( $pipeline_status['writer'] ) ? 'done' : ''; ?>">
						<span>
							<?php echo ! empty( $pipeline_status['writer'] ) ? '✓' : '○'; ?>
						</span>
						<?php
						esc_html_e(
							'Automatic ACF Writer',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</li>

					<li class="<?php echo ! empty( $pipeline_status['bulk'] ) ? 'done' : ''; ?>">
						<span>
							<?php echo ! empty( $pipeline_status['bulk'] ) ? '✓' : '○'; ?>
						</span>
						<?php
						esc_html_e(
							'Bulk Vehicle Processing',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</li>

				</ul>

			</div>

			<!-- =====================================================
			     Current Architecture Notice
			====================================================== -->

			<div class="tl-ai-vm-card">

				<h2>
					<?php
					esc_html_e(
						'Current Architecture',
						'tuningland-ai-vehicle-manager'
					);
					?>
				</h2>

				<div class="tl-ai-vm-notice">

					<p>
						<strong>
							<?php
							esc_html_e(
								'Important:',
								'tuningland-ai-vehicle-manager'
							);
							?>
						</strong>

						<?php
						esc_html_e(
							'The current research engine stores research tasks separately and does not directly modify ACF fields.',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</p>

					<p>
						<?php
						esc_html_e(
							'This separation is intentional: research results must be validated and reviewed before any final ACF write operation.',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</p>

				</div>

			</div>

		</div>

		<?php
	}

	/**
	 * Render a status card.
	 *
	 * @param string $title   Title.
	 * @param string $value   Value.
	 * @param bool   $success Whether status is successful.
	 *
	 * @return void
	 */
	private function render_status_card(
		$title,
		$value,
		$success = false
	) {
		?>

		<div class="tl-ai-vm-stat">

			<span class="tl-ai-vm-stat-title">
				<?php echo esc_html( $title ); ?>
			</span>

			<strong>

				<?php if ( $success ) : ?>

					<span class="tl-ai-vm-status-success">
						<?php echo esc_html( $value ); ?>
					</span>

				<?php else : ?>

					<span class="tl-ai-vm-status-error">
						<?php echo esc_html( $value ); ?>
					</span>

				<?php endif; ?>

			</strong>

		</div>

		<?php
	}

	/**
	 * Render admin notice based on redirect parameter.
	 *
	 * @return void
	 */
	private function render_notice() {

		if ( empty( $_GET['tl_ai_vm_notice'] ) ) {
			return;
		}

		$notice = sanitize_key(
			wp_unslash(
				$_GET['tl_ai_vm_notice']
			)
		);

		$messages = array(
			'cpt_saved' => array(
				'type'    => 'success',
				'message' => 'Vehicle CPT was saved successfully.',
			),

			'cpt_cleared' => array(
				'type'    => 'success',
				'message' => 'Vehicle CPT selection was cleared.',
			),

			'cpt_error' => array(
				'type'    => 'error',
				'message' => 'Vehicle CPT could not be saved.',
			),

			'scan_complete' => array(
				'type'    => 'success',
				'message' => 'ACF scan completed successfully.',
			),

			'scan_error' => array(
				'type'    => 'error',
				'message' => 'ACF scan could not be completed.',
			),

			'approval_complete' => array(
				'type'    => 'success',
				'message' => 'Research was approved and the verified value was written to ACF.',
			),

			'approval_rejected' => array(
				'type'    => 'warning',
				'message' => 'Research result was rejected.',
			),

			'approval_error' => array(
				'type'    => 'error',
				'message' => 'Research approval or ACF writing could not be completed.',
			),

			'research_started' => array(
				'type' => 'success',
				'message' => 'AI vehicle research completed. Check Research Approval for review items.',
			),

			'research_error' => array(
				'type' => 'error',
				'message' => 'AI vehicle research could not be completed. Check the plugin logs for details.',
			),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$data = $messages[ $notice ];

		if ( 'research_error' === $notice && ! empty( $_GET['tl_ai_vm_detail'] ) ) {
			$detail = sanitize_text_field( wp_unslash( $_GET['tl_ai_vm_detail'] ) );
			if ( $detail ) {
				$data['message'] .= ' ' . $detail;
			}
		}

		$class =
			'success' === $data['type']
				? 'notice notice-success is-dismissible'
				: 'notice notice-error is-dismissible';

		?>

		<div class="<?php echo esc_attr( $class ); ?>">

			<p>
				<?php echo esc_html( $data['message'] ); ?>
			</p>

		</div>

		<?php
	}

	/**
	 * Render fields recursively.
	 *
	 * @param array $fields Fields.
	 * @param int   $level  Nesting level.
	 *
	 * @return void
	 */
	private function render_fields(
		$fields,
		$level = 0
	) {

		if ( empty( $fields ) ) {
			return;
		}

		?>

		<table class="widefat striped tl-ai-vm-fields-table">

			<thead>

				<tr>

					<th>
						<?php
						esc_html_e(
							'Label',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</th>

					<th>
						<?php
						esc_html_e(
							'Name',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</th>

					<th>
						<?php
						esc_html_e(
							'Type',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</th>

					<th>
						<?php
						esc_html_e(
							'Required',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</th>

					<th>
						<?php
						esc_html_e(
							'AI Status',
							'tuningland-ai-vehicle-manager'
						);
						?>
					</th>

				</tr>

			</thead>

			<tbody>

				<?php foreach ( $fields as $field ) : ?>

					<tr>

						<td>
							<?php
							echo esc_html(
								isset(
									$field['label']
								)
									? $field['label']
									: ''
							);
							?>
						</td>

						<td>

							<code>
								<?php
								echo esc_html(
									isset(
										$field['name']
									)
										? $field['name']
										: ''
								);
								?>
							</code>

						</td>

						<td>

							<code>
								<?php
								echo esc_html(
									isset(
										$field['type']
									)
										? $field['type']
										: ''
								);
								?>
							</code>

						</td>

						<td>

							<?php if ( ! empty( $field['required'] ) ) : ?>

								<span class="tl-ai-vm-required">
									<?php
									esc_html_e(
										'Yes',
										'tuningland-ai-vehicle-manager'
									);
									?>
								</span>

							<?php else : ?>

								<span class="tl-ai-vm-muted">
									<?php
									esc_html_e(
										'No',
										'tuningland-ai-vehicle-manager'
									);
									?>
								</span>

							<?php endif; ?>

						</td>

						<td>

							<span class="tl-ai-vm-ai-pending">
								<?php
								esc_html_e(
									'Pending AI',
									'tuningland-ai-vehicle-manager'
								);
								?>
							</span>

						</td>

					</tr>

					<?php if (
						! empty( $field['sub_fields'] ) &&
						is_array( $field['sub_fields'] )
					) : ?>

						<tr class="tl-ai-vm-nested-row">

							<td colspan="5">

								<strong>
									<?php
									echo esc_html(
										isset(
											$field['label']
										)
											? $field['label']
											: ''
									);
									?>
								</strong>

								<?php
								$this->render_fields(
									$field['sub_fields'],
									$level + 1
								);
								?>

							</td>

						</tr>

					<?php endif; ?>

				<?php endforeach; ?>

			</tbody>

		</table>

		<?php
	}

	/**
	 * Register the AI research box on vehicle edit screens.
	 */
	public function register_vehicle_meta_box() {
		$post_type = sanitize_key( get_option( 'tl_ai_vm_vehicle_cpt', '' ) );
		if ( $post_type ) {
			add_meta_box(
				'tl-ai-vm-vehicle-research',
				'Tuningland AI Vehicle Manager',
				array( $this, 'render_vehicle_meta_box' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render vehicle research controls.
	 */
	/** Render selectable ACF groups for asynchronous research. */
	private function render_research_group_selector( $post_type ) {
		$post_type = sanitize_key( $post_type );
		if ( empty( $post_type ) ) { return; }
		$schema = TL_AI_VM_Field_Schema::instance()->build( $post_type );
		$groups = isset( $schema['groups'] ) && is_array( $schema['groups'] ) ? $schema['groups'] : array();
		if ( empty( $groups ) ) {
			echo '<p class="tl-ai-vm-muted">No ACF groups found.</p>';
			return;
		}
		foreach ( $groups as $group ) {
			$key = isset( $group['key'] ) ? sanitize_key( $group['key'] ) : '';
			if ( ! $key ) { continue; }
			$title = isset( $group['title'] ) ? $group['title'] : $key;
			$count = isset( $group['total_fields'] ) ? absint( $group['total_fields'] ) : 0;
			?>
			<label class="tl-ai-vm-group-toggle">
				<input type="checkbox" class="tl-ai-vm-research-group" value="<?php echo esc_attr( $key ); ?>" checked>
				<span><?php echo esc_html( $title ); ?></span>
				<small><?php echo esc_html( $count . ' fields' ); ?></small>
			</label>
			<?php
		}
	}

	public function render_vehicle_meta_box( $post ) {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		?>
		<p><strong>AI Vehicle Research</strong></p>
		<p class="description">Research empty ACF fields, validate sources and automatically write only results that pass the 90% Auto threshold.</p>
		<div class="tl-ai-vm-research-box" data-vehicle-id="<?php echo esc_attr( $post->ID ); ?>" data-research-nonce="<?php echo esc_attr( wp_create_nonce( 'tl_ai_vm_async_research' ) ); ?>">
			<div class="tl-ai-vm-research-groups"><strong>Research Field Groups</strong><p class="description">Select only the groups you want the AI to research.</p><?php $this->render_research_group_selector( $post->post_type ); ?></div>
			<button type="button" class="button button-primary tl-ai-vm-start-research" style="width:100%;">🔍 Research This Vehicle</button>
			<div class="tl-ai-vm-progress-wrap" style="display:none;">
				<div class="tl-ai-vm-progress-bar"><span></span></div>
				<div class="tl-ai-vm-progress-text">Preparing…</div>
				<div class="tl-ai-vm-progress-stage"></div>
				<div class="tl-ai-vm-progress-stats"></div>
				<button type="button" class="button tl-ai-vm-cancel-research" style="margin-top:8px;">Cancel</button>
				<div class="tl-ai-vm-progress-errors"></div>
				<div class="tl-ai-vm-live-activity" style="display:none;"></div>
				<div class="tl-ai-vm-live-results" style="display:none;"></div>
			</div>
		</div>
		<?php $this->render_vehicle_research_results( $post->ID ); ?>
		<?php
	}

	private function render_vehicle_research_results( $post_id ) {
		if ( ! class_exists( 'TL_AI_VM_Research_Result' ) ) { return; }
		$results = $this->latest_results_by_field( TL_AI_VM_Research_Result::instance()->get_all( array( 'post_id' => absint( $post_id ), 'limit' => 0 ) ) );
		if ( empty( $results ) ) {
			echo '<div class="tl-ai-vm-research-results"><p class="tl-ai-vm-muted">No Research Results stored yet.</p></div>';
			return;
		}
		echo '<div class="tl-ai-vm-research-results"><h4>Latest Research Results</h4>';
		foreach ( array_reverse( $results ) as $result ) {
			$field = isset($result['field']['label']) ? $result['field']['label'] : (isset($result['field']['name']) ? $result['field']['name'] : 'Field');
			$value = isset($result['normalized_value']) ? $result['normalized_value'] : '';
			if ( is_array($value) ) { $value = implode(', ', array_map('strval',$value)); }
			$confidence = isset($result['confidence']['percentage']) ? (int)$result['confidence']['percentage'] : (isset($result['confidence']['score']) ? (int)round((float)$result['confidence']['score']*100) : 0);
			$decision = isset($result['decision']['decision']) ? $result['decision']['decision'] : 'review';
			$method = isset($result['metadata']['method']) ? $result['metadata']['method'] : '';
			echo '<div class="tl-ai-vm-result-card"><strong>'.esc_html($field).'</strong><div class="tl-ai-vm-result-value">'.esc_html(is_scalar($value)?(string)$value:wp_json_encode($value,JSON_UNESCAPED_UNICODE)).'</div><small>Confidence: '.esc_html($confidence).'% · Decision: '.esc_html($decision).($method?' · AI: '.esc_html($method):'').'</small>';
			if ( ! empty($result['sources']) && is_array($result['sources']) ) {
				echo '<details><summary>Sources ('.count($result['sources']).')</summary><ul>';
				foreach(array_slice($result['sources'],0,5) as $src){ if(!empty($src['url'])) echo '<li><a href="'.esc_url($src['url']).'" target="_blank" rel="noopener">'.esc_html(isset($src['title'])?$src['title']:$src['url']).'</a></li>'; }
				echo '</ul></details>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Start the complete research pipeline for one vehicle.
	 */
	public function start_vehicle_research() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'tuningland-ai-vehicle-manager' ) ); }
		check_admin_referer( 'tl_ai_vm_start_vehicle_research', 'tl_ai_vm_research_nonce' );

		$post_id = isset( $_POST['vehicle_id'] ) ? absint( wp_unslash( $_POST['vehicle_id'] ) ) : 0;
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) { $this->redirect( array( 'tl_ai_vm_notice' => 'research_error' ) ); }

		$selected = sanitize_key( get_option( 'tl_ai_vm_vehicle_cpt', '' ) );
		if ( $selected && $post->post_type !== $selected ) { $this->redirect( array( 'tl_ai_vm_notice' => 'research_error' ) ); }

		$args = array( 'only_empty' => isset( $_POST['only_empty'] ), 'use_ai' => true, 'max_fields' => 50, 'search_limit' => 8 );
		$runner = class_exists( 'TL_AI_VM_Vehicle_Research_Runner' ) ? TL_AI_VM_Vehicle_Research_Runner::instance() : null;
		if ( ! $runner ) { $this->redirect( array( 'tl_ai_vm_notice' => 'research_error' ) ); }

		$result = $runner->run( $post_id, $args );

		if ( ! empty( $result['success'] ) ) {
			$this->redirect(
				array(
					'tl_ai_vm_notice' => 'research_started',
					'vehicle_id'      => $post_id,
					'created'         => isset( $result['created'] ) ? (int) $result['created'] : 0,
					'auto'            => isset( $result['auto_written'] ) ? (int) $result['auto_written'] : 0,
					'review'          => isset( $result['review'] ) ? (int) $result['review'] : 0,
				)
			);
		}

		$detail = '';
		if ( ! empty( $result['errors'][0]['error'] ) ) {
			$detail = sanitize_text_field( $result['errors'][0]['error'] );
		} elseif ( ! empty( $result['error'] ) ) {
			$detail = sanitize_text_field( $result['error'] );
		} elseif ( ! empty( $result['message'] ) ) {
			$detail = sanitize_text_field( $result['message'] );
		}

		$this->redirect(
			array(
				'tl_ai_vm_notice' => 'research_error',
				'tl_ai_vm_detail' => function_exists( 'mb_substr' )
					? mb_substr( $detail, 0, 300 )
					: substr( $detail, 0, 300 ),
			)
		);

	}

	/**
	 * Save selected Vehicle CPT.
	 *
	 * @return void
	 */
	/** Start an asynchronous vehicle research job. */
	public function ajax_start_research_async() {
		check_ajax_referer( 'tl_ai_vm_async_research', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 ); }
		$post_id = isset( $_POST['vehicle_id'] ) ? absint( wp_unslash( $_POST['vehicle_id'] ) ) : 0;
		$selected_groups = array();
		if ( isset( $_POST['selected_groups'] ) ) {
			$raw_groups = sanitize_text_field( wp_unslash( $_POST['selected_groups'] ) );
			$selected_groups = array_values( array_unique( array_filter( array_map( 'sanitize_key', explode( ',', $raw_groups ) ) ) ) );
		}
		$only_empty = ! empty( $_POST['only_empty'] );
		$result = TL_AI_VM_Async_Research::instance()->start( $post_id, array( 'only_empty' => $only_empty, 'use_ai' => true, 'max_fields' => 50, 'search_limit' => 1, 'selected_groups' => $selected_groups, 'groups_filter_enabled' => true ) );
		if ( empty( $result['success'] ) ) { wp_send_json_error( array( 'message' => isset( $result['error'] ) ? $result['error'] : 'Could not start research.' ) ); }
		wp_send_json_success( $result );
	}

	/** Execute one short asynchronous research step. */
	public function ajax_research_tick() {
		check_ajax_referer( 'tl_ai_vm_async_research', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 ); }
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$result = TL_AI_VM_Async_Research::instance()->tick( $job_id );
		if ( empty( $result['success'] ) ) { wp_send_json_error( array( 'message' => isset( $result['error'] ) ? $result['error'] : 'Research step failed.' ) ); }
		wp_send_json_success( $result );
	}

	public function ajax_research_status() {
		check_ajax_referer( 'tl_ai_vm_async_research', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 ); }
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$result = TL_AI_VM_Async_Research::instance()->status( $job_id );
		if ( empty( $result['success'] ) ) { wp_send_json_error( array( 'message' => isset( $result['error'] ) ? $result['error'] : 'Status unavailable.' ) ); }
		wp_send_json_success( $result );
	}

	public function ajax_cancel_research() {
		check_ajax_referer( 'tl_ai_vm_async_research', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 ); }
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$result = TL_AI_VM_Async_Research::instance()->cancel( $job_id );
		if ( empty( $result['success'] ) ) { wp_send_json_error( array( 'message' => isset( $result['error'] ) ? $result['error'] : 'Could not cancel research.' ) ); }
		wp_send_json_success( $result );
	}


    /** Keep only the newest Research Result per vehicle field. */
    private function latest_results_by_field( $results ) {
        $latest = array();
        if ( ! is_array( $results ) ) { return array(); }
        foreach ( $results as $result ) {
            if ( ! is_array( $result ) ) { continue; }
            if ( ! empty( $result['field']['key'] ) ) { $key = 'key:' . $result['field']['key']; }
            elseif ( ! empty( $result['field']['name'] ) ) { $key = 'name:' . $result['field']['name']; }
            else { continue; }
            if ( ! isset( $latest[ $key ] ) ) { $latest[ $key ] = $result; continue; }
            $old = isset( $latest[ $key ]['updated_at'] ) ? $latest[ $key ]['updated_at'] : ( isset( $latest[ $key ]['created_at'] ) ? $latest[ $key ]['created_at'] : '' );
            $new = isset( $result['updated_at'] ) ? $result['updated_at'] : ( isset( $result['created_at'] ) ? $result['created_at'] : '' );
            if ( strcmp( (string) $new, (string) $old ) > 0 ) { $latest[ $key ] = $result; }
        }
        return array_values( $latest );
    }

    /** Return persisted research results for a vehicle. */
    public function ajax_research_results() {
        check_ajax_referer( 'tl_ai_vm_async_research', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 ); }
        $post_id = isset( $_POST['vehicle_id'] ) ? absint( wp_unslash( $_POST['vehicle_id'] ) ) : 0;
        if ( ! $post_id ) { wp_send_json_error( array( 'message' => 'Vehicle ID is required.' ), 400 ); }
        $results = class_exists( 'TL_AI_VM_Research_Result' ) ? $this->latest_results_by_field( TL_AI_VM_Research_Result::instance()->get_all( array( 'post_id' => $post_id, 'limit' => 0 ) ) ) : array();
        $out = array();
        foreach ( $results as $r ) {
            $value = isset( $r['normalized_value'] ) ? $r['normalized_value'] : null;
            if ( is_array( $value ) ) { $value = array_values( $value ); }
            $confidence = 0;
            if ( isset( $r['confidence']['percentage'] ) && is_numeric( $r['confidence']['percentage'] ) ) { $confidence = (int) $r['confidence']['percentage']; }
            elseif ( isset( $r['confidence']['score'] ) && is_numeric( $r['confidence']['score'] ) ) { $confidence = (int) round( (float) $r['confidence']['score'] * 100 ); }
            $out[] = array(
                'id' => isset($r['id']) ? sanitize_text_field($r['id']) : '',
                'field_key' => isset($r['field']['key']) ? sanitize_text_field($r['field']['key']) : '',
                'field_label' => isset($r['field']['label']) ? sanitize_text_field($r['field']['label']) : '',
                'value' => $value,
                'confidence' => $confidence,
                'decision' => isset($r['decision']) ? sanitize_key($r['decision']) : 'review',
                'status' => isset($r['status']) ? sanitize_key($r['status']) : 'researched',
                'sources' => isset($r['sources']) && is_array($r['sources']) ? array_slice($r['sources'],0,5) : array(),
                'method' => isset($r['metadata']['method']) ? sanitize_key($r['metadata']['method']) : '',
            );
        }
        wp_send_json_success( array( 'vehicle_id' => $post_id, 'results' => array_reverse( $out ) ) );
    }

    /** Approve one persisted research result and write it to ACF. */
    public function ajax_approve_result() {
        check_ajax_referer( 'tl_ai_vm_async_research', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 ); }
        $id = isset($_POST['result_id']) ? sanitize_text_field(wp_unslash($_POST['result_id'])) : '';
        if ( ! $id || ! class_exists('TL_AI_VM_Research_Approval') ) { wp_send_json_error(array('message'=>'Approval service is unavailable.'),400); }
        $result = TL_AI_VM_Research_Approval::instance()->approve_and_write($id);
        if ( empty($result['success']) ) { wp_send_json_error(array('message'=>isset($result['error'])?$result['error']:'Approval failed.')); }
        wp_send_json_success($result);
    }

    /** Correct a result, write it and remember the correction. */
    public function ajax_correct_result() {
        check_ajax_referer( 'tl_ai_vm_async_research', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 ); }
        $id = isset($_POST['result_id']) ? sanitize_text_field(wp_unslash($_POST['result_id'])) : '';
        $value = isset($_POST['value']) ? wp_unslash($_POST['value']) : '';
        $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';
        $source = isset($_POST['source']) ? esc_url_raw(wp_unslash($_POST['source'])) : '';
        if ( ! $id || ! class_exists('TL_AI_VM_Research_Approval') ) { wp_send_json_error(array('message'=>'Approval service is unavailable.'),400); }
        $result = TL_AI_VM_Research_Approval::instance()->correct_and_write($id, $value, $note, $source);
        if ( ! empty($result['success']) && ! empty($note) && class_exists('TL_AI_VM_Learning_Memory') ) { $approval = TL_AI_VM_Research_Approval::instance()->get($id); $rid = !empty($approval['research_id']) ? $approval['research_id'] : $id; $research = class_exists('TL_AI_VM_Research_Result') ? TL_AI_VM_Research_Result::instance()->get($rid) : null; if ( is_array($research) ) { TL_AI_VM_Learning_Memory::instance()->remember_rule($research, $note, $source); } }
        if ( empty($result['success']) ) { wp_send_json_error(array('message'=>isset($result['error'])?$result['error']:'Correction failed.')); }
        wp_send_json_success($result);
    }

    /** Reject a result and remember the feedback. */
    public function ajax_reject_result() {
        check_ajax_referer( 'tl_ai_vm_async_research', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 ); }
        $id = isset($_POST['result_id']) ? sanitize_text_field(wp_unslash($_POST['result_id'])) : '';
        $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';
        if ( ! $id || ! class_exists('TL_AI_VM_Research_Approval') ) { wp_send_json_error(array('message'=>'Approval service is unavailable.'),400); }
        $result = TL_AI_VM_Research_Approval::instance()->reject($id, $note);
        if ( empty($result['success']) ) { wp_send_json_error(array('message'=>isset($result['error'])?$result['error']:'Reject failed.')); }
        wp_send_json_success($result);
    }

    /** Approve all review results at or above the configured confidence threshold. */
    public function ajax_approve_high_confidence() {
        check_ajax_referer( 'tl_ai_vm_async_research', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 ); }
        $post_id = isset($_POST['vehicle_id']) ? absint(wp_unslash($_POST['vehicle_id'])) : 0;
        $threshold = (int) get_option('tl_ai_vm_auto_threshold',90);
        $threshold = max(50,min(100,$threshold));
        $results = class_exists('TL_AI_VM_Research_Result') ? TL_AI_VM_Research_Result::instance()->get_all(array('post_id'=>$post_id,'limit'=>100)) : array();
        $done=0; $skipped=0; $errors=array();
        foreach($results as $r){
            $status=isset($r['status'])?$r['status']:'';
            if(in_array($status,array('approved','rejected'),true)) { $skipped++; continue; }
            $confidence=0;
            if(isset($r['confidence']['percentage'])&&is_numeric($r['confidence']['percentage'])) $confidence=(int)$r['confidence']['percentage'];
            elseif(isset($r['confidence']['score'])&&is_numeric($r['confidence']['score'])) $confidence=(int)round((float)$r['confidence']['score']*100);
            if($confidence < $threshold){ $skipped++; continue; }
            $id=isset($r['id'])?$r['id']:'';
            $one=TL_AI_VM_Research_Approval::instance()->approve_and_write($id);
            if(!empty($one['success'])) $done++; else $errors[]=isset($one['error'])?$one['error']:'Write failed.';
        }
        wp_send_json_success(array('approved'=>$done,'skipped'=>$skipped,'errors'=>$errors,'threshold'=>$threshold));
    }

	public function save_vehicle_cpt() {

		if ( ! current_user_can( 'manage_options' ) ) {

			wp_die(
				esc_html__(
					'Permission denied.',
					'tuningland-ai-vehicle-manager'
				)
			);
		}

		check_admin_referer(
			'tl_ai_vm_save_cpt',
			'tl_ai_vm_nonce'
		);

		$post_type = isset( $_POST['vehicle_cpt'] )
			? sanitize_key(
				wp_unslash(
					$_POST['vehicle_cpt']
				)
			)
			: '';

		$cpt_manager =
			TL_AI_VM_Vehicle_CPT::instance();

		if ( empty( $post_type ) ) {

			delete_option(
				'tl_ai_vm_vehicle_cpt'
			);

			TL_AI_VM_ACF_Scanner::instance()
				->clear_cache();

			TL_AI_VM_Field_Schema::instance()
				->clear_cache();

			$this->redirect(
				array(
					'tl_ai_vm_notice' => 'cpt_cleared',
				)
			);

			return;
		}

		if (
			! $cpt_manager->set_selected_vehicle_cpt(
				$post_type
			)
		) {

			$this->redirect(
				array(
					'tl_ai_vm_notice' => 'cpt_error',
				)
			);

			return;
		}

		/**
		 * The selected CPT changed.
		 *
		 * Clear all discovery/schema caches.
		 */
		TL_AI_VM_ACF_Scanner::instance()
			->clear_cache();

		TL_AI_VM_Field_Schema::instance()
			->clear_cache();

		if (
			class_exists(
				'TL_AI_VM_Logger'
			)
		) {

			TL_AI_VM_Logger::instance()->success(
				'Vehicle CPT selected.',
				'admin',
				array(
					'post_type' => $post_type,
				)
			);
		}

		$this->redirect(
			array(
				'tl_ai_vm_notice' => 'cpt_saved',
			)
		);
	}

	/**
	 * Scan ACF.
	 *
	 * @return void
	 */
	public function scan_acf() {

		if ( ! current_user_can( 'manage_options' ) ) {

			wp_die(
				esc_html__(
					'Permission denied.',
					'tuningland-ai-vehicle-manager'
				)
			);
		}

		check_admin_referer(
			'tl_ai_vm_scan_acf',
			'tl_ai_vm_scan_nonce'
		);

		$post_type = isset( $_POST['vehicle_cpt'] )
			? sanitize_key(
				wp_unslash(
					$_POST['vehicle_cpt']
				)
			)
			: '';

		if ( empty( $post_type ) ) {

			$this->redirect(
				array(
					'tl_ai_vm_notice' => 'scan_error',
				)
			);

			return;
		}

		$scanner =
			TL_AI_VM_ACF_Scanner::instance();

		$result =
			$scanner->scan(
				$post_type,
				true
			);

		/**
		 * Rebuild Field Schema.
		 *
		 * This creates/refreshes the semantic-neutral
		 * representation of the discovered ACF structure.
		 */
		$schema_manager =
			TL_AI_VM_Field_Schema::instance();

		if (
			method_exists(
				$schema_manager,
				'build'
			)
		) {

			$schema_manager->build(
				$post_type,
				true
			);
		}

		if (
			! empty(
				$result['success']
			)
		) {

			if (
				class_exists(
					'TL_AI_VM_Logger'
				)
			) {

				TL_AI_VM_Logger::instance()->success(
					'ACF scan completed.',
					'acf',
					array(
						'post_type' =>
							$post_type,

						'total_groups' =>
							isset(
								$result[
									'total_groups'
								]
							)
								? $result[
									'total_groups'
								]
								: 0,

						'total_fields' =>
							isset(
								$result[
									'total_fields'
								]
							)
								? $result[
									'total_fields'
								]
								: 0,
					)
				);
			}

			$this->redirect(
				array(
					'tl_ai_vm_notice' =>
						'scan_complete',
				)
			);

			return;
		}

		if (
			class_exists(
				'TL_AI_VM_Logger'
			)
		) {

			TL_AI_VM_Logger::instance()->error(
				'ACF scan failed.',
				'acf',
				array(
					'post_type' => $post_type,

					'error' =>
						isset(
							$result['error']
						)
							? $result['error']
							: 'Unknown error.',
				)
			);
		}

		$this->redirect(
			array(
				'tl_ai_vm_notice' => 'scan_error',
			)
		);
	}

	/**
	 * Approve a research result and write it to ACF.
	 *
	 * @return void
	 */
	public function approve_research() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'tuningland-ai-vehicle-manager' ) );
		}

		check_admin_referer( 'tl_ai_vm_approve_research', 'tl_ai_vm_approval_nonce' );

		$id = isset( $_POST['approval_id'] )
			? sanitize_text_field( wp_unslash( $_POST['approval_id'] ) )
			: '';

		$result = TL_AI_VM_Research_Approval::instance()->approve_and_write( $id );

		$this->redirect(
			array(
				'tl_ai_vm_notice' => ! empty( $result['success'] ) ? 'approval_complete' : 'approval_error',
			)
		);
	}

	/**
	 * Reject a research result.
	 *
	 * @return void
	 */
	public function reject_research() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'tuningland-ai-vehicle-manager' ) );
		}

		check_admin_referer( 'tl_ai_vm_reject_research', 'tl_ai_vm_reject_nonce' );

		$id = isset( $_POST['approval_id'] )
			? sanitize_text_field( wp_unslash( $_POST['approval_id'] ) )
			: '';

		$result = TL_AI_VM_Research_Approval::instance()->reject( $id );

		$this->redirect(
			array(
				'tl_ai_vm_notice' => ! empty( $result['success'] ) ? 'approval_rejected' : 'approval_error',
			)
		);
	}


	/** Render AI/search settings. */
	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'tuningland-ai-vehicle-manager' ) ); }
		$client = class_exists( 'TL_AI_VM_AI_Client' ) ? TL_AI_VM_AI_Client::instance() : null;
		$enabled = (bool) get_option( 'tl_ai_vm_ai_enabled', 1 );
		$threshold = max( 50, min( 100, absint( get_option( 'tl_ai_vm_auto_threshold', 90 ) ) ) );
		$order = $client ? $client->get_provider_order() : array( 'gemini_worker', 'gemini', 'deepseek', 'openai' );
		$providers = array('gemini_worker'=>'Gemini via Cloudflare Worker','gemini'=>'Gemini direct','deepseek'=>'DeepSeek','openai'=>'OpenAI');
		?>
		<div class="wrap tl-ai-vm-admin"><h1>Tuningland AI Vehicle Manager — AI Settings</h1>
		<?php if ( isset($_GET['tl_ai_vm_settings']) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash($_GET['tl_ai_vm_settings']) ) ); ?></p></div><?php endif; ?>
		<?php if ( isset($_GET['tl_ai_vm_test_status']) ) : $ok = 'success' === sanitize_key( wp_unslash($_GET['tl_ai_vm_test_status']) ); ?><div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><strong><?php echo esc_html( sanitize_text_field( wp_unslash($_GET['tl_ai_vm_test_provider'] ?? '') ) ); ?>:</strong> <?php echo esc_html( sanitize_text_field( wp_unslash($_GET['tl_ai_vm_test_message'] ?? '') ) ); ?><?php if(isset($_GET['tl_ai_vm_test_text']) && $_GET['tl_ai_vm_test_text']!==''): ?><br><code><?php echo esc_html( sanitize_text_field( wp_unslash($_GET['tl_ai_vm_test_text']) ) ); ?></code><?php endif; ?></p></div><?php endif; ?>
		<div class="tl-ai-vm-card">
		<h2>AI Provider Chain</h2>
		<p class="description">The manager tries providers in this order. If a provider is not configured or fails, it automatically continues to the next one. Web research remains the fallback.</p>
		<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
		<input type="hidden" name="action" value="tl_ai_vm_save_settings"><?php wp_nonce_field('tl_ai_vm_save_settings','tl_ai_vm_settings_nonce'); ?>
		<table class="form-table">
		<tr><th>Providers</th><td><?php foreach($providers as $key=>$label): ?><label style="display:block;margin:6px 0"><input type="checkbox" name="provider_enabled[<?php echo esc_attr($key); ?>]" value="1" <?php checked($client ? $client->get_provider_enabled($key) : false); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?></td></tr>
		<tr><th>Provider priority</th><td><select name="provider_order[]" multiple size="3" style="min-width:260px;"><?php foreach(array('gemini_worker'=>'Gemini via Cloudflare Worker','gemini'=>'Gemini direct','deepseek'=>'DeepSeek','openai'=>'OpenAI') as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected(in_array($key,$order,true)); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><p class="description">Select providers. Their order follows the order shown above; defaults are Gemini → DeepSeek → OpenAI.</p></td></tr>
<tr><th>Gemini Cloudflare Worker URL</th><td><input type="url" class="regular-text" name="gemini_worker_url" value="<?php echo esc_attr( $client ? $client->get_gemini_worker_url() : '' ); ?>" placeholder="https://your-worker.example.workers.dev/gemini"><p class="description">اولویت اول پیشنهادی: Worker شما Gemini را صدا می‌زند. کلید Gemini روی Worker بماند و داخل وردپرس ذخیره نشود.</p></td></tr>
		<tr><th>Gemini API Key</th><td><input type="password" class="regular-text" name="gemini_api_key" autocomplete="new-password" placeholder="Leave blank to keep current key"><?php if($client && $client->provider_configured('gemini')): ?> <span class="tl-ai-vm-status-success">Configured</span><?php endif; ?></td></tr>
		<tr><th>Gemini Model</th><td><input type="text" class="regular-text" name="gemini_model" value="<?php echo esc_attr($client ? $client->get_provider_model('gemini') : 'gemini-3.6-flash'); ?>"></td></tr>
		<tr><th>DeepSeek API Key</th><td><input type="password" class="regular-text" name="deepseek_api_key" autocomplete="new-password" placeholder="Leave blank to keep current key"><?php if($client && $client->provider_configured('deepseek')): ?> <span class="tl-ai-vm-status-success">Configured</span><?php endif; ?></td></tr>
		<tr><th>DeepSeek Model</th><td><input type="text" class="regular-text" name="deepseek_model" value="<?php echo esc_attr($client ? $client->get_provider_model('deepseek') : 'deepseek-chat'); ?>"></td></tr>
		<tr><th>OpenAI API Key</th><td><input type="password" class="regular-text" name="openai_api_key" autocomplete="new-password" placeholder="Leave blank to keep current key"><?php if($client && $client->provider_configured('openai')): ?> <span class="tl-ai-vm-status-success">Configured</span><?php endif; ?></td></tr>
		<tr><th>OpenAI Model</th><td><input type="text" class="regular-text" name="openai_model" value="<?php echo esc_attr($client ? $client->get_provider_model('openai') : 'gpt-5.6'); ?>"></td></tr>
		<tr><th>Auto approval threshold</th><td><input type="number" min="50" max="100" name="auto_threshold" value="<?php echo esc_attr($threshold); ?>"> % <p class="description">Results at or above this confidence are automatically approved and written to ACF, provided there is no source conflict.</p></td></tr>
		</table><p><button class="button button-primary">Save Settings</button></p></form>
		</div>
		<div class="tl-ai-vm-card"><h2>Test Providers</h2>
		<p class="description">Test is performed with AJAX and does not reload the page. For Gemini Worker the plugin calls the Worker's health endpoint directly.</p>
		<div id="tl-ai-vm-ai-test-result" class="tl-ai-vm-ai-test-result" style="display:none;margin:12px 0;padding:12px;border-left:4px solid #2271b1;background:#f6f7f7"></div>
		<?php foreach(array('gemini_worker'=>'Gemini via Cloudflare Worker','gemini'=>'Gemini direct','deepseek'=>'DeepSeek','openai'=>'OpenAI') as $key=>$label): ?>
		<button type="button" class="button tl-ai-vm-test-provider" data-provider="<?php echo esc_attr($key); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('tl_ai_vm_test_ai_ajax')); ?>" <?php disabled(!$client || !$client->get_provider_enabled($key) || !$client->provider_configured($key)); ?>>Test <?php echo esc_html($label); ?></button>
		<?php endforeach; ?>
		</div>
		<div class="tl-ai-vm-card"><h2>How the pipeline works</h2><ol><li>Internal Tuningland assets are resolved first when configured.</li><li>Enabled AI providers are tried in the configured order, with Gemini Worker first by default.</li><li>Gemini Worker can use Google Search grounding before Web Research fallback.</li><li>Web research is used only when AI cannot return a reliable answer.</li><li>Validation and confidence are calculated.</li><li>High-confidence results are auto-approved; lower results go to review.</li><li>Only approved results are written to ACF.</li></ol></div>
		<script>
		(function(){
		 const box=document.getElementById('tl-ai-vm-ai-test-result');
		 document.querySelectorAll('.tl-ai-vm-test-provider').forEach(function(btn){
		  btn.addEventListener('click',function(){
		   const provider=btn.dataset.provider; const nonce=btn.dataset.nonce;
		   btn.disabled=true; const old=btn.textContent; btn.textContent='Testing…';
		   box.style.display='block'; box.style.borderLeftColor='#2271b1'; box.textContent='Testing '+provider+'…';
		   const fd=new FormData(); fd.append('action','tl_ai_vm_test_ai_ajax'); fd.append('provider',provider); fd.append('nonce',nonce);
		   fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json().then(function(j){return {http:r.status,data:j};});}).then(function(x){
		    const d=x.data&&x.data.data?x.data.data:x.data; const ok=!!(x.data&&x.data.success&&d&&d.success);
		    box.style.borderLeftColor=ok?'#00a32a':'#d63638';
		    if(ok){ box.innerHTML='<strong>🟢 Connection successful</strong><br>Provider: '+(d.provider||provider)+'<br>HTTP: '+(d.http_code||x.http)+'<br>Model: '+(d.model||'—')+'<br>Latency: '+(d.latency_ms||'—')+' ms<br><code>'+String(d.text||d.message||'OK').replace(/[<>&]/g,function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c];})+'</code>'; }
		    else { box.innerHTML='<strong>🔴 Connection failed</strong><br>HTTP: '+x.http+'<br>'+String((d&&d.error)||'Unknown error').replace(/</g,'&lt;'); }
		   }).catch(function(e){box.style.borderLeftColor='#d63638';box.textContent='Connection test failed: '+e.message;}).finally(function(){btn.disabled=false;btn.textContent=old;});
		  });
		 });
		})();
		</script>
		</div><?php
	}

	public function save_settings() {
		if ( ! current_user_can('manage_options') ) { wp_die( esc_html__('Permission denied.','tuningland-ai-vehicle-manager') ); }
		check_admin_referer('tl_ai_vm_save_settings','tl_ai_vm_settings_nonce');
		update_option('tl_ai_vm_ai_enabled', !empty($_POST['ai_enabled']) ? 1 : 0, false);
		$client = TL_AI_VM_AI_Client::instance();
		$enabled_map = isset($_POST['provider_enabled']) ? (array) wp_unslash($_POST['provider_enabled']) : array();
		foreach ( array('gemini_worker','gemini','deepseek','openai') as $provider ) { $client->set_provider_enabled( $provider, ! empty( $enabled_map[$provider] ) ); }
		foreach ( array('gemini','deepseek','openai') as $provider ) {
			$key_name = $provider . '_api_key';
			$model_name = $provider . '_model';
			if ( isset($_POST[$key_name]) && '' !== trim((string)wp_unslash($_POST[$key_name])) ) { $client->set_provider_api_key($provider, trim((string)wp_unslash($_POST[$key_name]))); }
			if ( isset($_POST[$model_name]) ) { $client->set_provider_model($provider, sanitize_text_field(wp_unslash($_POST[$model_name]))); }
		}
		$worker_url = isset($_POST['gemini_worker_url']) ? esc_url_raw( trim( wp_unslash($_POST['gemini_worker_url']) ) ) : '';
		$client->set_gemini_worker_url( $worker_url );

		$order = isset($_POST['provider_order']) ? array_map('sanitize_key',(array)wp_unslash($_POST['provider_order'])) : array('gemini','deepseek','openai');
		$client->set_provider_order($order);
		$threshold = isset($_POST['auto_threshold']) ? absint(wp_unslash($_POST['auto_threshold'])) : 90;
		update_option('tl_ai_vm_auto_threshold', max(50,min(100,$threshold)), false);
		$this->redirect_page(self::PAGE_SLUG . '-settings', array('tl_ai_vm_settings'=>'AI settings saved.'));
	}

	public function test_openai() {
		return $this->test_ai_provider();
	}

	public function test_ai_provider_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'error' => 'Permission denied.' ), 403 ); }
		check_ajax_referer( 'tl_ai_vm_test_ai_ajax', 'nonce' );
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'gemini_worker';
		$start = microtime( true );
		$result = TL_AI_VM_AI_Client::instance()->test_connection( $provider );
		$result['latency_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );
		$result['provider'] = $provider;
		$result['model'] = TL_AI_VM_AI_Client::instance()->get_provider_model( $provider );
		if ( ! empty( $result['success'] ) ) { wp_send_json_success( $result ); }
		wp_send_json_error( $result, 200 );
	}

	public function test_ai_provider() {
		if ( ! current_user_can('manage_options') ) { wp_die( esc_html__('Permission denied.','tuningland-ai-vehicle-manager') ); }
		check_admin_referer('tl_ai_vm_test_ai_provider','tl_ai_vm_test_ai_nonce');
		$provider = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : 'gemini';
		$result = TL_AI_VM_AI_Client::instance()->test_connection($provider);
		$name = ucfirst($provider);
		$success = ! empty( $result['success'] );
		$message = $success ? 'Connection successful. HTTP ' . ( isset($result['http_code']) ? (int)$result['http_code'] : 'OK' ) : 'Connection failed: ' . (isset($result['error']) ? $result['error'] : 'Unknown error.');
		$this->redirect_page(self::PAGE_SLUG . '-settings', array(
			'tl_ai_vm_test_status' => $success ? 'success' : 'error',
			'tl_ai_vm_test_provider' => $name,
			'tl_ai_vm_test_message' => $message,
			'tl_ai_vm_test_text' => $success ? (string)($result['text'] ?? '') : '',
		));
	}


	/** Render field/group-specific intelligence rules. */
	public function render_field_intelligence() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'tuningland-ai-vehicle-manager' ) ); }
		$intel = TL_AI_VM_Field_Intelligence::instance();
		$settings = $intel->get_settings();
		$cpt = sanitize_key( get_option( 'tl_ai_vm_vehicle_cpt', '' ) );
		$schema = $cpt ? TL_AI_VM_Field_Schema::instance()->build( $cpt ) : array();
		$groups = isset( $schema['groups'] ) && is_array( $schema['groups'] ) ? $schema['groups'] : array();
		?>
		<div class="wrap tl-ai-vm-admin"><h1>Tuningland AI Vehicle Manager — Field Intelligence</h1>
		<p class="description">Per-field routing. Leave a field empty to use the normal Source Data priorities. Image fields can be resolved from the internal Media Library without web research.</p>
		<?php if ( isset( $_GET['tl_ai_vm_fields'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['tl_ai_vm_fields'] ) ) ); ?></p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tl_ai_vm_save_field_intelligence"><?php wp_nonce_field( 'tl_ai_vm_save_field_intelligence', 'tl_ai_vm_field_nonce' ); ?>
		<?php $memory_stats = class_exists('TL_AI_VM_Learning_Memory') ? TL_AI_VM_Learning_Memory::instance()->stats() : array(); ?>
		<div class="tl-ai-vm-card"><h2>Learning Memory</h2><p>Approved/rejected research patterns are stored as JSONL files under <code><?php echo esc_html(isset($memory_stats['path'])?$memory_stats['path']:'wp-content/tuningland-ai-vm-data/learning/'); ?></code> instead of growing wp_options. This can be backed up, inspected or deleted independently.</p><p><strong><?php echo esc_html(isset($memory_stats['files'])?$memory_stats['files']:0); ?></strong> files · <strong><?php echo esc_html(isset($memory_stats['bytes'])?size_format($memory_stats['bytes']):'0 B'); ?></strong></p></div>
		<div class="tl-ai-vm-card"><h2>Global Performance</h2>
		<table class="form-table">
		<tr><th>Enable Field Intelligence</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>> Enable field-specific routing and internal asset resolution.</label></td></tr>
		<tr><th>Parallel searches</th><td><input type="number" min="1" max="8" name="parallel_searches" value="<?php echo esc_attr( $settings['parallel_searches'] ); ?>"> <span class="description">Recommended: 5. Searches are parallel only when server cURL multi is available.</span></td></tr>
		<tr><th>Parallel source pages</th><td><input type="number" min="1" max="8" name="parallel_pages" value="<?php echo esc_attr( $settings['parallel_pages'] ); ?>"> <span class="description">Recommended: 5.</span></td></tr>
		<tr><th>Default domains</th><td><textarea name="default_domains" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", $settings['default_domains'] ) ); ?></textarea><p class="description">One domain per line. These are used only when a field/group has no explicit sources.</p></td></tr>
		</table></div>
		<?php if ( empty( $groups ) ) : ?><div class="notice notice-warning"><p>First select a Vehicle CPT and scan its ACF fields.</p></div><?php else : ?>
		<div class="tl-ai-vm-card"><h2>Group Rules</h2><p class="description">A group rule is inherited by all fields unless an individual field overrides it.</p>
		<table class="widefat striped"><thead><tr><th>Group</th><th>Mode</th><th>Priority domains</th></tr></thead><tbody>
		<?php foreach ( $groups as $group ) : $gkey = isset($group['key']) ? sanitize_key($group['key']) : ''; if ( ! $gkey ) continue; $grule = isset($settings['groups'][$gkey]) ? $settings['groups'][$gkey] : array(); ?>
		<tr><td><strong><?php echo esc_html(isset($group['title'])?$group['title']:$gkey); ?></strong><br><code><?php echo esc_html($gkey); ?></code></td><td><select name="group_rules[<?php echo esc_attr($gkey); ?>][mode]"><option value="web" <?php selected(isset($grule['mode'])?$grule['mode']:'web','web'); ?>>Web</option><option value="internal" <?php selected(isset($grule['mode'])?$grule['mode']:'web','internal'); ?>>Internal</option><option value="disabled" <?php selected(isset($grule['mode'])?$grule['mode']:'web','disabled'); ?>>Disabled</option></select></td><td><input type="text" class="regular-text" name="group_rules[<?php echo esc_attr($gkey); ?>][domains]" value="<?php echo esc_attr(implode(', ',isset($grule['domains'])?(array)$grule['domains']:array())); ?>" placeholder="mycarlubs.com, example.com"></td></tr>
		<?php endforeach; ?></tbody></table></div>
		<div class="tl-ai-vm-card"><h2>Field Rules</h2><p class="description">Mode <strong>Internal</strong> is intended for images/media already stored on Tuningland. Disabled skips the field entirely.</p>
		<table class="widefat striped"><thead><tr><th>Group</th><th>Field</th><th>Type</th><th>Mode</th><th>Priority domains</th><th>Internal image</th></tr></thead><tbody>
		<?php foreach ( $groups as $group ) : $gkey = isset($group['key']) ? sanitize_key($group['key']) : ''; if ( ! $gkey ) continue; $gtitle = isset($group['title']) ? $group['title'] : $gkey; $grule = isset($settings['groups'][$gkey]) ? $settings['groups'][$gkey] : array(); ?>
		<?php if ( ! empty( $group['fields'] ) ) : foreach ( $group['fields'] as $field ) : $fkey = isset($field['key']) ? sanitize_key($field['key']) : ''; if ( ! $fkey ) continue; $rule = isset($settings['fields'][$fkey]) ? array_replace_recursive(array(),$settings['fields'][$fkey]) : array(); ?>
		<tr><td><strong><?php echo esc_html($gtitle); ?></strong><input type="hidden" name="group_rules[<?php echo esc_attr($gkey); ?>][keep]" value="1"></td><td><strong><?php echo esc_html(isset($field['label'])?$field['label']:$field['name']); ?></strong><br><code><?php echo esc_html($fkey); ?></code></td><td><code><?php echo esc_html(isset($field['type'])?$field['type']:''); ?></code></td>
		<td><select name="field_rules[<?php echo esc_attr($fkey); ?>][mode]"><option value="web" <?php selected(isset($rule['mode'])?$rule['mode']:'web','web'); ?>>Web</option><option value="internal" <?php selected(isset($rule['mode'])?$rule['mode']:'web','internal'); ?>>Internal</option><option value="disabled" <?php selected(isset($rule['mode'])?$rule['mode']:'web','disabled'); ?>>Disabled</option></select></td>
		<td><input type="text" class="regular-text" name="field_rules[<?php echo esc_attr($fkey); ?>][domains]" value="<?php echo esc_attr(implode(', ',isset($rule['domains'])?(array)$rule['domains']:array())); ?>" placeholder="mycarlubs.com, example.com"></td>
		<td><?php if ( in_array(isset($field['type'])?$field['type']:'',array('image','gallery'),true) ) : ?><label><input type="checkbox" name="field_rules[<?php echo esc_attr($fkey); ?>][internal_images]" value="1" <?php checked(!empty($rule['internal_images'])); ?>> Use Media Library</label><?php else : ?>—<?php endif; ?></td></tr>
		<?php endforeach; endif; ?>
		<?php endforeach; ?></tbody></table></div>
		<?php endif; ?><p><button class="button button-primary">Save Field Intelligence</button></p></form></div>
		<?php
	}

	public function save_field_intelligence() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'tuningland-ai-vehicle-manager' ) ); }
		check_admin_referer( 'tl_ai_vm_save_field_intelligence', 'tl_ai_vm_field_nonce' );
		$settings = array(
			'enabled' => ! empty( $_POST['enabled'] ) ? 1 : 0,
			'parallel_searches' => isset($_POST['parallel_searches']) ? absint(wp_unslash($_POST['parallel_searches'])) : 5,
			'parallel_pages' => isset($_POST['parallel_pages']) ? absint(wp_unslash($_POST['parallel_pages'])) : 5,
			'default_domains' => isset($_POST['default_domains']) ? preg_split('/\r\n|\r|\n/', sanitize_textarea_field(wp_unslash($_POST['default_domains']))) : array(),
			'groups' => array(), 'fields' => array(),
		);
		$groups_post = isset($_POST['group_rules']) && is_array($_POST['group_rules']) ? wp_unslash($_POST['group_rules']) : array();
		foreach($groups_post as $key=>$rule){$key=sanitize_key($key);if(!$key||!is_array($rule))continue;$settings['groups'][$key]=array('mode'=>isset($rule['mode'])?sanitize_key($rule['mode']):'web','domains'=>isset($rule['domains'])?preg_split('/[,\r\n]+/',sanitize_text_field($rule['domains'])):array());}
		$fields = isset($_POST['field_rules']) && is_array($_POST['field_rules']) ? wp_unslash($_POST['field_rules']) : array();
		foreach($fields as $key=>$rule){$key=sanitize_key($key);if(!$key||!is_array($rule))continue;$settings['fields'][$key]=array('mode'=>isset($rule['mode'])?sanitize_key($rule['mode']):'web','domains'=>isset($rule['domains'])?preg_split('/[,\r\n]+/',sanitize_text_field($rule['domains'])):array(),'internal_images'=>!empty($rule['internal_images']));}
		TL_AI_VM_Field_Intelligence::instance()->save_settings($settings);
		$this->redirect_page(self::PAGE_SLUG . '-fields',array('tl_ai_vm_fields'=>'Field Intelligence settings saved.'));
	}

	/** Render Source Data manager. */
	public function render_sources() {
		if ( ! current_user_can('manage_options') ) { wp_die( esc_html__('Permission denied.','tuningland-ai-vehicle-manager') ); }
		$manager = TL_AI_VM_Source_Manager::instance(); $profiles = $manager->get_profiles();
		$global_lines=array(); foreach($profiles['global'] as $src){$global_lines[]=$src['url'].' | '.$src['label'].' | '.$src['priority'].' | '.implode(',',$src['groups']);}
		$brand_lines=array(); foreach($profiles['brands'] as $brand){foreach($brand['sources'] as $src){$brand_lines[]=$brand['name'].' | '.implode(',',$brand['aliases']).' | '.$src['url'].' | '.$src['label'].' | '.$src['priority'].' | '.implode(',',$src['groups']);}}
		$cpt=sanitize_key(get_option('tl_ai_vm_vehicle_cpt','')); $vehicles=$cpt?get_posts(array('post_type'=>$cpt,'post_status'=>array('publish','draft','pending','private'),'posts_per_page'=>200,'orderby'=>'title','order'=>'ASC')):array();
		$selected_vehicle=isset($_GET['vehicle_id'])?absint($_GET['vehicle_id']):0; if(!$selected_vehicle&&!empty($vehicles)){$selected_vehicle=$vehicles[0]->ID;}
		$vehicle_lines=array(); if($selected_vehicle&&isset($profiles['vehicles'][$selected_vehicle])){foreach($profiles['vehicles'][$selected_vehicle] as $src){$vehicle_lines[]=$src['url'].' | '.$src['label'].' | '.$src['priority'].' | '.implode(',',$src['groups']);}}
		?>
		<div class="wrap tl-ai-vm-admin"><h1>Tuningland AI Vehicle Manager — Source Data</h1>
		<?php if(isset($_GET['tl_ai_vm_sources'])):?><div class="notice notice-success is-dismissible"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['tl_ai_vm_sources'])));?></p></div><?php endif;?>
		<div class="tl-ai-vm-card"><h2>Source Priority</h2><p>Research order: <strong>Vehicle-specific → Brand → Global → General Web Search</strong>.</p><p class="description">Global/Vehicle format: <code>URL | Label | Priority | group_key1,group_key2</code><br>Brand format: <code>Brand | Alias1,Alias2 | URL | Label | Priority | group_key1,group_key2</code></p></div>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="tl_ai_vm_save_sources"><?php wp_nonce_field('tl_ai_vm_save_sources','tl_ai_vm_sources_nonce');?>
		<div class="tl-ai-vm-card"><h2>Global Sources</h2><textarea name="global_sources" rows="8" class="large-text code"><?php echo esc_textarea(implode("\n",$global_lines));?></textarea></div>
		<div class="tl-ai-vm-card"><h2>Brand Sources</h2><p class="description">Example: Toyota | Toyota,تويوتا | https://www.toyota.com/owners/ | Toyota Owners | 100 |</p><textarea name="brand_sources" rows="12" class="large-text code"><?php echo esc_textarea(implode("\n",$brand_lines));?></textarea></div>
		<div class="tl-ai-vm-card"><h2>Vehicle-specific Sources</h2><select name="vehicle_id" class="tl-ai-vm-select" onchange="if(this.value){window.location='?page=<?php echo esc_js(self::PAGE_SLUG . '-sources');?>&vehicle_id='+this.value;}"><option value="">Select vehicle</option><?php foreach($vehicles as $vehicle):?><option value="<?php echo esc_attr($vehicle->ID);?>" <?php selected($selected_vehicle,$vehicle->ID);?>><?php echo esc_html($vehicle->post_title.' (#'.$vehicle->ID.')');?></option><?php endforeach;?></select><textarea name="vehicle_sources" rows="8" class="large-text code"><?php echo esc_textarea(implode("\n",$vehicle_lines));?></textarea></div>
		<p><button class="button button-primary">Save Source Data</button></p></form>
		<div class="tl-ai-vm-card"><h2>Recommended</h2><ul><li>Official manufacturer manuals/owners portals for specifications.</li><li>Trusted technical databases for cross-checking.</li><li>Local automotive sources such as mycarlubs.com where appropriate and permitted.</li><li>General web search as fallback.</li></ul></div></div>
		<?php
	}

	public function save_sources() {
		if ( ! current_user_can('manage_options') ) { wp_die( esc_html__('Permission denied.','tuningland-ai-vehicle-manager') ); }
		check_admin_referer('tl_ai_vm_save_sources','tl_ai_vm_sources_nonce'); $profiles=array('global'=>array(),'brands'=>array(),'vehicles'=>array());
		$profiles['global']=$this->parse_source_lines(isset($_POST['global_sources'])?wp_unslash($_POST['global_sources']):'');
		$lines=isset($_POST['brand_sources'])?preg_split('/\r\n|\r|\n/',(string)wp_unslash($_POST['brand_sources'])):array();
		foreach($lines as $line){$parts=array_map('trim',explode('|',$line));if(count($parts)<5)continue;$brand=sanitize_text_field($parts[0]);$key=sanitize_key($brand);if(!$key)continue;if(!isset($profiles['brands'][$key]))$profiles['brands'][$key]=array('name'=>$brand,'aliases'=>array_filter(array_map('sanitize_text_field',explode(',',$parts[1]))),'sources'=>array());$profiles['brands'][$key]['sources'][]=array('url'=>esc_url_raw($parts[2]),'label'=>sanitize_text_field($parts[3]),'priority'=>max(1,min(100,(float)$parts[4])),'enabled'=>true,'groups'=>isset($parts[5])?array_filter(array_map('sanitize_key',explode(',',$parts[5]))):array(),'type'=>'technical');}
		$vehicle_id=isset($_POST['vehicle_id'])?absint($_POST['vehicle_id']):0;if($vehicle_id)$profiles['vehicles'][$vehicle_id]=$this->parse_source_lines(isset($_POST['vehicle_sources'])?wp_unslash($_POST['vehicle_sources']):'');
		TL_AI_VM_Source_Manager::instance()->save_profiles($profiles);$this->redirect_page(self::PAGE_SLUG.'-sources',array('tl_ai_vm_sources'=>'Source data saved.','vehicle_id'=>$vehicle_id));
	}

	private function parse_source_lines($text){$out=array();$lines=preg_split('/\r\n|\r|\n/',(string)$text);foreach($lines as $line){$line=trim($line);if(!$line||0===strpos($line,'#'))continue;$parts=array_map('trim',explode('|',$line));$url=isset($parts[0])?esc_url_raw($parts[0]):'';if(!$url)continue;$out[]=array('url'=>$url,'label'=>isset($parts[1])&&$parts[1]?$parts[1]:wp_parse_url($url,PHP_URL_HOST),'priority'=>isset($parts[2])?(float)$parts[2]:80,'enabled'=>true,'groups'=>isset($parts[3])?array_filter(array_map('sanitize_key',explode(',',$parts[3]))):array(),'type'=>'technical');}return $out;}
	private function redirect_page($page,$args=array()){$url=add_query_arg($args,admin_url('admin.php?page='.sanitize_key($page)));wp_safe_redirect($url);exit;}

	/**
	 * Redirect back to dashboard.
	 *
	 * @param array $args Query arguments.
	 *
	 * @return void
	 */
	private function redirect(
		$args = array()
	) {

		$url = add_query_arg(
			$args,
			admin_url(
				'admin.php?page=' . self::PAGE_SLUG
			)
		);

		wp_safe_redirect(
			$url
		);

		exit;
	}
}
