<?php
/**
 * NexPay admin: a "NexPay" submenu page under WooCommerce that shows every
 * gateway grouped by country — enabled state and Live/Sandbox mode are read
 * live from each gateway's saved WooCommerce settings, and each row links
 * straight to that gateway's WooCommerce settings section.
 *
 * The layout follows the NexPay design: purple gradient header band, white
 * cards, country squares, Live/Sandbox pills and violet Configure buttons.
 *
 * @package NexPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * NexPay admin overview page.
 */
class NexPay_Admin {

	/**
	 * Menu page hook suffix (set when the submenu is registered).
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Wire up the admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add the NexPay submenu under the WooCommerce menu.
	 */
	public static function register_menu() {
		self::$hook = add_submenu_page(
			'woocommerce',
			__( 'NexPay — Payment Gateways by Country', 'nexpay' ),
			__( 'NexPay', 'nexpay' ),
			'manage_woocommerce',
			'nexpay',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue the NexPay admin stylesheet on the NexPay page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page check.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'nexpay' !== $page && self::$hook !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'nexpay-admin',
			NEXPAY_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			NEXPAY_VERSION
		);
	}

	/**
	 * Read one gateway's saved WooCommerce settings.
	 *
	 * @param string $gateway_id Gateway id, e.g. "nexpay_jazzcash".
	 * @return array
	 */
	private static function gateway_settings( $gateway_id ) {
		$settings = get_option( 'woocommerce_' . $gateway_id . '_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Render the overview page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'nexpay' ) );
		}

		$gateways = nexpay_gateway_meta();
		$docs_url = 'https://github.com/mrdigitalceo/nexpay#readme';
		?>
		<div class="wrap nexpay-admin">

			<div class="nexpay-band">
				<div class="nexpay-band__globe" aria-hidden="true">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.7"><circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4.2" ry="9"/><path d="M3.5 9h17M3.5 15h17"/></svg>
				</div>
				<h1 class="nexpay-band__title"><?php esc_html_e( 'NexPay — Payment Gateways by Country', 'nexpay' ); ?></h1>
				<a class="nexpay-band__docs" href="<?php echo esc_url( $docs_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Docs', 'nexpay' ); ?></a>
				<span class="nexpay-band__version"><?php echo esc_html( sprintf( __( 'Version %s', 'nexpay' ), NEXPAY_VERSION ) ); ?></span>
			</div>

			<p class="nexpay-desc"><?php esc_html_e( 'Manage and configure payment gateways for each country. Enable a gateway and enter its credentials from its Configure screen — the state below is read live from WooCommerce settings.', 'nexpay' ); ?></p>

			<div class="nexpay-card">
				<h2 class="nexpay-card__title"><?php esc_html_e( 'Payment Gateways', 'nexpay' ); ?></h2>
				<table class="nexpay-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Gateway', 'nexpay' ); ?></th>
							<th><?php esc_html_e( 'Country', 'nexpay' ); ?></th>
							<th><?php esc_html_e( 'Enabled', 'nexpay' ); ?></th>
							<th><?php esc_html_e( 'Mode', 'nexpay' ); ?></th>
							<th><?php esc_html_e( 'Action', 'nexpay' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $gateways as $gateway_id => $meta ) : ?>
						<?php
						$settings = self::gateway_settings( $gateway_id );
						$enabled  = isset( $settings['enabled'] ) && 'yes' === $settings['enabled'];
						$testmode = ! isset( $settings['testmode'] ) || 'yes' === $settings['testmode'];
						$config_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $gateway_id );
						?>
						<tr>
							<td class="nexpay-table__gateway">
								<span class="nexpay-badge nexpay-badge--<?php echo esc_attr( $gateway_id ); ?>"><?php echo esc_html( $meta['title'] ); ?></span>
							</td>
							<td>
								<span class="nexpay-cc nexpay-cc--<?php echo esc_attr( strtolower( $meta['code'] ) ); ?>"><?php echo esc_html( $meta['code'] ); ?></span>
								<?php echo esc_html( $meta['country'] ); ?>
							</td>
							<td>
								<span class="nexpay-toggle <?php echo $enabled ? 'is-on' : 'is-off'; ?>" role="img" aria-label="<?php echo esc_attr( $enabled ? __( 'Enabled', 'nexpay' ) : __( 'Disabled', 'nexpay' ) ); ?>"></span>
								<?php echo esc_html( $enabled ? __( 'ON', 'nexpay' ) : __( 'OFF', 'nexpay' ) ); ?>
							</td>
							<td>
								<?php if ( $testmode ) : ?>
									<span class="nexpay-mode nexpay-mode--sandbox">● <?php esc_html_e( 'Sandbox', 'nexpay' ); ?></span>
								<?php else : ?>
									<span class="nexpay-mode nexpay-mode--live">● <?php esc_html_e( 'Live', 'nexpay' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<a class="nexpay-configure" href="<?php echo esc_url( $config_url ); ?>"><?php esc_html_e( 'Configure', 'nexpay' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="nexpay-foot">
				<span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: plugin version */
							__( 'NexPay — Multi-Country Payments v%s · Connected to WooCommerce · Documentation', 'nexpay' ),
							NEXPAY_VERSION
						)
					);
					?>
				</span>
				<img class="nexpay-foot__logo" src="<?php echo esc_url( NEXPAY_PLUGIN_URL . 'assets/images/icon.png' ); ?>" alt="<?php esc_attr_e( 'NexPay', 'nexpay' ); ?>" width="28" height="28" />
			</div>

		</div>
		<?php
	}
}
