/**
 * WordPress dependencies
 */
const { createElement } = wp.element;
const { PluginDocumentSettingPanel } = wp.editPost;
const { registerPlugin } = wp.plugins;
const { TextControl } = wp.components;
const { useEntityProp } = wp.coreData;
const { useSelect } = wp.data;

// Note: This file is loaded in the browser, not compiled via webpack or wp-scripts.
// There is no build step, but JSX & import are not supported.

function PartChapterPanel() {
	const postType = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostType(), [] );
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
	if ( postType !== 'post' ) {
		return;
	}

	const updateMetaValue = ( key, newValue ) => {
		setMeta( { ...meta, [ key ]: newValue } );
	};

	return createElement(
		PluginDocumentSettingPanel,
		{
			name: 'book-part-chapter',
			title: 'Book Data',
		},
		[
			createElement( TextControl, {
				key: 'vol',
				label: 'Volume',
				value: meta.mb_vol,
				onChange: ( newValue ) => {
					updateMetaValue( 'mb_vol', newValue );
				},
			} ),
			createElement( TextControl, {
				key: 'part',
				label: 'Part',
				value: meta.mb_part,
				onChange: ( newValue ) => {
					updateMetaValue( 'mb_part', newValue );
				},
			} ),
			createElement( TextControl, {
				key: 'chapter',
				label: 'Chapter',
				value: meta.mb_chapter,
				onChange: ( newValue ) => {
					updateMetaValue( 'mb_chapter', newValue );
				},
			} ),
		]
	);
}

/**
 * Check if the panel is open, and open it if not.
 */
function initialOpen() {
	const panelName = 'book-part-chapter-panel/book-part-chapter';
	const openPanels = wp.data.select( wp.preferences.store ).get( 'core/edit-post', 'openPanels' );

	if ( ! openPanels?.includes( panelName ) ) {
		wp.data.dispatch( wp.editPost.store ).toggleEditorPanelOpened( panelName );
	}
}

registerPlugin( 'book-part-chapter-panel', {
	render: PartChapterPanel,
} );

initialOpen();
