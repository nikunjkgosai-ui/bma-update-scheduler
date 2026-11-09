<?php
/**
 * Focused regression tests for bma_us_reschedule_event().
 *
 * Run with: php tests/test-reschedule-event.php
 */

define( 'BMA_US_CRON_HOOK', 'bma_us_scheduled_update_event' );

final class WP_Error {
    private $code;
    private $message;

    public function __construct( $code, $message ) {
        $this->code    = $code;
        $this->message = $message;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }
}

$bma_test_state = array();

function bma_test_reset( $events, $target ) {
    global $bma_test_state;
    $bma_test_state = array(
        'events'            => array_values( $events ),
        'target'            => $target,
        'schedule_failures' => array(),
        'clear_failure'     => false,
        'calls'             => array(),
        'options'           => array(),
        'alerts'            => array(),
    );
    sort( $bma_test_state['events'], SORT_NUMERIC );
}

function is_wp_error( $value ) {
    return $value instanceof WP_Error;
}

function wp_next_scheduled( $hook, $args = array() ) {
    global $bma_test_state;
    unset( $hook, $args );
    return empty( $bma_test_state['events'] ) ? false : $bma_test_state['events'][0];
}

function wp_clear_scheduled_hook( $hook, $args = array(), $wp_error = false ) {
    global $bma_test_state;
    unset( $hook, $args, $wp_error );
    $bma_test_state['calls'][] = 'clear';
    if ( $bma_test_state['clear_failure'] ) {
        return new WP_Error( 'clear_failed', 'The existing event could not be removed.' );
    }
    $count                    = count( $bma_test_state['events'] );
    $bma_test_state['events'] = array();
    return $count;
}

function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
    global $bma_test_state;
    unset( $hook, $args, $wp_error );
    $timestamp                 = (int) $timestamp;
    $bma_test_state['calls'][] = 'schedule:' . $timestamp;

    if ( ! empty( $bma_test_state['schedule_failures'][ $timestamp ] ) ) {
        $bma_test_state['schedule_failures'][ $timestamp ]--;
        return new WP_Error( 'schedule_failed', 'The replacement event could not be created.' );
    }

    foreach ( $bma_test_state['events'] as $existing ) {
        if ( abs( $existing - $timestamp ) <= 600 ) {
            return new WP_Error( 'duplicate_event', 'A duplicate event already exists.' );
        }
    }

    $bma_test_state['events'][] = $timestamp;
    sort( $bma_test_state['events'], SORT_NUMERIC );
    return true;
}

function bma_us_calculate_next_run( $settings ) {
    global $bma_test_state;
    unset( $settings );
    return $bma_test_state['target'];
}

function get_option( $name, $default = false ) {
    global $bma_test_state;
    return array_key_exists( $name, $bma_test_state['options'] ) ? $bma_test_state['options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
    global $bma_test_state;
    unset( $autoload );
    $bma_test_state['options'][ $name ] = $value;
    return true;
}

function delete_option( $name ) {
    global $bma_test_state;
    unset( $bma_test_state['options'][ $name ] );
    return true;
}

function bma_us_send_update_alert( $result, $operation ) {
    global $bma_test_state;
    $bma_test_state['alerts'][] = array( $result, $operation );
}

function bma_test_load_function() {
    $source = file_get_contents( dirname( __DIR__ ) . '/includes/scheduler.php' );
    $start  = strpos( $source, 'function bma_us_reschedule_event' );
    if ( false === $start ) {
        throw new RuntimeException( 'bma_us_reschedule_event() was not found.' );
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
        throw new RuntimeException( 'bma_us_reschedule_event() could not be parsed.' );
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
    'unchanged schedule is retained without another cron write',
    function () {
        global $bma_test_state;
        bma_test_reset( array( 1000 ), 1000 );
        bma_test_assert_same( true, bma_us_reschedule_event( array( 'enabled' => 1 ) ), 'The unchanged schedule should succeed.' );
        bma_test_assert_same( array( 1000 ), $bma_test_state['events'], 'The existing event should remain.' );
        bma_test_assert_same( array(), $bma_test_state['calls'], 'No cron write should occur.' );
    }
);

bma_test_run(
    'five-minute adjustment removes the old event before creating the replacement',
    function () {
        global $bma_test_state;
        bma_test_reset( array( 1000 ), 1300 );
        bma_test_assert_same( true, bma_us_reschedule_event( array( 'enabled' => 1 ) ), 'A five-minute adjustment should succeed.' );
        bma_test_assert_same( array( 1300 ), $bma_test_state['events'], 'Only the replacement event should remain.' );
        bma_test_assert_same( array( 'clear', 'schedule:1300' ), $bma_test_state['calls'], 'The old event must be removed before scheduling.' );
    }
);

bma_test_run(
    'larger time adjustment creates exactly one replacement event',
    function () {
        global $bma_test_state;
        bma_test_reset( array( 1000 ), 5000 );
        bma_test_assert_same( true, bma_us_reschedule_event( array( 'enabled' => 1 ) ), 'A larger adjustment should succeed.' );
        bma_test_assert_same( array( 5000 ), $bma_test_state['events'], 'Only the new event should remain.' );
    }
);

bma_test_run(
    'disabling the schedule removes every matching event',
    function () {
        global $bma_test_state;
        bma_test_reset( array( 1000, 2000 ), false );
        bma_test_assert_same( true, bma_us_reschedule_event( array( 'enabled' => 0 ) ), 'Disabling should succeed.' );
        bma_test_assert_same( array(), $bma_test_state['events'], 'No scheduled events should remain.' );
    }
);

bma_test_run(
    'failed replacement restores the previous event and reports failure',
    function () {
        global $bma_test_state;
        bma_test_reset( array( 1000 ), 1300 );
        $bma_test_state['schedule_failures'][1300] = 1;
        bma_test_assert_same( false, bma_us_reschedule_event( array( 'enabled' => 1 ) ), 'The save must fail when WordPress rejects the replacement.' );
        bma_test_assert_same( array( 1000 ), $bma_test_state['events'], 'The previous event must be restored.' );
        bma_test_assert_same( array( 'clear', 'schedule:1300', 'schedule:1000' ), $bma_test_state['calls'], 'Rollback should occur immediately after the failed replacement.' );
    }
);

bma_test_run(
    'failed removal leaves the previous event untouched',
    function () {
        global $bma_test_state;
        bma_test_reset( array( 1000 ), 1300 );
        $bma_test_state['clear_failure'] = true;
        bma_test_assert_same( false, bma_us_reschedule_event( array( 'enabled' => 1 ) ), 'The save must fail when the existing event cannot be removed.' );
        bma_test_assert_same( array( 1000 ), $bma_test_state['events'], 'The previous event must remain intact.' );
        bma_test_assert_same( array( 'clear' ), $bma_test_state['calls'], 'No replacement should be attempted after a removal failure.' );
    }
);

echo "All schedule replacement tests passed.\n";

