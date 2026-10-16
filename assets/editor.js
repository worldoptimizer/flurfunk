/**
 * Block editor: "Conversations" panel in the document sidebar.
 */
( function ( wp ) {
	'use strict';
	const { registerPlugin } = wp.plugins;
	const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	const { createElement: el, useCallback } = wp.element;
	const { useSelect } = wp.data;
	if ( ! Panel || ! window.Flurfunk ) {
		return;
	}

	function Conversations() {
		const id = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );
		// The panel body is created anew each time it is opened, so mount from a callback ref.
		const ref = useCallback( ( node ) => {
			if ( node && id && node.dataset.ffMounted !== String( id ) ) {
				node.dataset.ffMounted = String( id );
				window.Flurfunk.mountTarget( node, { type: 'post', id } );
			}
		}, [ id ] );
		return el( Panel, { name: 'flurfunk', title: window.Flurfunk.t( 'Conversations' ), className: 'flurfunk-panel' }, el( 'div', { ref } ) );
	}

	registerPlugin( 'flurfunk', { render: Conversations, icon: 'format-chat' } );
}( window.wp ) );
