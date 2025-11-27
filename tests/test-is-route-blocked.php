<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/../' );
}

require_once __DIR__ . '/../includes/class-wc-api-auditor-logger.php';

$logger  = WC_API_Auditor_Logger::get_instance();
$ref     = new ReflectionClass( $logger );
$method  = $ref->getMethod( 'is_route_blocked' );
$method->setAccessible( true );

function assert_true( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "Assertion failed: {$message}\n" );
        exit( 1 );
    }
}

function assert_false( $condition, $message ) {
    assert_true( ! $condition, $message );
}

assert_true(
    $method->invoke( $logger, '/wp/v2/users/1', array( '/wp/v2/users/*' ) ),
    'Wildcard pattern should match nested user route.'
);

assert_true(
    $method->invoke( $logger, '/wp/v2/users', array( '/wp/v2/users/*' ) ),
    'Trailing wildcard should also match base prefix without ID.'
);

assert_true(
    $method->invoke( $logger, '/wc/v1/items', array( '/wc/*/items' ) ),
    'Wildcard inside pattern should translate to regex equivalent.'
);

assert_false(
    $method->invoke( $logger, '/wc/v1/items', array( '/wc/v2/items' ) ),
    'Non matching pattern should not block route.'
);

echo "All assertions passed.\n";
