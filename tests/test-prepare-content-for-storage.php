<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/../' );
}

require_once __DIR__ . '/../includes/class-wc-api-auditor-logger.php';

$logger = WC_API_Auditor_Logger::get_instance();
$ref    = new ReflectionClass( $logger );

$method_prepare = $ref->getMethod( 'prepare_content_for_storage' );
$method_prepare->setAccessible( true );

$method_hash_sample = $ref->getMethod( 'get_hash_sample_length' );
$method_hash_sample->setAccessible( true );

function assert_equals( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        fwrite( STDERR, "Assertion failed: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
        exit( 1 );
    }
}

function assert_contains( $needle, $haystack, $message ) {
    if ( false === strpos( $haystack, $needle ) ) {
        fwrite( STDERR, "Assertion failed: {$message}\nMissing: {$needle}\n" );
        exit( 1 );
    }
}

// Large payload is truncated and annotation reports partial hashing.
$limit            = 1000;
$hash_sample      = $method_hash_sample->invoke( $logger, $limit );
$large_payload    = str_repeat( 'abcdef', 2000 ); // 12,000 characters.
$expected_prefix  = substr( $large_payload, 0, $limit );
$expected_hash    = hash( 'sha256', substr( $large_payload, 0, $hash_sample ) );

$result = $method_prepare->invoke( $logger, $large_payload, $limit );

assert_contains( '[TRUNCADO]', $result, 'Truncation marker should be present.' );
assert_contains( $expected_hash, $result, 'Hash should be calculated from truncated fragment.' );
assert_contains( 'Hash calculado sobre los primeros ' . $hash_sample . ' caracteres.', $result, 'Annotation should mention hash scope.' );
assert_equals( $expected_prefix, substr( $result, 0, $limit ), 'Stored content should start with truncated payload.' );

// Content shorter than limit remains untouched.
$short_payload = 'short';
$stored_short  = $method_prepare->invoke( $logger, $short_payload, $limit );
assert_equals( $short_payload, $stored_short, 'Short content should not be modified.' );

echo "All assertions passed.\n";
