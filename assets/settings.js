/**
 * Settings → Flurfunk: pick the logo from the media library.
 *
 * @package Flurfunk
 */
( function () {
	const pick = document.getElementById( 'ff-logo-pick' );
	const clear = document.getElementById( 'ff-logo-clear' );
	const input = document.getElementById( 'ff-logo' );
	const img = document.getElementById( 'ff-logo-preview' );
	let frame;
	if ( ! pick || ! clear || ! input || ! img ) {
		return;
	}
	pick.addEventListener( 'click', function () {
		frame = frame || wp.media( { library: { type: 'image' }, multiple: false } );
		frame.off( 'select' ).on( 'select', function () {
			const a = frame.state().get( 'selection' ).first().toJSON();
			input.value = a.id;
			img.src = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
			img.style.display = '';
		} );
		frame.open();
	} );
	clear.addEventListener( 'click', function () {
		input.value = 0;
		img.style.display = 'none';
	} );
} )();
