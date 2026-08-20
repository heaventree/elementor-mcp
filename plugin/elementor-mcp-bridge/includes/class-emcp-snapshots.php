<?php
/**
 * Point-in-time snapshots of Elementor documents, independent of WP revisions.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Snapshot store.
 *
 * WordPress revisions only capture post content, and Elementor keeps its layout
 * in postmeta — so a revision alone will not always bring a page back. Every
 * write the bridge performs takes a snapshot first, which makes "undo the last
 * thing you did" a single call.
 *
 * Snapshots are stored in postmeta on the document itself, so they travel with
 * the post through exports and get cleaned up when the post is deleted.
 */
class EMCP_Snapshots {

	/**
	 * Meta key holding the snapshot ring buffer.
	 */
	const META_KEY = '_emcp_snapshots';

	/**
	 * How many snapshots to retain per document.
	 */
	const DEFAULT_LIMIT = 20;

	/**
	 * Nothing to create up-front; kept so activation has a clear hook.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		// Snapshots are lazily created per document; no schema to install.
	}

	/**
	 * How many snapshots to keep per document.
	 *
	 * @return int
	 */
	public static function limit() {
		/**
		 * Filters the per-document snapshot retention count.
		 *
		 * @param int $limit Number of snapshots to keep.
		 */
		return max( 1, (int) apply_filters( 'emcp_snapshot_limit', self::DEFAULT_LIMIT ) );
	}

	/**
	 * Read the snapshot list for a document, newest first.
	 *
	 * @param int $post_id Post ID.
	 * @return array[]
	 */
	public static function all( $post_id ) {
		$raw = get_post_meta( (int) $post_id, self::META_KEY, true );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		return $raw;
	}

	/**
	 * Snapshot list without the (large) payloads, for listing calls.
	 *
	 * @param int $post_id Post ID.
	 * @return array[]
	 */
	public static function index( $post_id ) {
		return array_map(
			static function ( $snapshot ) {
				unset( $snapshot['elements'], $snapshot['settings'] );
				return $snapshot;
			},
			self::all( $post_id )
		);
	}

	/**
	 * Capture the current state of a document.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $label   Why the snapshot was taken.
	 * @return array|WP_Error The snapshot metadata (without payload).
	 */
	public static function capture( $post_id, $label = '' ) {
		$post_id = (int) $post_id;

		$elements = EMCP_Documents::read_elements( $post_id );

		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		$snapshot = array(
			'id'        => uniqid( 'snap_', false ),
			'label'     => (string) $label,
			'createdAt' => gmdate( 'c' ),
			'author'    => get_current_user_id(),
			'hash'      => EMCP_Tree::hash( $elements ),
			'nodeCount' => count( EMCP_Tree::collect_ids( $elements ) ),
			'elements'  => $elements,
			'settings'  => EMCP_Documents::read_settings( $post_id ),
		);

		$list = self::all( $post_id );
		array_unshift( $list, $snapshot );
		$list = array_slice( $list, 0, self::limit() );

		update_post_meta( $post_id, self::META_KEY, $list );

		unset( $snapshot['elements'], $snapshot['settings'] );

		return $snapshot;
	}

	/**
	 * Restore a snapshot back onto its document.
	 *
	 * The current state is snapshotted first, so a restore is itself undoable.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $snapshot_id Snapshot ID, or empty for the most recent.
	 * @return array|WP_Error
	 */
	public static function restore( $post_id, $snapshot_id = '' ) {
		$post_id = (int) $post_id;
		$list    = self::all( $post_id );

		if ( ! $list ) {
			return new WP_Error(
				'emcp_no_snapshots',
				__( 'This document has no snapshots to restore.', 'elementor-mcp-bridge' ),
				array( 'status' => 404 )
			);
		}

		$target = null;

		if ( '' === $snapshot_id ) {
			$target = $list[0];
		} else {
			foreach ( $list as $snapshot ) {
				if ( isset( $snapshot['id'] ) && $snapshot['id'] === $snapshot_id ) {
					$target = $snapshot;
					break;
				}
			}
		}

		if ( null === $target ) {
			return new WP_Error(
				'emcp_snapshot_not_found',
				sprintf(
					/* translators: %s: snapshot id */
					__( 'No snapshot with id "%s" on this document.', 'elementor-mcp-bridge' ),
					$snapshot_id
				),
				array( 'status' => 404 )
			);
		}

		self::capture( $post_id, 'before restore of ' . $target['id'] );

		$result = EMCP_Documents::write(
			$post_id,
			isset( $target['elements'] ) ? $target['elements'] : array(),
			isset( $target['settings'] ) && is_array( $target['settings'] ) ? $target['settings'] : null,
			false
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'restored'  => $target['id'],
			'createdAt' => isset( $target['createdAt'] ) ? $target['createdAt'] : null,
			'document'  => $result,
		);
	}

	/**
	 * Delete every snapshot for a document.
	 *
	 * @param int $post_id Post ID.
	 * @return int Number of snapshots removed.
	 */
	public static function purge( $post_id ) {
		$count = count( self::all( $post_id ) );

		delete_post_meta( (int) $post_id, self::META_KEY );

		return $count;
	}
}
