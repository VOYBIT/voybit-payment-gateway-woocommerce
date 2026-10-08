( function () {
	var settings = window.wc && window.wc.wcSettings ? window.wc.wcSettings.getSetting( 'voybit_data', {} ) : {};
	var element = window.wp && window.wp.element ? window.wp.element : null;
	var decode = window.wp && window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function ( value ) {
		return value;
	};
	if ( ! element || ! window.wc || ! window.wc.wcBlocksRegistry ) {
		return;
	}

	var title = decode( settings.title || '' );
	var description = decode( settings.description || '' );
	var icon = settings.icon ? element.createElement( 'img', { src: settings.icon, alt: '' } ) : null;

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'voybit',
		label: element.createElement( 'span', { className: 'voybit-for-woocommerce-label' }, icon, title ),
		content: element.createElement( 'div', { className: 'voybit-for-woocommerce-description' }, description ),
		edit: element.createElement( 'div', { className: 'voybit-for-woocommerce-description' }, description ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: settings.supports || { features: [ 'products' ] }
	} );
}() );
