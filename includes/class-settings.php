<?php
/**
 * Settings (Settings API) for the plugin.
 *
 * @package TopTex_WooCommerce
 */

namespace TopTexWooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the plugin settings.
 */
class Settings {

	/**
	 * Option group and page slug.
	 */
	const OPTION_GROUP = 'toptex_options';
	const OPTION_NAME  = 'toptex_options';

	/**
	 * Default option values.
	 */
	const DEFAULTS = array(
		'markup_percent'      => 30,
		'price_country'       => 'fr',
		'import_images'       => 'yes',
		'sync_frequency'      => 'daily',
		'catalog_category_id' => 0,
		'auto_publish'        => 'yes',
		'append_sku_suffix'   => '',
	);

	/**
	 * Cached option values.
	 *
	 * @var array|null
	 */
	private $options;

	/**
	 * Boots the settings.
	 *
	 * @return Settings
	 */
	public static function instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
			$instance->hooks();
		}
		return $instance;
	}

	/**
	 * Wires WordPress hooks.
	 *
	 * @return void
	 */
	private function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_toptex_run_sync', array( $this, 'handle_manual_sync' ) );
	}

	/**
	 * Adds the settings page under the WooCommerce menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			\__( 'TopTex Settings', 'toptex-woocommerce' ),
			\__( 'TopTex', 'toptex-woocommerce' ),
			'manage_woocommerce',
			'toptex-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers settings fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => self::DEFAULTS,
			)
		);

		add_settings_section(
			'toptex_general',
			\__( 'Catalog import', 'toptex-woocommerce' ),
			'__return_false',
			'toptex-settings'
		);

		add_settings_field(
			'markup_percent',
			\__( 'Price markup (%)', 'toptex-woocommerce' ),
			array( $this, 'field_markup' ),
			'toptex-settings',
			'toptex_general'
		);

		add_settings_field(
			'price_country',
			\__( 'Price list', 'toptex-woocommerce' ),
			array( $this, 'field_price_country' ),
			'toptex-settings',
			'toptex_general'
		);

		add_settings_field(
			'import_images',
			\__( 'Import images', 'toptex-woocommerce' ),
			array( $this, 'field_import_images' ),
			'toptex-settings',
			'toptex_general'
		);

		add_settings_field(
			'catalog_category_id',
			\__( 'Import into category', 'toptex-woocommerce' ),
			array( $this, 'field_category' ),
			'toptex-settings',
			'toptex_general'
		);

		add_settings_field(
			'auto_publish',
			\__( 'Product status', 'toptex-woocommerce' ),
			array( $this, 'field_auto_publish' ),
			'toptex-settings',
			'toptex_general'
		);

		add_settings_field(
			'sync_frequency',
			\__( 'Automatic sync', 'toptex-woocommerce' ),
			array( $this, 'field_sync_frequency' ),
			'toptex-settings',
			'toptex_general'
		);

		add_settings_field(
			'append_sku_suffix',
			\__( 'SKU suffix', 'toptex-woocommerce' ),
			array( $this, 'field_sku_suffix' ),
			'toptex-settings',
			'toptex_general'
		);
	}

	/**
	 * Retrieves the merged options.
	 *
	 * @return array
	 */
	public function get_options() {
		if ( null === $this->options ) {
			$this->options = wp_parse_args( (array) get_option( self::OPTION_NAME, array() ), self::DEFAULTS );
		}
		return $this->options;
	}

	/**
	 * Sanitizes the submitted options before saving.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_options( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::DEFAULTS;

		if ( isset( $input['markup_percent'] ) ) {
			$out['markup_percent'] = min( 1000, max( 0, (float) $input['markup_percent'] ) );
		}

		if ( isset( $input['price_country'] ) && in_array( $input['price_country'], array( 'fr', 'it' ), true ) ) {
			$out['price_country'] = sanitize_key( $input['price_country'] );
		}

		$out['import_images'] = ( isset( $input['import_images'] ) && 'yes' === $input['import_images'] ) ? 'yes' : 'no';

		if ( isset( $input['catalog_category_id'] ) ) {
			$out['catalog_category_id'] = absint( $input['catalog_category_id'] );
		}

		$out['auto_publish'] = ( isset( $input['auto_publish'] ) && 'yes' === $input['auto_publish'] ) ? 'yes' : 'no';

		if ( isset( $input['sync_frequency'] ) && in_array( $input['sync_frequency'], array( 'hourly', 'twicedaily', 'daily', 'weekly', 'manual' ), true ) ) {
			$out['sync_frequency'] = sanitize_key( $input['sync_frequency'] );
		}

		$out['append_sku_suffix'] = isset( $input['append_sku_suffix'] ) ? sanitize_text_field( strtoupper( $input['append_sku_suffix'] ) ) : '';

		return $out;
	}

	/**
	 * Markup percentage field.
	 *
	 * @return void
	 */
	public function field_markup() {
		$opts = $this->get_options();
		?>
		<input type="number" step="0.01" min="0" max="1000" name="<?php echo esc_attr( self::OPTION_NAME . '[markup_percent]' ); ?>" value="<?php echo esc_attr( $opts['markup_percent'] ); ?>" />
		<p class="description"><?php esc_html_e( 'Percentage added to the TopTex wholesale price to form the selling price.', 'toptex-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * Price country selector.
	 *
	 * @return void
	 */
	public function field_price_country() {
		$opts = $this->get_options();
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[price_country]' ); ?>">
			<option value="fr" <?php selected( $opts['price_country'], 'fr' ); ?>><?php esc_html_e( 'France', 'toptex-woocommerce' ); ?></option>
			<option value="it" <?php selected( $opts['price_country'], 'it' ); ?>><?php esc_html_e( 'Italy', 'toptex-woocommerce' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Wholesale price list used as the base price.', 'toptex-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * Import images toggle.
	 *
	 * @return void
	 */
	public function field_import_images() {
		$opts = $this->get_options();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME . '[import_images]' ); ?>" value="yes" <?php checked( $opts['import_images'], 'yes' ); ?> />
			<?php esc_html_e( 'Download and attach product images from the TopTex media library.', 'toptex-woocommerce' ); ?>
		</label>
		<?php
	}

	/**
	 * Destination category selector.
	 *
	 * @return void
	 */
	public function field_category() {
		$opts = $this->get_options();
		$args = array(
			'taxonomy'         => 'product_cat',
			'hide_empty'       => false,
			'hierarchical'     => true,
			'show_option_none' => \__( '— Root (use TopTex families) —', 'toptex-woocommerce' ),
			'name'             => self::OPTION_NAME . '[catalog_category_id]',
			'selected'         => $opts['catalog_category_id'],
		);
		wp_dropdown_categories( $args );
		?>
		<p class="description"><?php esc_html_e( 'Optional: import everything under a single category. Leave empty to mirror TopTex families/sub-families as categories.', 'toptex-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * Auto publish toggle.
	 *
	 * @return void
	 */
	public function field_auto_publish() {
		$opts = $this->get_options();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME . '[auto_publish]' ); ?>" value="yes" <?php checked( $opts['auto_publish'], 'yes' ); ?> />
			<?php esc_html_e( 'Publish imported products automatically (leave unchecked to import as drafts).', 'toptex-woocommerce' ); ?>
		</label>
		<?php
	}

	/**
	 * Sync frequency selector.
	 *
	 * @return void
	 */
	public function field_sync_frequency() {
		$opts    = $this->get_options();
		$choices = array(
			'hourly'     => \__( 'Hourly', 'toptex-woocommerce' ),
			'twicedaily' => \__( 'Twice daily', 'toptex-woocommerce' ),
			'daily'      => \__( 'Daily', 'toptex-woocommerce' ),
			'weekly'     => \__( 'Weekly', 'toptex-woocommerce' ),
			'manual'     => \__( 'Manual only', 'toptex-woocommerce' ),
		);
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[sync_frequency]' ); ?>">
			<?php foreach ( $choices as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $opts['sync_frequency'], $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * SKU suffix field.
	 *
	 * @return void
	 */
	public function field_sku_suffix() {
		$opts = $this->get_options();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME . '[append_sku_suffix]' ); ?>" value="<?php echo esc_attr( $opts['append_sku_suffix'] ); ?>" />
		<p class="description"><?php esc_html_e( 'Optional suffix appended to every imported SKU to avoid collisions with existing products.', 'toptex-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'TopTex for WooCommerce', 'toptex-woocommerce' ); ?></h1>

			<div class="notice notice-info">
				<p><?php esc_html_e( 'This plugin imports the TopTex wholesale garment catalog. Each TopTex style becomes a WooCommerce variable product with Color and Size attributes. Prices come from the TopTex wholesale list plus your markup.', 'toptex-woocommerce' ); ?></p>
			</div>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( 'toptex-settings' );
				submit_button( \__( 'Save settings', 'toptex-woocommerce' ) );
				?>
			</form>

			<h2><?php esc_html_e( 'Synchronization', 'toptex-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'Run a full import now. Existing products are updated in place; nothing is duplicated.', 'toptex-woocommerce' ); ?></p>

			<?php $this->render_last_sync_status(); ?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="toptex_run_sync" />
				<?php wp_nonce_field( 'toptex_run_sync' ); ?>
				<?php submit_button( \__( 'Run import now', 'toptex-woocommerce' ), 'primary', 'toptex_sync_submit' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Shows last sync timestamp and result.
	 *
	 * @return void
	 */
	private function render_last_sync_status() {
		$status = get_option( 'toptex_last_sync', array() );

		if ( empty( $status['time'] ) ) {
			echo '<p>' . esc_html__( 'No import has run yet.', 'toptex-woocommerce' ) . '</p>';
			return;
		}

		$time = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $status['time'] ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: last sync date/time. */
				\__( 'Last import: %1$s', 'toptex-woocommerce' ),
				$time
			)
		) . '</p>';

		if ( isset( $status['result'] ) ) {
			$result = $status['result'];
			/* translators: 1: imported count, 2: updated count, 3: error count. */
			$summary = sprintf( \__( 'Imported: %1$d — Updated: %2$d — Errors: %3$d', 'toptex-woocommerce' ), (int) $result['imported'], (int) $result['updated'], (int) $result['errors'] );
			echo '<p>' . esc_html( $summary ) . '</p>';
		}
	}

	/**
	 * Handles the manual "run sync now" action.
	 *
	 * @return void
	 */
	public function handle_manual_sync() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'toptex-woocommerce' ) );
		}

		check_admin_referer( 'toptex_run_sync' );

		$result = Importer::instance()->run_import();

		if ( is_wp_error( $result ) ) {
			set_transient( 'toptex_sync_error', $result->get_error_message(), 60 );
		}

		wp_safe_redirect( add_query_arg( 'toptex_synced', '1', wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=toptex-settings' ) ) );
		exit;
	}
}
