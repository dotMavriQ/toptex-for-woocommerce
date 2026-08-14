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
		'api_key'             => '',
		'api_username'        => '',
		'api_password'        => '',
		'usage_right'         => 'b2b_b2c',
		'language'            => 'en',
		'import_scope'        => 'all',
		'import_references'   => '',
		'per_page'            => 50,
		'markup_percent'      => 30,
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
		add_action( 'admin_post_toptex_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueues the copy-to-clipboard script on the settings page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( 'woocommerce_page_toptex-settings' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'toptex-admin',
			TOP_TEX_PLUGIN_DIR_URL . 'assets/toptex-admin.js',
			array(),
			TOP_TEX_VERSION,
			true
		);
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
			'toptex_api',
			\__( 'TopTex API connection', 'toptex-woocommerce' ),
			'__return_false',
			'toptex-settings'
		);

		add_settings_field(
			'api_key',
			\__( 'API key', 'toptex-woocommerce' ),
			array( $this, 'field_api_key' ),
			'toptex-settings',
			'toptex_api'
		);

		add_settings_field(
			'api_username',
			\__( 'API username', 'toptex-woocommerce' ),
			array( $this, 'field_api_username' ),
			'toptex-settings',
			'toptex_api'
		);

		add_settings_field(
			'api_password',
			\__( 'API password', 'toptex-woocommerce' ),
			array( $this, 'field_api_password' ),
			'toptex-settings',
			'toptex_api'
		);

		add_settings_field(
			'usage_right',
			\__( 'Usage right', 'toptex-woocommerce' ),
			array( $this, 'field_usage_right' ),
			'toptex-settings',
			'toptex_api'
		);

		add_settings_field(
			'language',
			\__( 'Language', 'toptex-woocommerce' ),
			array( $this, 'field_language' ),
			'toptex-settings',
			'toptex_api'
		);

		add_settings_section(
			'toptex_general',
			\__( 'Catalog import', 'toptex-woocommerce' ),
			'__return_false',
			'toptex-settings'
		);

		add_settings_field(
			'import_scope',
			\__( 'Import scope', 'toptex-woocommerce' ),
			array( $this, 'field_import_scope' ),
			'toptex-settings',
			'toptex_general'
		);

		add_settings_field(
			'import_references',
			\__( 'Catalog references', 'toptex-woocommerce' ),
			array( $this, 'field_import_references' ),
			'toptex-settings',
			'toptex_general'
		);

		add_settings_field(
			'per_page',
			\__( 'First N products', 'toptex-woocommerce' ),
			array( $this, 'field_per_page' ),
			'toptex-settings',
			'toptex_general'
		);

		add_settings_field(
			'markup_percent',
			\__( 'Price markup (%)', 'toptex-woocommerce' ),
			array( $this, 'field_markup' ),
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

		if ( isset( $input['api_key'] ) ) {
			$out['api_key'] = sanitize_text_field( $input['api_key'] );
		}

		if ( isset( $input['api_username'] ) ) {
			$out['api_username'] = sanitize_text_field( $input['api_username'] );
		}

		if ( isset( $input['api_password'] ) && ! empty( $input['api_password'] ) ) {
			// Preserve the password as-is; don't sanitize/re-encode credentials.
			$out['api_password'] = (string) $input['api_password'];
		} else {
			// Keep the existing password if the field is left blank.
			$existing            = $this->get_options();
			$out['api_password'] = isset( $existing['api_password'] ) ? $existing['api_password'] : '';
		}

		if ( isset( $input['usage_right'] ) && in_array( $input['usage_right'], array( 'b2b_uniquement', 'b2c_uniquement', 'b2b_b2c' ), true ) ) {
			$out['usage_right'] = sanitize_key( $input['usage_right'] );
		}

		if ( isset( $input['language'] ) && in_array( $input['language'], array( 'de', 'en', 'es', 'fr', 'it', 'nl', 'pt' ), true ) ) {
			$out['language'] = sanitize_key( $input['language'] );
		}

		if ( isset( $input['import_scope'] ) && in_array( $input['import_scope'], array( 'all', 'selection', 'per_page' ), true ) ) {
			$out['import_scope'] = sanitize_key( $input['import_scope'] );
		}

		if ( isset( $input['import_references'] ) ) {
			$out['import_references'] = $this->sanitize_references( $input['import_references'] );
		}

		if ( isset( $input['per_page'] ) ) {
			$out['per_page'] = max( 1, min( 200, absint( $input['per_page'] ) ) );
		}

		if ( isset( $input['markup_percent'] ) ) {
			$out['markup_percent'] = min( 1000, max( 0, (float) $input['markup_percent'] ) );
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
	 * Normalizes a whitespace/comma/newline-separated list of references.
	 *
	 * @param string $raw Raw list.
	 * @return string Comma-separated, normalized references.
	 */
	private function sanitize_references( $raw ) {
		$refs = preg_split( '/[\s,]+/', (string) $raw );
		$refs = array_map(
			function ( $ref ) {
				return strtoupper( sanitize_text_field( $ref ) );
			},
			(array) $refs
		);
		$refs = array_values( array_filter( $refs ) );
		$refs = array_unique( $refs );

		return implode( ',', $refs );
	}

	/**
	 * API key field.
	 *
	 * @return void
	 */
	public function field_api_key() {
		$opts = $this->get_options();
		?>
		<input type="text" class="regular-text code" name="<?php echo esc_attr( self::OPTION_NAME . '[api_key]' ); ?>" value="<?php echo esc_attr( $opts['api_key'] ); ?>" />
		<p class="description"><?php esc_html_e( 'Your TopTex partner API key (from portal.toptex.io).', 'toptex-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * API username field.
	 *
	 * @return void
	 */
	public function field_api_username() {
		$opts = $this->get_options();
		?>
		<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME . '[api_username]' ); ?>" value="<?php echo esc_attr( $opts['api_username'] ); ?>" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Username used to obtain the OIDC token.', 'toptex-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * API password field.
	 *
	 * @return void
	 */
	public function field_api_password() {
		$opts = $this->get_options();
		?>
		<input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME . '[api_password]' ); ?>" value="<?php echo esc_attr( $opts['api_password'] ); ?>" autocomplete="new-password" />
		<p class="description"><?php esc_html_e( 'Password used to obtain the OIDC token. Leave blank to keep the existing value.', 'toptex-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * Usage-right selector.
	 *
	 * @return void
	 */
	public function field_usage_right() {
		$opts    = $this->get_options();
		$choices = array(
			'b2b_b2c'        => \__( 'B2B + B2C', 'toptex-woocommerce' ),
			'b2b_uniquement' => \__( 'B2B only', 'toptex-woocommerce' ),
			'b2c_uniquement' => \__( 'B2C only', 'toptex-woocommerce' ),
		);
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[usage_right]' ); ?>">
			<?php foreach ( $choices as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $opts['usage_right'], $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Which catalog subset your license allows you to resell.', 'toptex-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * Language selector.
	 *
	 * @return void
	 */
	public function field_language() {
		$opts    = $this->get_options();
		$choices = array(
			'en' => \__( 'English', 'toptex-woocommerce' ),
			'fr' => \__( 'French', 'toptex-woocommerce' ),
			'de' => \__( 'German', 'toptex-woocommerce' ),
			'es' => \__( 'Spanish', 'toptex-woocommerce' ),
			'it' => \__( 'Italian', 'toptex-woocommerce' ),
			'nl' => \__( 'Dutch', 'toptex-woocommerce' ),
			'pt' => \__( 'Portuguese', 'toptex-woocommerce' ),
		);
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[language]' ); ?>">
			<?php foreach ( $choices as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $opts['language'], $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Language used for imported names, descriptions and categories.', 'toptex-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * Import scope selector.
	 *
	 * @return void
	 */
	public function field_import_scope() {
		$opts    = $this->get_options();
		$choices = array(
			'all'       => \__( 'Full catalog', 'toptex-woocommerce' ),
			'selection' => \__( 'Selected references only', 'toptex-woocommerce' ),
			'per_page'  => \__( 'First N products', 'toptex-woocommerce' ),
		);
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[import_scope]' ); ?>">
			<?php foreach ( $choices as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $opts['import_scope'], $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Import everything, a selected subset of references, or only the first N products (great for testing).', 'toptex-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * Import references field (selection scope).
	 *
	 * @return void
	 */
	public function field_import_references() {
		$opts = $this->get_options();
		?>
		<textarea class="large-text" rows="4" name="<?php echo esc_attr( self::OPTION_NAME . '[import_references]' ); ?>" placeholder="<?php esc_attr_e( 'B610, B050, IB323', 'toptex-woocommerce' ); ?>"><?php echo esc_textarea( $opts['import_references'] ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Comma-separated catalog references to import (used when scope is "Selected references only").', 'toptex-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * "First N products" count field (per-page scope).
	 *
	 * @return void
	 */
	public function field_per_page() {
		$opts = $this->get_options();
		?>
		<input type="number" step="1" min="1" max="200" name="<?php echo esc_attr( self::OPTION_NAME . '[per_page]' ); ?>" value="<?php echo esc_attr( $opts['per_page'] ); ?>" />
		<p class="description"><?php esc_html_e( 'How many products to import when the scope is "First N products" (1–200).', 'toptex-woocommerce' ); ?></p>
		<?php
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

			<?php $this->render_connection_notices(); ?>

			<div class="notice notice-info">
				<p><?php esc_html_e( 'This plugin imports the TopTex catalog via the official TopTex v3 API. Each style becomes a WooCommerce variable product with Color and Size attributes. Live dealer prices and stock are pulled from the API and a configurable markup is applied.', 'toptex-woocommerce' ); ?></p>
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

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="toptex-inline-form">
				<input type="hidden" name="action" value="toptex_run_sync" />
				<?php wp_nonce_field( 'toptex_run_sync' ); ?>
				<?php submit_button( \__( 'Run import now', 'toptex-woocommerce' ), 'primary', 'toptex_sync_submit' ); ?>
			</form>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="toptex-inline-form">
				<input type="hidden" name="action" value="toptex_test_connection" />
				<?php wp_nonce_field( 'toptex_test_connection' ); ?>
				<?php submit_button( \__( 'Test connection', 'toptex-woocommerce' ), 'secondary', 'toptex_test_submit' ); ?>
			</form>

			<?php $this->render_diagnostic_report(); ?>
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
	 * Renders connection/error notices at the top of the settings page.
	 *
	 * @return void
	 */
	private function render_connection_notices() {
		// Transient set by a just-failed manual sync.
		$manual_error = get_transient( 'toptex_sync_error' );
		if ( $manual_error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $manual_error ) . '</p></div>';
		}

		// Transient set by a just-run connection test.
		$test_result = get_transient( 'toptex_test_result' );
		if ( is_array( $test_result ) && isset( $test_result['ok'] ) ) {
			$class = $test_result['ok'] ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $test_result['message'] ) . '</p></div>';
		}

		// Fatal error from the last import run.
		$status = get_option( 'toptex_last_sync', array() );
		if ( ! empty( $status['diagnostic']['fatal'] ) ) {
			$fatal = $status['diagnostic']['fatal'];
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'The last import failed:', 'toptex-woocommerce' ) . '</strong> ' . esc_html( isset( $fatal['message'] ) ? $fatal['message'] : '' ) . '</p><p>' . esc_html__( 'See the diagnostic report below and use "Test connection" to check your API credentials.', 'toptex-woocommerce' ) . '</p></div>';
		}
	}

	/**
	 * Renders the copyable diagnostic report block.
	 *
	 * @return void
	 */
	private function render_diagnostic_report() {
		$report = $this->build_diagnostic_report();
		?>
		<h2><?php esc_html_e( 'Diagnostic report', 'toptex-woocommerce' ); ?></h2>
		<p><?php esc_html_e( 'If something is not working, copy this report and send it along with your support request. It contains no secrets (your password is never included and the API key is masked).', 'toptex-woocommerce' ); ?></p>
		<textarea id="toptex-diagnostic-report" class="large-text code" rows="16" readonly onclick="this.select();"><?php echo esc_textarea( $report ); ?></textarea>
		<p>
			<button type="button" class="button" data-toptex-copy="toptex-diagnostic-report"><?php esc_html_e( 'Copy report', 'toptex-woocommerce' ); ?></button>
		</p>
		<?php
	}

	/**
	 * Builds a redacted diagnostic report string.
	 *
	 * @return string
	 */
	private function build_diagnostic_report() {
		$settings = $this->get_options();

		$lines   = array();
		$lines[] = '### TopTex for WooCommerce diagnostic report';
		$lines[] = '';

		// Environment.
		$lines[] = '- Plugin version: ' . ( defined( 'TopTexWooCommerce\\TOP_TEX_VERSION' ) ? \TopTexWooCommerce\TOP_TEX_VERSION : 'unknown' );
		$lines[] = '- WordPress: ' . get_bloginfo( 'version' );
		$lines[] = '- WooCommerce: ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown' );
		$lines[] = '- PHP: ' . PHP_VERSION;
		$lines[] = '- Site: ' . home_url();

		// API config (redacted).
		$api_key = isset( $settings['api_key'] ) ? (string) $settings['api_key'] : '';
		$masked  = '' === $api_key ? '(not set)' : $this->mask_key( $api_key );
		$lines[] = '- API key: ' . $masked;
		$lines[] = '- Username: ' . ( isset( $settings['api_username'] ) && '' !== $settings['api_username'] ? $settings['api_username'] : '(not set)' );
		$lines[] = '- Usage right: ' . ( isset( $settings['usage_right'] ) ? $settings['usage_right'] : 'b2b_b2c' );
		$lines[] = '- Language: ' . ( isset( $settings['language'] ) ? $settings['language'] : 'en' );
		$lines[] = '- Import scope: ' . ( isset( $settings['import_scope'] ) ? $settings['import_scope'] : 'all' );
		$lines[] = '';

		// Last sync.
		$status = get_option( 'toptex_last_sync', array() );
		if ( empty( $status['time'] ) ) {
			$lines[] = '- Last import: never';
		} else {
			$lines[] = '- Last import: ' . gmdate( 'Y-m-d H:i:s', (int) $status['time'] ) . ' UTC';
			if ( ! empty( $status['result'] ) ) {
				$r       = $status['result'];
				$lines[] = '- Imported: ' . (int) $r['imported'] . ', Updated: ' . (int) $r['updated'] . ', Errors: ' . (int) $r['errors'];
			}
			if ( ! empty( $status['diagnostic']['fatal'] ) ) {
				$f       = $status['diagnostic']['fatal'];
				$lines[] = '- Last error: [' . $f['code'] . '] ' . $f['message'] . ( ! empty( $f['http_code'] ) ? ' (HTTP ' . $f['http_code'] . ')' : '' );
			}
			if ( ! empty( $status['diagnostic']['failures'] ) ) {
				$lines[] = '- Product failures:';
				foreach ( $status['diagnostic']['failures'] as $fail ) {
					$lines[] = '    - ' . $fail['reference'] . ': ' . $fail['message'];
				}
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Masks an API key, showing only the last four characters.
	 *
	 * @param string $key API key.
	 * @return string
	 */
	private function mask_key( $key ) {
		if ( strlen( $key ) <= 4 ) {
			return '****';
		}
		return '****' . substr( $key, -4 );
	}

	/**
	 * Handles the "test connection" action.
	 *
	 * @return void
	 */
	public function handle_test_connection() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'toptex-woocommerce' ) );
		}

		check_admin_referer( 'toptex_test_connection' );

		$client = new Client();
		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			set_transient(
				'toptex_test_result',
				array(
					'ok'      => false,
					'message' => $result->get_error_message(),
				),
				60
			);
		} else {
			$total = isset( $result['total_count'] ) ? (int) $result['total_count'] : 0;
			set_transient(
				'toptex_test_result',
				array(
					'ok'      => true,
					/* translators: 1: total product count. */
					'message' => sprintf( __( 'Connected to the TopTex API. Catalog contains %1$d products.', 'toptex-woocommerce' ), $total ),
				),
				60
			);
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=toptex-settings' ) );
		exit;
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
