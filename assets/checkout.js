/**
 * Reports the checkout email and the opt-in tick to the site's own cart-contact route, so a ticked
 * shopper's cart can be reminded. Nothing is sent before the box is ticked, and nothing goes to
 * mailtonic from the browser. Works with the classic checkout and the checkout block.
 */
( function () {
	const config = window.mailtonicCheckout;
	if ( ! config ) {
		return;
	}
	let last = '';

	function field( selectors ) {
		for ( const selector of selectors ) {
			const el = document.querySelector( selector );
			if ( el ) {
				return el;
			}
		}
		return null;
	}

	function report() {
		const email = field( [ '#billing_email', '#email' ] );
		const box = field( [ '#mailtonic_optin', 'input[id*="mailtonic-optin"]', 'input[id*="mailtonic/optin"]' ] );
		if ( ! email || ! box ) {
			return;
		}
		const ticked = box.checked;
		const value = ticked ? email.value.trim() : '';
		const state = ticked + '|' + value;
		if ( state === last || ( ticked && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value ) ) ) {
			return;
		}
		if ( ! ticked && last === '' ) {
			return;
		}
		last = state;
		window.fetch( config.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-Mailtonic-Nonce': config.nonce },
			body: JSON.stringify( { email: value, opted_in: ticked } ),
		} );
	}

	document.addEventListener( 'change', report, true );
	document.addEventListener( 'focusout', report, true );
} )();
