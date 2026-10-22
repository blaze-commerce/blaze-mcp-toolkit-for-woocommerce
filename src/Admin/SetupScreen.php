<?php
/**
 * WooCommerce > Blaze MCP Toolkit setup screen.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Admin;

use Blaze\McpToolkit\Audit\AuditLog;
use Blaze\McpToolkit\Bundled\Commerce;
use Blaze\McpToolkit\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Plain WP admin markup: checks, connector URL, one setting, recent tool calls. No assets.
 */
final class SetupScreen {

	public const SLUG         = 'blzmcp-setup';
	public const ACTION       = 'blzmcp_save_setup';
	public const NONCE_ACTION = 'blzmcp_save_setup';
	public const NONCE_FIELD  = 'blzmcp_setup_nonce';

	/**
	 * Hook the menu and the save handler (admin only).
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_post' ) );
	}

	/**
	 * Add the submenu under WooCommerce.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Blaze MCP Toolkit', 'blaze-mcp-toolkit-for-woocommerce' ),
			__( 'Blaze MCP Toolkit', 'blaze-mcp-toolkit-for-woocommerce' ),
			'manage_woocommerce',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Save the settings from a posted form. Needs manage_options and a valid nonce.
	 *
	 * @param array<string, mixed> $post Posted fields (slashed, as in $_POST).
	 * @return bool Whether the setting was saved.
	 */
	public static function save( array $post ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$nonce = isset( $post[ self::NONCE_FIELD ] ) && is_string( $post[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $post[ self::NONCE_FIELD ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return false;
		}

		Settings::update( array( 'shop_manager_diagnostics' => ! empty( $post['shop_manager_diagnostics'] ) ) );
		return true;
	}

	/**
	 * admin-post.php handler: save, then go back to the screen.
	 */
	public static function handle_post(): void {
		// Only the two fields the form sends; the nonce and capability are checked inside save().
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in save() before anything is stored.
		$fields = array(
			self::NONCE_FIELD          => isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '',
			'shop_manager_diagnostics' => ! empty( $_POST['shop_manager_diagnostics'] ),
		);
		// phpcs:enable
		$saved = self::save( $fields );
		wp_safe_redirect( add_query_arg( 'blzmcp_saved', $saved ? '1' : '0', admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'blaze-mcp-toolkit-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Blaze MCP Toolkit for WooCommerce', 'blaze-mcp-toolkit-for-woocommerce' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		if ( isset( $_GET['blzmcp_saved'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '1' === sanitize_text_field( wp_unslash( $_GET['blzmcp_saved'] ) ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'blaze-mcp-toolkit-for-woocommerce' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Settings were not saved. Only administrators can change them, and the page may have expired. Reload and try again.', 'blaze-mcp-toolkit-for-woocommerce' ) . '</p></div>';
			}
		}

		echo '<p>' . esc_html__( 'Connect Claude or ChatGPT to your store so you can ask questions about orders, products and store health. The tools in this plugin are read-only and never return customer names, emails, phone numbers or addresses from your orders.', 'blaze-mcp-toolkit-for-woocommerce' ) . '</p>';

		if ( Commerce::is_standing_aside() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'WooCommerce for Claude is active, so the analytics and store tools come from it and this plugin does not add its own copies. Our store health, update history, access check and workflow tools still work.', 'blaze-mcp-toolkit-for-woocommerce' ) . '</p></div>';
		}

		self::render_checks();
		self::render_connector();
		self::render_settings();
		self::render_audit();

		echo '<p class="description">' . esc_html__( 'WooCommerce is a trademark of Automattic Inc.', 'blaze-mcp-toolkit-for-woocommerce' ) . '</p></div>';
	}

	/**
	 * Checks table.
	 */
	private static function render_checks(): void {
		$labels = array(
			'pass' => array( __( 'OK', 'blaze-mcp-toolkit-for-woocommerce' ), 'dashicons-yes-alt' ),
			'warn' => array( __( 'Check', 'blaze-mcp-toolkit-for-woocommerce' ), 'dashicons-warning' ),
			'fail' => array( __( 'Problem', 'blaze-mcp-toolkit-for-woocommerce' ), 'dashicons-dismiss' ),
		);

		echo '<h2>' . esc_html__( 'Setup checks', 'blaze-mcp-toolkit-for-woocommerce' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Status', 'blaze-mcp-toolkit-for-woocommerce' ) . '</th><th scope="col">' . esc_html__( 'Check', 'blaze-mcp-toolkit-for-woocommerce' ) . '</th><th scope="col">' . esc_html__( 'What to do', 'blaze-mcp-toolkit-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( SetupChecks::run() as $check ) {
			$status = isset( $labels[ $check['status'] ] ) ? $check['status'] : 'warn';
			printf(
				'<tr><td><span class="dashicons %1$s" aria-hidden="true"></span> %2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				esc_attr( $labels[ $status ][1] ),
				esc_html( $labels[ $status ][0] ),
				esc_html( $check['label'] ),
				esc_html( $check['fix'] )
			);
		}
		echo '</tbody></table>';
		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( SetupChecks::enable_abilities_url() ),
			esc_html__( 'Open Enable Abilities settings (Settings > WP Abilities)', 'blaze-mcp-toolkit-for-woocommerce' )
		);
	}

	/**
	 * Connector URL and how to add it.
	 */
	private static function render_connector(): void {
		echo '<h2>' . esc_html__( 'Connector URL', 'blaze-mcp-toolkit-for-woocommerce' ) . '</h2>';
		printf( '<p><input type="text" class="large-text code" readonly="readonly" aria-label="%1$s" value="%2$s" /></p>', esc_attr__( 'Connector URL', 'blaze-mcp-toolkit-for-woocommerce' ), esc_attr( rest_url( 'mcp/mcp-oauth-server' ) ) );
		echo '<ul class="ul-disc">';
		echo '<li>' . esc_html__( 'Claude: Customize > Connectors > Add > Add custom connector, then paste the URL.', 'blaze-mcp-toolkit-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'ChatGPT: Plugins > Add > Add custom MCP server, then paste the URL. In our testing this worked on a Free ChatGPT account.', 'blaze-mcp-toolkit-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'Log in to wp-admin before you click Connect, and click Allow once.', 'blaze-mcp-toolkit-for-woocommerce' ) . '</li>';
		echo '</ul>';
	}

	/**
	 * The one setting. Only administrators see and can change it.
	 */
	private static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Settings', 'blaze-mcp-toolkit-for-woocommerce' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		printf(
			'<p><label><input type="checkbox" name="shop_manager_diagnostics" value="1" %1$s /> %2$s</label></p><p class="description">%3$s</p>',
			checked( Settings::shop_manager_diagnostics(), true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core helper returns a safe attribute.
			esc_html__( 'Allow Shop Managers to see diagnostics', 'blaze-mcp-toolkit-for-woocommerce' ),
			esc_html__( 'Lets Shop Managers use the store health and update history tools through their AI connection. Administrators always can.', 'blaze-mcp-toolkit-for-woocommerce' )
		);
		submit_button();
		echo '</form>';
	}

	/**
	 * Last 50 tool calls. No names or emails: the role only.
	 */
	private static function render_audit(): void {
		echo '<h2>' . esc_html__( 'Recent tool calls', 'blaze-mcp-toolkit-for-woocommerce' ) . '</h2>';
		$rows = AuditLog::recent( 50 );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No tool calls yet.', 'blaze-mcp-toolkit-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Time', 'blaze-mcp-toolkit-for-woocommerce' ), __( 'Role', 'blaze-mcp-toolkit-for-woocommerce' ), __( 'Tool', 'blaze-mcp-toolkit-for-woocommerce' ), __( 'Rows', 'blaze-mcp-toolkit-for-woocommerce' ), __( 'ms', 'blaze-mcp-toolkit-for-woocommerce' ), __( 'Status', 'blaze-mcp-toolkit-for-woocommerce' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$roles = array();
		foreach ( $rows as $row ) {
			$uid = $row['user_id'];
			if ( ! isset( $roles[ $uid ] ) ) {
				$roles[ $uid ] = self::role_label( $uid );
			}
			$time = strtotime( $row['created_at'] . ' UTC' );
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td><code>%3$s</code></td><td>%4$d</td><td>%5$d</td><td>%6$s</td></tr>',
				esc_html( false === $time ? $row['created_at'] : wp_date( 'Y-m-d H:i:s', $time ) ),
				esc_html( $roles[ $uid ] ),
				esc_html( $row['ability'] ),
				(int) $row['row_count'],
				(int) $row['duration_ms'],
				esc_html( $row['status'] )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Role slug for a user id; never a name or email.
	 *
	 * @param int $user_id User id from the audit log.
	 */
	private static function role_label( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return 'system';
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return 'unknown';
		}
		$role = reset( $user->roles );
		return is_string( $role ) && '' !== $role ? $role : 'unknown';
	}
}
