<?php
/**
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Tests\Unit;

use Blaze\McpToolkit\Audit\AuditLog;
use PHPUnit\Framework\TestCase;

final class AuditLogHashTest extends TestCase {

	public function test_params_hash_is_sha256_and_input_not_stored(): void {
		$hash = AuditLog::hash_params( array( 'period' => 'last_7_days' ) );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
		$this->assertStringNotContainsString( 'last_7_days', $hash );
		$this->assertSame(
			AuditLog::hash_params( array( 'a' => 1, 'b' => 2 ) ),
			AuditLog::hash_params( array( 'b' => 2, 'a' => 1 ) ),
			'Key order must not change the hash.'
		);
	}

	public function test_params_hash_sorts_nested_keys(): void {
		$this->assertSame(
			AuditLog::hash_params( array( 'x' => array( 'a' => 1, 'b' => array( 'c' => 3, 'd' => 4 ) ) ) ),
			AuditLog::hash_params( array( 'x' => array( 'b' => array( 'd' => 4, 'c' => 3 ), 'a' => 1 ) ) )
		);
		$this->assertNotSame(
			AuditLog::hash_params( array( 'period' => 'last_7_days' ) ),
			AuditLog::hash_params( array( 'period' => 'last_30_days' ) )
		);
	}

	public function test_normalise_keeps_list_order(): void {
		$this->assertSame( array( 'b', 'a' ), AuditLog::normalise( array( 'b', 'a' ) ) );
	}
}
