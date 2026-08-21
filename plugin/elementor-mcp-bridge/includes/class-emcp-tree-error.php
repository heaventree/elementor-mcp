<?php
/**
 * Exception type for element-tree mutation failures.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by EMCP_Tree's mutators (insert, move, remove, and so on).
 *
 * Kept as a real exception rather than a WP_Error so the pure tree-mutation
 * code never has to know about WordPress. The single call site that drives a
 * multi-step edit catches it once and converts it to a WP_Error there.
 */
class EMCP_Tree_Error extends Exception {

	/**
	 * What to do next. Always set: every tree error is something a caller can
	 * act on by re-reading the outline and retrying.
	 *
	 * @var string
	 */
	public $hint;

	/**
	 * Machine-readable error code, mirrors the codes used elsewhere in the bridge.
	 *
	 * @var string
	 */
	public $error_code;

	/**
	 * Extra structured detail, merged into the WP_Error's data.
	 *
	 * @var array
	 */
	public $error_data;

	/**
	 * @param string $message    Human-readable message.
	 * @param string $error_code Machine-readable code.
	 * @param string $hint       What to do next.
	 * @param array  $error_data Extra structured detail.
	 */
	public function __construct( $message, $error_code = 'emcp_tree_error', $hint = '', array $error_data = array() ) {
		parent::__construct( $message );

		$this->error_code = $error_code;
		$this->hint       = $hint;
		$this->error_data = $error_data;
	}

	/**
	 * Convert to the WP_Error shape the rest of the bridge returns.
	 *
	 * @param int $status HTTP status to attach.
	 * @return WP_Error
	 */
	public function to_wp_error( $status = 400 ) {
		return new WP_Error(
			$this->error_code,
			$this->hint ? $this->getMessage() . ' ' . $this->hint : $this->getMessage(),
			array_merge( array( 'status' => $status ), $this->error_data )
		);
	}

	/**
	 * "No element with id X" helper, mirroring ElementNotFoundError in the TS server.
	 *
	 * @param string $id Element id.
	 * @return self
	 */
	public static function not_found( $id ) {
		return new self(
			sprintf( 'No element with id "%s" in this tree.', $id ),
			'emcp_element_not_found',
			'Call the outline endpoint to list the element ids this document actually contains.'
		);
	}
}
