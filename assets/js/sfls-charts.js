/**
 * SubtleForms License Server: dependency-free SVG charts.
 *
 * <div class="sfls-chart" data-chart='{"type":"bar|line|pie","labels":[],
 *   "series":[{"name":"","data":[]}],"format":"number|money|percent",
 *   "currency":"USD","stacked":false,"donut":true}'></div>
 *
 * The SVG carries role="img" and an aria-label; a screen-reader table and
 * legend follow it. Rendered on DOMContentLoaded; also window.SFLSCharts.render( el, config ).
 */
( function () {
	'use strict';

	var NS = 'http://www.w3.org/2000/svg';
	// First colour follows the admin colour scheme; the rest is a fixed palette.
	var PALETTE = [ 'var(--wp-admin-theme-color, #2271b1)', '#007017', '#b32d2e', '#8a3b00', '#7e3bd0', '#00758a', '#6c6c00', '#a0236a' ];

	function colour( i ) {
		return PALETTE[ i % PALETTE.length ];
	}

	function el( name, attrs, text ) {
		var node = document.createElementNS( NS, name );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			node.setAttribute( key, attrs[ key ] );
		} );
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function formatter( cfg ) {
		var locale = document.documentElement.lang || undefined;
		var opts = {};
		try {
			if ( cfg.format === 'money' ) {
				opts = { style: 'currency', currency: cfg.currency || 'USD', maximumFractionDigits: 2 };
			} else if ( cfg.format === 'percent' ) {
				opts = { maximumFractionDigits: 1 };
			}
			var nf = new Intl.NumberFormat( locale, opts );
			return function ( v ) {
				return nf.format( v ) + ( cfg.format === 'percent' ? '%' : '' );
			};
		} catch ( e ) {
			return function ( v ) {
				return String( v );
			};
		}
	}

	// A round upper bound for the value axis.
	function niceMax( max ) {
		if ( max <= 0 ) {
			return 1;
		}
		var pow = Math.pow( 10, Math.floor( Math.log10( max ) ) );
		var n = max / pow;
		return ( n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10 ) * pow;
	}

	function shortNumber( v, fmt ) {
		return Math.abs( v ) >= 1000 && fmt.length ? new Intl.NumberFormat( undefined, { notation: 'compact' } ).format( v ) : fmt( v );
	}

	function table( cfg, fmt, pie ) {
		var t = document.createElement( 'table' );
		t.className = 'screen-reader-text';
		var head = '<tr><th></th>';
		cfg.series.forEach( function ( s ) {
			head += '<th scope="col"></th>';
		} );
		t.innerHTML = '<thead>' + head + '</tr></thead><tbody></tbody>';
		cfg.series.forEach( function ( s, i ) {
			t.querySelectorAll( 'th' )[ i + 1 ].textContent = s.name || '';
		} );
		var body = t.querySelector( 'tbody' );
		( cfg.labels || [] ).forEach( function ( label, r ) {
			var tr = document.createElement( 'tr' );
			var th = document.createElement( 'th' );
			th.scope = 'row';
			th.textContent = label;
			tr.appendChild( th );
			cfg.series.forEach( function ( s ) {
				var td = document.createElement( 'td' );
				td.textContent = fmt( Number( ( s.data || [] )[ r ] ) || 0 );
				tr.appendChild( td );
			} );
			body.appendChild( tr );
		} );
		return t;
	}

	function legend( items ) {
		var ul = document.createElement( 'ul' );
		ul.className = 'sfls-chart__legend';
		items.forEach( function ( item ) {
			var li = document.createElement( 'li' );
			var sw = document.createElement( 'span' );
			sw.className = 'sfls-chart__swatch';
			sw.style.background = item.colour;
			li.appendChild( sw );
			li.appendChild( document.createTextNode( item.name ) );
			ul.appendChild( li );
		} );
		return ul;
	}

	function cartesian( cfg, fmt ) {
		var W = 640, H = 280, L = 54, R = 12, T = 12, B = 34;
		var labels = cfg.labels || [];
		var n = labels.length;
		var line = cfg.type === 'line';
		var stacked = !! cfg.stacked && ! line;
		var max = 0;
		for ( var i = 0; i < n; i++ ) {
			var sum = 0;
			cfg.series.forEach( function ( s ) {
				var v = Math.max( 0, Number( ( s.data || [] )[ i ] ) || 0 );
				sum += v;
				if ( ! stacked ) {
					max = Math.max( max, v );
				}
			} );
			if ( stacked ) {
				max = Math.max( max, sum );
			}
		}
		max = niceMax( max );
		var pw = W - L - R, ph = H - T - B;
		var svg = el( 'svg', { viewBox: '0 0 ' + W + ' ' + H, focusable: 'false', 'class': 'sfls-chart__svg' } );
		var y = function ( v ) {
			return T + ph - ( v / max ) * ph;
		};
		for ( var g = 0; g <= 4; g++ ) {
			var gv = ( max / 4 ) * g;
			svg.appendChild( el( 'line', { x1: L, x2: W - R, y1: y( gv ), y2: y( gv ), stroke: 'currentColor', 'stroke-opacity': '.15' } ) );
			svg.appendChild( el( 'text', { x: L - 6, y: y( gv ) + 4, 'text-anchor': 'end', 'font-size': '11', fill: 'currentColor' }, shortNumber( gv, fmt ) ) );
		}
		var band = n ? pw / n : pw;
		var skip = Math.ceil( n / 12 );
		labels.forEach( function ( label, i ) {
			if ( i % skip === 0 ) {
				svg.appendChild( el( 'text', { x: L + band * i + band / 2, y: H - 12, 'text-anchor': 'middle', 'font-size': '11', fill: 'currentColor' }, label ) );
			}
		} );
		cfg.series.forEach( function ( s, si ) {
			var c = colour( si );
			var data = s.data || [];
			if ( line ) {
				var pts = labels.map( function ( label, i ) {
					return ( L + band * i + band / 2 ) + ',' + y( Number( data[ i ] ) || 0 );
				} );
				svg.appendChild( el( 'polyline', { points: pts.join( ' ' ), fill: 'none', stroke: c, 'stroke-width': '2.5', 'stroke-linejoin': 'round' } ) );
				labels.forEach( function ( label, i ) {
					var v = Number( data[ i ] ) || 0;
					var dot = el( 'circle', { cx: L + band * i + band / 2, cy: y( v ), r: 4, fill: c, stroke: 'var(--sfls-surface, #fff)' } );
					dot.appendChild( el( 'title', {}, label + ' - ' + ( s.name || '' ) + ': ' + fmt( v ) ) );
					svg.appendChild( dot );
				} );
				return;
			}
			var k = cfg.series.length;
			var bw = stacked ? band * 0.6 : ( band * 0.8 ) / k;
			labels.forEach( function ( label, i ) {
				var v = Math.max( 0, Number( data[ i ] ) || 0 );
				var base = 0;
				if ( stacked ) {
					for ( var j = 0; j < si; j++ ) {
						base += Math.max( 0, Number( ( cfg.series[ j ].data || [] )[ i ] ) || 0 );
					}
				}
				var x = stacked ? L + band * i + ( band - bw ) / 2 : L + band * i + band * 0.1 + bw * si;
				var rect = el( 'rect', { x: x, y: y( base + v ), width: Math.max( bw - 1, 1 ), height: Math.max( y( base ) - y( base + v ), 0 ), fill: c } );
				rect.appendChild( el( 'title', {}, label + ' - ' + ( s.name || '' ) + ': ' + fmt( v ) ) );
				svg.appendChild( rect );
			} );
		} );
		return svg;
	}

	function arc( cx, cy, r0, r1, a0, a1 ) {
		var p = function ( r, a ) {
			return ( cx + r * Math.cos( a ) ) + ' ' + ( cy + r * Math.sin( a ) );
		};
		var large = a1 - a0 > Math.PI ? 1 : 0;
		return 'M ' + p( r1, a0 ) + ' A ' + r1 + ' ' + r1 + ' 0 ' + large + ' 1 ' + p( r1, a1 ) +
			' L ' + p( r0, a1 ) + ' A ' + r0 + ' ' + r0 + ' 0 ' + large + ' 0 ' + p( r0, a0 ) + ' Z';
	}

	function pie( cfg, fmt ) {
		var data = ( cfg.series[ 0 ] || {} ).data || [];
		var labels = cfg.labels || [];
		var total = data.reduce( function ( a, v ) {
			return a + Math.max( 0, Number( v ) || 0 );
		}, 0 );
		var svg = el( 'svg', { viewBox: '0 0 220 220', focusable: 'false', 'class': 'sfls-chart__svg sfls-chart__svg--pie' } );
		var inner = cfg.donut === false ? 0 : 52;
		var angle = -Math.PI / 2;
		labels.forEach( function ( label, i ) {
			var v = Math.max( 0, Number( data[ i ] ) || 0 );
			if ( ! v ) {
				return;
			}
			var share = v / total;
			var node;
			if ( share >= 0.9999 ) {
				node = el( 'circle', { cx: 110, cy: 110, r: 100, fill: colour( i ) } );
			} else {
				node = el( 'path', { d: arc( 110, 110, inner, 100, angle, angle + share * 2 * Math.PI ), fill: colour( i ), stroke: 'var(--sfls-surface, #fff)', 'stroke-width': '1' } );
			}
			node.appendChild( el( 'title', {}, label + ': ' + fmt( v ) + ' (' + ( share * 100 ).toFixed( 1 ) + '%)' ) );
			svg.appendChild( node );
			angle += share * 2 * Math.PI;
		} );
		if ( inner && total ) {
			svg.appendChild( el( 'text', { x: 110, y: 116, 'text-anchor': 'middle', 'font-size': '20', 'font-weight': '600', fill: 'currentColor' }, fmt( total ) ) );
		}
		return svg;
	}

	function render( host, cfg ) {
		if ( ! host || ! cfg ) {
			return;
		}
		cfg.series = Array.isArray( cfg.series ) ? cfg.series : [];
		var fmt = formatter( cfg );
		var isPie = cfg.type === 'pie';
		host.textContent = '';
		host.classList.add( 'sfls-chart--' + ( cfg.type || 'bar' ) );
		var hasData = cfg.series.some( function ( s ) {
			return ( s.data || [] ).some( function ( v ) {
				return Number( v ) > 0;
			} );
		} );
		if ( ! hasData ) {
			var empty = document.createElement( 'p' );
			empty.className = 'sfls-empty';
			empty.textContent = cfg.empty || '-';
			host.appendChild( empty );
			return;
		}
		var summary = ( cfg.title ? cfg.title + '. ' : '' ) + ( cfg.labels || [] ).length + ' ' + ( isPie ? 'slices' : 'points' ) + ': ' +
			( isPie ?
				( cfg.labels || [] ).map( function ( l, i ) {
					return l + ' ' + fmt( Number( cfg.series[ 0 ].data[ i ] ) || 0 );
				} ).slice( 0, 6 ).join( ', ' ) :
				cfg.series.map( function ( s ) {
					return s.name;
				} ).join( ', ' ) );
		var svg = isPie ? pie( cfg, fmt ) : cartesian( cfg, fmt );
		svg.setAttribute( 'role', 'img' );
		svg.setAttribute( 'aria-label', summary );
		host.appendChild( svg );
		host.appendChild( legend( isPie ?
			( cfg.labels || [] ).map( function ( l, i ) {
				return { name: l, colour: colour( i ) };
			} ) :
			cfg.series.map( function ( s, i ) {
				return { name: s.name || '', colour: colour( i ) };
			} ) ) );
		host.appendChild( table( cfg, fmt, isPie ) );
	}

	function renderAll() {
		document.querySelectorAll( '.sfls-chart[data-chart]' ).forEach( function ( node ) {
			try {
				render( node, JSON.parse( node.getAttribute( 'data-chart' ) ) );
			} catch ( e ) {
				node.textContent = '';
			}
		} );
	}

	window.SFLSCharts = { render: render };
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', renderAll );
	} else {
		renderAll();
	}
}() );
