<?php
/**
 * Focused regression tests for bma_us_save_last_check().
 *
 * Run with: php tests/test-last-check.php
 */

define( 'BMA_US_LAST_CHECK_OPTION', 'bma_us_last_update_check' );

$bma_test_options = array();

function sanitize_key( $value ) {
    return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_text_field( $value ) {
    return trim( strip_tags( (string) $value ) );
}

function absint( $value ) {
    return abs( (int) $value );
}

function update_option( $name, $value, $autoload = null ) {
    global $bma_test_options;
    unset( $autoload );
    $bma_test_options[ $name ] = $value;
    return true;
}

function bma_test_load_function() {
    $source = file_get_contents( dirname( __DIR__ ) . '/includes/scheduler.php' );
    $start  = strpos( $source, 'function bma_us_save_last_check' );
    if ( false === $start ) {
        throw new RuntimeException( 'bma_us_save_last_check() was not found.' );
    }

    $brace = strpos( $source, '{', $start );
    $depth = 0;
    $end   = null;
    $size  = strlen( $source );
    for ( $index = $brace; $index < $size; $index++ ) {
        if ( '{' === $source[ $index ] ) {
            $depth++;
        } elseif ( '}' === $source[ $index ] ) {
            $depth--;
            if ( 0 === $depth ) {
                $end = $index + 1;
                break;
            }
        }
    }
    if ( null === $end ) {
        throw new RuntimeException( 'bma_us_save_last_check() could not be parsed.' );
    }

    eval( substr( $source, $start, $end - $start ) );
}

function bma_test_assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        throw new RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
    }
}

function bma_test_run( $name, $callback ) {
    try {
        $callback();
        echo "PASS: {$name}\n";
    } catch ( Throwable $error ) {
        echo "FAIL: {$name}\n{$error->getMessage()}\n";
        exit( 1 );
    }
}

bma_test_load_function();

bma_test_run(
    'empty scheduled check is stored as up to date',
    function () {
        global $bma_test_options;
        bma_us_save_last_check( 'scheduled', 'no_updates', 'No eligible updates were available.' );
        $saved = $bma_test_options[ BMA_US_LAST_CHECK_OPTION ];
        bma_test_assert_same( 'scheduled', $saved['trigger'], 'The trigger should be retained.' );
        bma_test_assert_same( 'no_updates', $saved['status'], 'The no-updates status should be retained.' );
        bma_test_assert_same( 0, $saved['success'], 'An empty check should not report successful updates.' );
    }
);

bma_test_run(
    'result counts are normalized',
    function () {
        global $bma_test_options;
        bma_us_save_last_check( 'manual', 'updated', 'Updates installed.', array( 'success' => '2', 'failed' => '-1', 'skipped' => '3', 'blocked' => 0 ) );
        $saved = $bma_test_options[ BMA_US_LAST_CHECK_OPTION ];
        bma_test_assert_same( 2, $saved['success'], 'Success should be stored as an integer.' );
        bma_test_assert_same( 1, $saved['failed'], 'Counts should be normalized with absint().' );
        bma_test_assert_same( 3, $saved['skipped'], 'Skipped should be stored as an integer.' );
    }
);

bma_test_run(
    'unknown status falls back to checked',
    function () {
        global $bma_test_options;
        bma_us_save_last_check( 'manual', 'unexpected status', '<b>Finished</b>' );
        $saved = $bma_test_options[ BMA_US_LAST_CHECK_OPTION ];
        bma_test_assert_same( 'checked', $saved['status'], 'Unknown status should use the safe fallback.' );
        bma_test_assert_same( 'Finished', $saved['message'], 'The message should be sanitized.' );
    }
);

echo "All last-check tests passed.\n";
