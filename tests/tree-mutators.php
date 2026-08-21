<?php
/**
 * Mirrors tests/tree.test.ts for the PHP port of the element-tree mutators.
 *
 * EMCP_Tree's mutators are pure (no WordPress functions), so this runs them
 * directly under plain PHP rather than the stubbed-WordPress harness used by
 * tests/plugin-load.php. Every case here has a matching assertion in
 * tests/tree.test.ts — the two are meant to be read side by side.
 *
 * Usage: php tests/tree-mutators.php
 */

// wp_rand/wp_json_encode/wp_strip_all_tags are used by EMCP_Tree outside the
// mutators; stub the minimum needed to load the class standalone.
function wp_rand( $min = 0, $max = 0 ) { return random_int( $min, $max ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
define( 'ABSPATH', __DIR__ );

require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/class-emcp-tree.php';

$failures = array();
$passed   = 0;

function check( $label, $condition ) {
	global $failures, $passed;

	if ( $condition ) {
		$passed++;
	} else {
		$failures[] = $label;
	}
}

function throws( $label, callable $fn, $pattern ) {
	global $failures, $passed;

	try {
		$fn();
		$failures[] = "$label (expected an EMCP_Tree_Error, none thrown)";
	} catch ( EMCP_Tree_Error $e ) {
		if ( preg_match( $pattern, $e->getMessage() ) ) {
			$passed++;
		} else {
			$failures[] = "$label (message did not match $pattern: {$e->getMessage()})";
		}
	}
}

/** The same fixture as tests/tree.test.ts's samplePage(). */
function sample_page() {
	return array(
		array(
			'id'       => 'aaa0001',
			'elType'   => 'container',
			'settings' => array( 'flex_direction' => 'row' ),
			'elements' => array(
				array(
					'id'         => 'bbb0001',
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => array( 'title' => 'Welcome home' ),
					'elements'   => array(),
				),
				array(
					'id'         => 'bbb0002',
					'elType'     => 'widget',
					'widgetType' => 'button',
					'settings'   => array( 'text' => 'Get started' ),
					'elements'   => array(),
				),
			),
		),
		array(
			'id'       => 'aaa0002',
			'elType'   => 'container',
			'settings' => array(),
			'elements' => array(
				array(
					'id'         => 'ccc0001',
					'elType'     => 'widget',
					'widgetType' => 'image',
					'settings'   => array( 'image' => array( 'id' => 12, 'url' => 'https://example.test/a.png' ) ),
					'elements'   => array(),
				),
			),
		),
	);
}

// --- generate_id -------------------------------------------------------
for ( $i = 0; $i < 200; $i++ ) {
	check( 'generate_id: 7-char lowercase hex', (bool) preg_match( '/^[0-9a-f]{7}$/', EMCP_Tree::generate_id() ) );
}

// --- find ----------------------------------------------------------------
$found = EMCP_Tree::find( sample_page(), 'bbb0002' );
check( 'find: locates a nested node', 'bbb0002' === ( $found['id'] ?? null ) );
check( 'find: returns null for unknown id', null === EMCP_Tree::find( sample_page(), 'nope' ) );

// --- insert ----------------------------------------------------------------
$next = EMCP_Tree::insert( sample_page(), EMCP_Tree::make_widget( 'spacer' ) );
check( 'insert: appends to page root', 3 === count( $next ) && 'spacer' === $next[2]['widgetType'] );

$next = EMCP_Tree::insert( sample_page(), EMCP_Tree::make_widget( 'spacer' ), '', 'prepend' );
check( 'insert: prepends at root', 'spacer' === $next[0]['widgetType'] );

$next = EMCP_Tree::insert( sample_page(), EMCP_Tree::make_widget( 'divider' ), 'aaa0002' );
check( 'insert: appends inside a container', 2 === count( $next[1]['elements'] ) && 'divider' === $next[1]['elements'][1]['widgetType'] );

$next  = EMCP_Tree::insert( sample_page(), EMCP_Tree::make_widget( 'divider' ), 'bbb0002', 'before' );
$types = array_map( function ( $n ) { return $n['widgetType']; }, $next[0]['elements'] );
check( 'insert: places a sibling before a widget', array( 'heading', 'divider', 'button' ) === $types );

throws(
	'insert: refuses to nest inside a widget',
	function () { EMCP_Tree::insert( sample_page(), EMCP_Tree::make_widget( 'divider' ), 'bbb0001' ); },
	'/widgets do not take children/i'
);

$clash = array( 'id' => 'bbb0001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array(), 'elements' => array() );
$next  = EMCP_Tree::insert( sample_page(), $clash );
$ids   = EMCP_Tree::collect_ids( $next );
check( 'insert: rekeys a colliding incoming id', count( $ids ) === count( array_unique( $ids ) ) );

$original = sample_page();
EMCP_Tree::insert( $original, EMCP_Tree::make_widget( 'spacer' ) );
check( 'insert: does not mutate the caller\'s tree', 2 === count( $original ) );

throws(
	'insert: unknown target reports the missing id',
	function () { EMCP_Tree::insert( sample_page(), EMCP_Tree::make_widget( 'spacer' ), 'missing' ); },
	'/No element with id "missing"/'
);

// --- update_settings ---------------------------------------------------
$next = EMCP_Tree::update_settings( sample_page(), 'bbb0001', array( 'align' => 'center' ) );
check( 'update_settings: merges by default', array( 'title' => 'Welcome home', 'align' => 'center' ) === EMCP_Tree::find( $next, 'bbb0001' )['settings'] );

$next = EMCP_Tree::update_settings( sample_page(), 'bbb0001', array( 'align' => 'center' ), 'replace' );
check( 'update_settings: replace discards other keys', array( 'align' => 'center' ) === EMCP_Tree::find( $next, 'bbb0001' )['settings'] );

// --- move --------------------------------------------------------------
$next = EMCP_Tree::move( sample_page(), 'bbb0001', 'aaa0002' );
check( 'move: leaves the source with one child', 1 === count( EMCP_Tree::find( $next, 'aaa0001' )['elements'] ) );
$moved_ids = array_map( function ( $n ) { return $n['id']; }, EMCP_Tree::find( $next, 'aaa0002' )['elements'] );
check( 'move: appends into the destination', array( 'ccc0001', 'bbb0001' ) === $moved_ids );

$next = EMCP_Tree::move( sample_page(), 'bbb0001' );
check( 'move: moves to the page root with no reference', 3 === count( $next ) && 'bbb0001' === $next[2]['id'] );

throws(
	'move: refuses to move into its own descendant',
	function () { EMCP_Tree::move( sample_page(), 'aaa0001', 'bbb0001' ); },
	'/its own descendants/i'
);

throws(
	'move: refuses to move relative to itself',
	function () { EMCP_Tree::move( sample_page(), 'aaa0001', 'aaa0001' ); },
	'/relative to itself/i'
);

$before = EMCP_Tree::collect_ids( sample_page() );
sort( $before );
$after = EMCP_Tree::collect_ids( EMCP_Tree::move( sample_page(), 'ccc0001', 'bbb0001', 'before' ) );
sort( $after );
check( 'move: keeps every node when reordering across parents', $before === $after );

// --- duplicate -----------------------------------------------------------
list( $next, $new_id ) = EMCP_Tree::duplicate( sample_page(), 'aaa0001' );
check( 'duplicate: places the copy directly after the original', 3 === count( $next ) && $next[1]['id'] === $new_id );
check( 'duplicate: the copy has a fresh id', 'aaa0001' !== $new_id );
$dup_ids = EMCP_Tree::collect_ids( $next );
check( 'duplicate: no id collisions after the copy', count( $dup_ids ) === count( array_unique( $dup_ids ) ) );
check( 'duplicate: copies the whole subtree', 2 === count( EMCP_Tree::find( $next, $new_id )['elements'] ) );

// --- remove ----------------------------------------------------------------
$next = EMCP_Tree::remove( sample_page(), 'aaa0001' );
check( 'remove: deletes a node and its children', 1 === count( $next ) && null === EMCP_Tree::find( $next, 'bbb0001' ) );

// --- reorder ---------------------------------------------------------------
$next = EMCP_Tree::reorder( sample_page(), 'aaa0001', array( 'bbb0002', 'bbb0001' ) );
check( 'reorder: reorders direct children', array( 'bbb0002', 'bbb0001' ) === array_map( function ( $n ) { return $n['id']; }, EMCP_Tree::find( $next, 'aaa0001' )['elements'] ) );

$page                             = sample_page();
$page[0]['elements'][]            = EMCP_Tree::make_widget( 'divider', array(), 'ddd0001' );
$next                             = EMCP_Tree::reorder( $page, 'aaa0001', array( 'ddd0001' ) );
check( 'reorder: leaves omitted children at the end', array( 'ddd0001', 'bbb0001', 'bbb0002' ) === array_map( function ( $n ) { return $n['id']; }, EMCP_Tree::find( $next, 'aaa0001' )['elements'] ) );

throws(
	'reorder: rejects a non-child id',
	function () { EMCP_Tree::reorder( sample_page(), 'aaa0001', array( 'ccc0001' ) ); },
	'/not direct children/i'
);

// --- wrap --------------------------------------------------------------
list( $next, $wrapper_id ) = EMCP_Tree::wrap( sample_page(), 'aaa0001' );
check( 'wrap: wraps a node in place', 2 === count( $next ) && $next[0]['id'] === $wrapper_id && 'container' === $next[0]['elType'] );
check( 'wrap: the original becomes the wrapper\'s only child', 'aaa0001' === $next[0]['elements'][0]['id'] );

list( $next, ) = EMCP_Tree::wrap( sample_page(), 'bbb0001', EMCP_Tree::make_container( array( 'background_color' => '#000' ) ) );
check( 'wrap: uses the settings it is given', array( 'background_color' => '#000' ) === EMCP_Tree::find( $next, 'aaa0001' )['elements'][0]['settings'] );

// --- apply_operations --------------------------------------------------
list( $next, $log ) = EMCP_Tree::apply_operations(
	sample_page(),
	array(
		array( 'op' => 'insert', 'node' => EMCP_Tree::make_widget( 'divider', array(), 'ddd0001' ), 'targetId' => 'aaa0002' ),
		array( 'op' => 'update', 'targetId' => 'bbb0001', 'settings' => array( 'align' => 'center' ) ),
		array( 'op' => 'delete', 'targetId' => 'bbb0002' ),
	)
);
check( 'apply_operations: applies operations in sequence', 3 === count( $log ) );
check( 'apply_operations: insert landed', null !== EMCP_Tree::find( $next, 'ddd0001' ) );
check( 'apply_operations: update landed', 'center' === EMCP_Tree::find( $next, 'bbb0001' )['settings']['align'] );
check( 'apply_operations: delete landed', null === EMCP_Tree::find( $next, 'bbb0002' ) );

$original = sample_page();
throws(
	'apply_operations: abandons the whole batch on failure',
	function () use ( $original ) {
		EMCP_Tree::apply_operations(
			$original,
			array(
				array( 'op' => 'update', 'targetId' => 'bbb0001', 'settings' => array( 'align' => 'center' ) ),
				array( 'op' => 'delete', 'targetId' => 'does-not-exist' ),
			)
		);
	},
	'/Batch failed at operation #2/'
);
check( 'apply_operations: caller\'s tree is untouched after a failed batch', ! isset( EMCP_Tree::find( $original, 'bbb0001' )['settings']['align'] ) );

throws(
	'apply_operations: rejects an unknown op name',
	function () { EMCP_Tree::apply_operations( sample_page(), array( array( 'op' => 'explode' ) ) ); },
	'/Unknown operation/'
);

// --- report ----------------------------------------------------------------
printf( "%d passed, %d failed\n", $passed, count( $failures ) );

if ( $failures ) {
	echo "\nFAILURES:\n";
	foreach ( $failures as $failure ) {
		echo "  - $failure\n";
	}
	exit( 1 );
}

echo "TREE MUTATORS OK\n";
