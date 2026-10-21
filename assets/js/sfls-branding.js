/**
 * My Account › Branding: keeps the preview in step with the form.
 * Without this script the form still works; the preview shows the saved values.
 */
( function () {
	var preview = document.querySelector( '.sfls-bp' );
	var form = document.querySelector( '.sfls-bform' );
	if ( ! preview || ! form ) {
		return;
	}

	var fallback = preview.getAttribute( 'data-default-name' ) || 'SubtleForms';
	var fallbackDescription = preview.getAttribute( 'data-default-description' ) || '';
	var logoBox = preview.querySelector( '[data-sfls-bp="logo"]' );
	var defaultLogo = logoBox ? logoBox.innerHTML : '';
	var fileUrl = '';

	function field( bind ) {
		return form.querySelector( '[data-sfls-bind="' + bind + '"]' );
	}

	function value( bind ) {
		var el = field( bind );
		return el ? el.value.trim() : '';
	}

	function setText( key, text ) {
		preview.querySelectorAll( '[data-sfls-bp="' + key + '"]' ).forEach( function ( el ) {
			el.textContent = text;
		} );
	}

	function setLogo( url ) {
		if ( ! logoBox ) {
			return;
		}
		if ( ! url ) {
			logoBox.innerHTML = defaultLogo;
			return;
		}
		var img = document.createElement( 'img' );
		img.alt = '';
		img.src = url;
		logoBox.innerHTML = '';
		logoBox.appendChild( img );
	}

	function render() {
		var name = value( 'name' ) || fallback;
		setText( 'name', name );
		setText( 'menu', value( 'menu' ) || name );
		setText( 'author', value( 'author' ) || 'SubtleForms' );
		setText( 'description', value( 'description' ) || fallbackDescription );
		var address = value( 'logo-url' );
		if ( fileUrl ) {
			setLogo( fileUrl );
		} else if ( /^https:\/\//i.test( address ) ) {
			setLogo( address );
		}
	}

	form.addEventListener( 'input', function ( event ) {
		if ( event.target && event.target.type === 'file' ) {
			return;
		}
		render();
	} );

	var file = field( 'logo-file' );
	if ( file ) {
		file.addEventListener( 'change', function () {
			if ( fileUrl ) {
				URL.revokeObjectURL( fileUrl );
				fileUrl = '';
			}
			if ( file.files && file.files[ 0 ] && /^image\//.test( file.files[ 0 ].type ) ) {
				fileUrl = URL.createObjectURL( file.files[ 0 ] );
			}
			render();
		} );
	}
}() );
