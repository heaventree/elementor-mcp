/**
 * The shape of Elementor's stored page data.
 *
 * A document's layout is a list of nodes. Structural nodes (`container`,
 * `section`, `column`) hold children; `widget` nodes are leaves and carry a
 * `widgetType` naming the widget that renders them.
 */
export interface ElementNode {
  /** Short lowercase hex id, unique within the document. */
  id: string;
  /** `container`, `section`, `column` or `widget`. */
  elType: string;
  /** Control values keyed by control name. */
  settings?: Record<string, unknown>;
  /** Child nodes. Absent or empty on widgets. */
  elements?: ElementNode[];
  /** Set on widgets: which widget renders this node. */
  widgetType?: string;
  /** True for sections/columns nested inside another section. */
  isInner?: boolean;
  /** Elementor marks locked elements so the editor will not move them. */
  isLocked?: boolean;
  [key: string]: unknown;
}

/** Where to place a node relative to a reference element. */
export type InsertPosition =
  | 'append'
  | 'prepend'
  | 'before'
  | 'after';

/** A compact structural view of a document. */
export interface OutlineNode {
  id: string;
  elType: string;
  widgetType?: string;
  label?: string;
  childCount?: number;
  elements?: OutlineNode[];
}

/** Result of locating a node inside a tree. */
export interface NodeLocation {
  node: ElementNode;
  /** The array the node currently lives in. */
  siblings: ElementNode[];
  /** Index of the node within `siblings`. */
  index: number;
  /** Parent node, or null when the node is at the document root. */
  parent: ElementNode | null;
  /** Ancestor ids, outermost first. */
  ancestors: string[];
}

/** One mutation in a batch edit. */
export type TreeOperation =
  | { op: 'insert'; node: ElementNode; targetId?: string; position?: InsertPosition }
  | { op: 'update'; targetId: string; settings: Record<string, unknown>; mode?: 'merge' | 'replace' }
  | { op: 'move'; targetId: string; referenceId?: string; position?: InsertPosition }
  | { op: 'duplicate'; targetId: string }
  | { op: 'delete'; targetId: string }
  | { op: 'reorder'; targetId: string; order: string[] }
  | { op: 'wrap'; targetId: string; wrapper?: ElementNode };

/** Metadata the bridge returns about a document. */
export interface DocumentMeta {
  id: number;
  title: string;
  slug?: string;
  status?: string;
  type?: string;
  permalink?: string;
  editUrl?: string;
  modified?: string;
  isElementor?: boolean;
  templateType?: string;
  hash?: string;
  nodeCount?: number;
  census?: Record<string, number>;
  snapshotCount?: number;
  elements?: ElementNode[];
  settings?: Record<string, unknown>;
}
