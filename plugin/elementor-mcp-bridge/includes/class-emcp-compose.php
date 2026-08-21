<?php
/**
 * Read-mutate-write composition for element edits.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Element-edit composer.
 *
 * Every element-editing MCP tool follows the same shape: read the current
 * tree, run one of EMCP_Tree's pure mutators on it, and write the result back
 * guarded by the hash it was read at. edit() is that shape written once.
 *
 * On a hash conflict the whole cycle retries a single time against fresh
 * data, which absorbs the common case of two edits landing close together —
 * mirroring the retry in the TypeScript server's editDocument(). A second
 * conflict is surfaced, because by then something is genuinely contending.
 */
class EMCP_Compose {

	/**
	 * @param int      $post_id Post ID.
	 * @param callable $mutate  function( array $elements ): array — returns the
	 *                          new tree, or throws EMCP_Tree_Error.
	 * @param string   $label   Snapshot label for this change.
	 * @return array|WP_Error {
	 *     @type array  $write  The EMCP_Documents::write() result (id, hash, nodeCount, ...).
	 *     @type int    $nodeCountBefore
	 *     @type int    $nodeCountAfter
	 * }
	 */
	public static function edit( $post_id, callable $mutate, $label = '' ) {
		$post_id = (int) $post_id;

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$elements = EMCP_Documents::read_elements( $post_id );

			if ( is_wp_error( $elements ) ) {
				return $elements;
			}

			if ( ! is_array( $elements ) ) {
				$elements = array();
			}

			$before_count = count( EMCP_Tree::collect_ids( $elements ) );
			$hash         = EMCP_Tree::hash( $elements );

			try {
				$mutated = $mutate( $elements );
			} catch ( EMCP_Tree_Error $error ) {
				return $error->to_wp_error();
			}

			$write = EMCP_Documents::write( $post_id, $mutated, null, true, $label, $hash );

			if ( ! is_wp_error( $write ) ) {
				return array(
					'write'           => $write,
					'nodeCountBefore' => $before_count,
					'nodeCountAfter'  => count( EMCP_Tree::collect_ids( $mutated ) ),
				);
			}

			if ( 'emcp_conflict' !== $write->get_error_code() || $attempt === 1 ) {
				return $write;
			}

			// Conflict on the first attempt: loop and retry against fresh data.
		}

		/* istanbul ignore next -- the loop always returns. */
		return new WP_Error( 'emcp_conflict', __( 'The document kept changing while editing it.', 'elementor-mcp-bridge' ), array( 'status' => 409 ) );
	}
}
