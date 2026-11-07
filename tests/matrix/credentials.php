<?php
/**
 * In-container credential helper for the real-plugin matrix (tests/matrix/http-suite.sh).
 *
 * Run from the host through wp-env, with the subcommand as positional arguments:
 *   wp eval-file wp-content/blzmcp-tests/matrix/credentials.php <command> [args]
 *
 * Commands:
 *   app-password create <login>       Prints "ID=<uuid>" and "SECRET=<password>" for Basic auth.
 *   app-password revoke <login> <id>  Deletes that application password.
 *   easy-token create <login>         Creates an Easy MCP AI API key through Easy MCP's own
 *                                     Token_Manager. Prints "ID=<token id>" and "SECRET=<raw key>".
 *   easy-token revoke <id>            Revokes the key through Token_Manager, then deletes its row.
 *   user ensure <login> <role>        Creates (or resets the role of) a matrix test user. Prints "ID=".
 *   user delete <login>               Deletes a matrix test user (only logins starting blzmcp_matrix_).
 *
 * The secret is printed once so the host can hold it in a shell variable. The caller never
 * writes it to a file or to the evidence record.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals

defined( 'ABSPATH' ) || exit;

$blzmcp_cmd = array_values( (array) ( $args ?? array() ) );
$blzmcp_die = static function ( string $message ): void {
	echo 'ERROR=' . $message . "\n";
	exit( 1 );
};
$blzmcp_user = static function ( string $login ) use ( $blzmcp_die ): WP_User {
	$user = get_user_by( 'login', $login );
	if ( ! $user instanceof WP_User ) {
		$blzmcp_die( 'no such user ' . $login );
	}
	return $user;
};

switch ( ( $blzmcp_cmd[0] ?? '' ) . ' ' . ( $blzmcp_cmd[1] ?? '' ) ) {
	case 'app-password create':
		$user = $blzmcp_user( (string) ( $blzmcp_cmd[2] ?? '' ) );
		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			$blzmcp_die( 'application passwords unavailable for ' . $user->user_login );
		}
		$created = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => 'blzmcp-matrix' ) );
		if ( is_wp_error( $created ) ) {
			$blzmcp_die( $created->get_error_code() );
		}
		echo 'ID=' . $created[1]['uuid'] . "\n";
		echo 'SECRET=' . $created[0] . "\n";
		break;

	case 'app-password revoke':
		$user    = $blzmcp_user( (string) ( $blzmcp_cmd[2] ?? '' ) );
		$deleted = WP_Application_Passwords::delete_application_password( $user->ID, (string) ( $blzmcp_cmd[3] ?? '' ) );
		if ( is_wp_error( $deleted ) ) {
			$blzmcp_die( $deleted->get_error_code() );
		}
		echo "REVOKED=1\n";
		break;

	case 'easy-token create':
		if ( ! class_exists( '\Easy_MCP_AI\Auth\Token_Manager' ) ) {
			$blzmcp_die( 'Easy MCP AI Token_Manager not loaded' );
		}
		$user    = $blzmcp_user( (string) ( $blzmcp_cmd[2] ?? '' ) );
		$created = ( new \Easy_MCP_AI\Auth\Token_Manager() )->create_token( 'blzmcp-matrix', $user->ID, array( '*' ) );
		if ( is_wp_error( $created ) || empty( $created['raw_token'] ) ) {
			$blzmcp_die( is_wp_error( $created ) ? $created->get_error_code() : 'no token' );
		}
		echo 'ID=' . (int) $created['id'] . "\n";
		echo 'SECRET=' . $created['raw_token'] . "\n";
		break;

	case 'easy-token revoke':
		if ( ! class_exists( '\Easy_MCP_AI\Auth\Token_Manager' ) ) {
			$blzmcp_die( 'Easy MCP AI Token_Manager not loaded' );
		}
		$manager = new \Easy_MCP_AI\Auth\Token_Manager();
		$blzmcp_tid = (int) ( $blzmcp_cmd[2] ?? 0 );
		$manager->revoke_token( $blzmcp_tid );
		$manager->delete_token( $blzmcp_tid );
		echo 'REVOKED=' . ( empty( $manager->get_token_by_id( $blzmcp_tid ) ) ? '1' : '0' ) . "\n";
		break;

	case 'user ensure':
		$login = (string) ( $blzmcp_cmd[2] ?? '' );
		$blzmcp_role = (string) ( $blzmcp_cmd[3] ?? '' );
		if ( ! str_starts_with( $login, 'blzmcp_matrix_' ) || null === get_role( $blzmcp_role ) ) {
			$blzmcp_die( 'bad login or role' );
		}
		$user = get_user_by( 'login', $login );
		$blzmcp_uid = $user instanceof WP_User ? $user->ID : wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 32 ),
				'user_email' => $login . '@example.invalid',
				'role'       => $blzmcp_role,
			)
		);
		if ( is_wp_error( $blzmcp_uid ) ) {
			$blzmcp_die( $blzmcp_uid->get_error_code() );
		}
		( new WP_User( $blzmcp_uid ) )->set_role( $blzmcp_role );
		echo 'ID=' . (int) $blzmcp_uid . "\n";
		break;

	case 'user delete':
		$login = (string) ( $blzmcp_cmd[2] ?? '' );
		$user  = get_user_by( 'login', $login );
		if ( str_starts_with( $login, 'blzmcp_matrix_' ) && $user instanceof WP_User ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user->ID );
		}
		echo "DELETED=1\n";
		break;

	default:
		$blzmcp_die( 'unknown command' );
}
