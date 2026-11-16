/**
 * SubtleForms License Server: dependency-free SVG charts and dashboard controls.
 *
 * <div class="sfls-chart" data-chart='{"type":"bar|spark|donut","labels":[],
 *   "series":[{"name":"","data":[],"kind":"bar|line","color":""}],
 *   "format":"number|money|percent","currency":"USD","unit":""}'></div>
 *
 * Every SVG carries role="img" and an aria-label; a screen-reader table follows it.
 * Shapes have a <title> (native tooltip fallback) which is swapped for a styled
 * tooltip on first hover. Also window.SFLSCharts.render( el, config ).
 */
( function () {
	'use strict';

	var NS = 'http://www.w3.org/2000/svg';
	var ACCENT = 'var(--wp-admin-theme-color, #2271b1)';
	var NEG = '#d63638';
	// Accent plus four distinct hues.
	var PALETTE = [ ACCENT, '#8250df', '#0e9f8e', '#e07b00', '#d6479a', '#5c6bc0' ];

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

	function html( name, cls, text ) {
		var node = document.createElement( name );
		if ( cls ) {
			node.className = cls;
		}
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
				opts = { style: 'currency', currency: cfg.currency || 'USD', currencyDisplay: 'narrowSymbol', maximumFractionDigits: 2 };
			} else if ( cfg.format === 'percent' ) {
				opts = { maximumFractionDigits: 1 };
			}
			var nf = new Intl.NumberFormat( locale, opts );
			return function ( v ) {
				return nf.format( v ).replace( '-', '−' ) + ( cfg.format === 'percent' ? '%' : '' );
			};
		} catch ( e ) {
			return function ( v ) {
				return String( v );
			};
		}
	}

	function compact( v, fmt, cfg ) {
		if ( Math.abs( v ) < 1000 ) {
			return fmt( v );
		}
		try {
			var opts = { notation: 'compact', maximumFractionDigits: 1 };
			if ( cfg.format === 'money' ) {
				opts.style = 'currency';
				opts.currency = cfg.currency || 'USD';
				opts.currencyDisplay = 'narrowSymbol';
			}
			return new Intl.NumberFormat( document.documentElement.lang || undefined, opts ).format( v ).replace( '-', '−' );
		} catch ( e ) {
			return fmt( v );
		}
	}

	function num( v ) {
		return Number( v ) || 0;
	}

	// A round step for about four gridline intervals.
	function niceStep( range ) {
		if ( range <= 0 ) {
			return 1;
		}
		var raw = range / 4;
		var pow = Math.pow( 10, Math.floor( Math.log10( raw ) ) );
		var n = raw / pow;
		return ( n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10 ) * pow;
	}

	function table( cfg, fmt ) {
		var t = html( 'table', 'screen-reader-text' );
		var thead = html( 'thead' );
		var hr = html( 'tr' );
		hr.appendChild( html( 'th' ) );
		cfg.series.forEach( function ( s ) {
			var th = html( 'th', '', s.name || '' );
			th.scope = 'col';
			hr.appendChild( th );
		} );
		thead.appendChild( hr );
		t.appendChild( thead );
		var body = html( 'tbody' );
		( cfg.labels || [] ).forEach( function ( label, r ) {
			var tr = html( 'tr' );
			var th = html( 'th', '', label );
			th.scope = 'row';
			tr.appendChild( th );
			cfg.series.forEach( function ( s ) {
				tr.appendChild( html( 'td', '', fmt( num( ( s.data || [] )[ r ] ) ) ) );
			} );
			body.appendChild( tr );
		} );
		t.appendChild( body );
		return t;
	}

	function legend( items, rich ) {
		var ul = html( 'ul', 'sfls-chart__legend' );
		items.forEach( function ( item ) {
			var li = html( 'li' );
			var sw = html( 'span', 'sfls-chart__swatch' + ( item.line ? ' sfls-chart__swatch--line' : '' ) );
			sw.style.background = item.colour;
			li.appendChild( sw );
			li.appendChild( document.createTextNode( item.name ) );
			if ( rich ) {
				li.appendChild( html( 'span', 'sfls-chart__count', item.count ) );
				li.appendChild( html( 'span', 'sfls-chart__share', item.share ) );
			}
			ul.appendChild( li );
		} );
		return ul;
	}

	// Styled tooltip for every [data-tip] shape; its <title> is dropped on first hover.
	function tooltips( host, svg ) {
		var tip = html( 'div', 'sfls-chart__tip' );
		tip.hidden = true;
		tip.setAttribute( 'role', 'presentation' );
		host.appendChild( tip );

		function show( node, x, y ) {
			var title = node.querySelector( 'title' );
			if ( title ) {
				node.setAttribute( 'data-tip', title.textContent );
				node.removeChild( title );
			}
			var lines = ( node.getAttribute( 'data-tip' ) || '' ).split( '\n' );
			tip.textContent = '';
			tip.appendChild( html( 'strong', '', lines[ 0 ] ) );
			lines.slice( 1 ).forEach( function ( line ) {
				var parts = line.split( '\t' );
				var row = html( 'span' );
				var left = html( 'span' );
				if ( parts[ 2 ] ) {
					var dot = html( 'i' );
					dot.style.background = parts[ 2 ];
					left.appendChild( dot );
				}
				left.appendChild( document.createTextNode( parts[ 0 ] ) );
				row.appendChild( left );
				row.appendChild( html( 'b', '', parts[ 1 ] || '' ) );
				tip.appendChild( row );
			} );
			tip.hidden = false;
			var box = host.getBoundingClientRect();
			var half = tip.offsetWidth / 2;
			tip.style.left = Math.max( half, Math.min( box.width - half, x - box.left ) ) + 'px';
			tip.style.top = ( y - box.top ) + 'px';
		}

		svg.querySelectorAll( '.sfls-chart__hit, .sfls-chart__slice' ).forEach( function ( node ) {
			node.addEventListener( 'mousemove', function ( e ) {
				show( node, e.clientX, e.clientY );
			} );
			node.addEventListener( 'mouseleave', function () {
				tip.hidden = true;
			} );
			node.addEventListener( 'focus', function () {
				var r = node.getBoundingClientRect();
				show( node, r.left + r.width / 2, r.top + Math.min( r.height, 40 ) / 2 );
			} );
			node.addEventListener( 'blur', function () {
				tip.hidden = true;
			} );
		} );
	}

	function bars( cfg, fmt, width ) {
		var W = width, H = 300, L = 56, R = 12, T = 12, B = 30;
		var labels = cfg.labels || [];
		var n = labels.length;
		var lo = 0, hi = 0;
		cfg.series.forEach( function ( s ) {
			( s.data || [] ).forEach( function ( v ) {
				lo = Math.min( lo, num( v ) );
				hi = Math.max( hi, num( v ) );
			} );
		} );
		var step = niceStep( hi - lo );
		lo = Math.floor( lo / step ) * step;
		hi = Math.ceil( hi / step ) * step;
		if ( hi === lo ) {
			hi = lo + step;
		}
		var pw = W - L - R, ph = H - T - B;
		var svg = el( 'svg', { viewBox: '0 0 ' + W + ' ' + H, focusable: 'false', 'class': 'sfls-chart__svg' } );
		var y = function ( v ) {
			return T + ph - ( ( v - lo ) / ( hi - lo ) ) * ph;
		};
		for ( var g = lo; g <= hi + step / 2; g += step ) {
			var zero = Math.abs( g ) < step / 1000;
			svg.appendChild( el( 'line', { x1: L, x2: W - R, y1: y( g ), y2: y( g ), stroke: zero ? '#a7aaad' : '#e2e4e7', 'stroke-width': '1' } ) );
			svg.appendChild( el( 'text', { x: L - 8, y: y( g ) + 4, 'text-anchor': 'end' }, compact( g, fmt, cfg ) ) );
		}
		var band = n ? pw / n : pw;
		var skip = Math.ceil( n / 12 );
		labels.forEach( function ( label, i ) {
			if ( i % skip === 0 ) {
				svg.appendChild( el( 'text', { x: L + band * i + band / 2, y: H - 10, 'text-anchor': 'middle' }, label ) );
			}
		} );
		var barSeries = cfg.series.map( function ( s, i ) {
			return { s: s, i: i };
		} ).filter( function ( o ) {
			return o.s.kind !== 'line';
		} );
		var k = barSeries.length || 1;
		var gap = Math.min( 4, band * 0.06 );
		var bw = Math.min( 36, ( band * 0.64 - gap * ( k - 1 ) ) / k );
		var span = bw * k + gap * ( k - 1 );
		barSeries.forEach( function ( o, bi ) {
			var c = o.s.color || colour( o.i );
			labels.forEach( function ( label, i ) {
				var v = num( ( o.s.data || [] )[ i ] );
				if ( ! v ) {
					return;
				}
				var top = y( Math.max( v, 0 ) ), bottom = y( Math.min( v, 0 ) );
				svg.appendChild( el( 'rect', { x: L + band * i + ( band - span ) / 2 + bi * ( bw + gap ), y: top, width: bw, height: Math.max( bottom - top, 1 ), rx: 3, fill: v < 0 ? NEG : c } ) );
			} );
		} );
		cfg.series.forEach( function ( s, si ) {
			if ( s.kind !== 'line' ) {
				return;
			}
			var c = s.color || colour( si );
			var pts = labels.map( function ( label, i ) {
				return ( L + band * i + band / 2 ) + ',' + y( num( ( s.data || [] )[ i ] ) );
			} );
			svg.appendChild( el( 'polyline', { points: pts.join( ' ' ), fill: 'none', stroke: c, 'stroke-width': '2', 'stroke-linejoin': 'round', 'stroke-linecap': 'round' } ) );
			labels.forEach( function ( label, i ) {
				svg.appendChild( el( 'circle', { cx: L + band * i + band / 2, cy: y( num( ( s.data || [] )[ i ] ) ), r: 3, fill: '#fff', stroke: c, 'stroke-width': '2' } ) );
			} );
		} );
		// One hover band per category with every series in the tooltip.
		labels.forEach( function ( label, i ) {
			var tip = [ label ].concat( cfg.series.map( function ( s, si ) {
				var v = num( ( s.data || [] )[ i ] );
				return ( s.name || '' ) + '\t' + fmt( v ) + '\t' + ( v < 0 && s.kind !== 'line' ? NEG : ( s.color || colour( si ) ) );
			} ) ).join( '\n' );
			var hit = el( 'rect', { x: L + band * i, y: T, width: band, height: ph, 'class': 'sfls-chart__hit', tabindex: '0', 'aria-label': tip.replace( /\t/g, ': ' ).replace( /\n/g, ', ' ) } );
			hit.appendChild( el( 'title', {}, tip.split( '\n' ).map( function ( line ) {
				return line.split( '\t' ).slice( 0, 2 ).join( ': ' );
			} ).join( '\n' ) ) );
			hit.setAttribute( 'data-tip', tip );
			svg.appendChild( hit );
		} );
		return svg;
	}

	function spark( cfg, fmt, width ) {
		var W = width, H = 210, P = 8, B = 18;
		var s = cfg.series[ 0 ] || {};
		var data = ( s.data || [] ).map( num );
		var labels = cfg.labels || [];
		var n = data.length;
		var lo = Math.min.apply( null, data.concat( 0 ) ), hi = Math.max.apply( null, data.concat( 0 ) );
		if ( hi === lo ) {
			hi = lo + 1;
		}
		var ph = H - P * 2 - B;
		var x = function ( i ) {
			return n > 1 ? P + ( ( W - P * 2 ) * i ) / ( n - 1 ) : W / 2;
		};
		var y = function ( v ) {
			return P + ph - ( ( v - lo ) / ( hi - lo ) ) * ph;
		};
		var svg = el( 'svg', { viewBox: '0 0 ' + W + ' ' + H, focusable: 'false', 'class': 'sfls-chart__svg' } );
		svg.appendChild( el( 'line', { x1: P, x2: W - P, y1: y( 0 ), y2: y( 0 ), stroke: '#a7aaad', 'stroke-dasharray': '3 3' } ) );
		var pts = data.map( function ( v, i ) {
			return x( i ) + ',' + y( v );
		} );
		if ( n > 1 ) {
			svg.appendChild( el( 'path', { d: 'M ' + x( 0 ) + ',' + y( 0 ) + ' L ' + pts.join( ' L ' ) + ' L ' + x( n - 1 ) + ',' + y( 0 ) + ' Z', fill: 'currentColor', 'fill-opacity': '.1' } ) );
			svg.appendChild( el( 'polyline', { points: pts.join( ' ' ), fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linejoin': 'round', 'stroke-linecap': 'round' } ) );
		}
		data.forEach( function ( v, i ) {
			if ( v !== 0 || i === n - 1 ) {
				svg.appendChild( el( 'circle', { cx: x( i ), cy: y( v ), r: 3.5, fill: v < 0 ? NEG : 'currentColor', stroke: '#fff', 'stroke-width': '1.5' } ) );
			}
		} );
		if ( n ) {
			svg.appendChild( el( 'text', { x: P, y: H - 4 }, labels[ 0 ] ) );
			if ( n > 1 ) {
				svg.appendChild( el( 'text', { x: W - P, y: H - 4, 'text-anchor': 'end' }, labels[ n - 1 ] ) );
			}
		}
		var w = n > 1 ? ( W - P * 2 ) / ( n - 1 ) : W;
		data.forEach( function ( v, i ) {
			var hit = el( 'rect', { x: x( i ) - w / 2, y: 0, width: w, height: H - B, 'class': 'sfls-chart__hit', tabindex: '0', 'aria-label': labels[ i ] + ': ' + fmt( v ) } );
			hit.appendChild( el( 'title', {}, labels[ i ] + ': ' + fmt( v ) ) );
			hit.setAttribute( 'data-tip', labels[ i ] + '\n' + ( s.name || '' ) + '\t' + fmt( v ) + '\t' + ( v < 0 ? NEG : ACCENT ) );
			svg.appendChild( hit );
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

	function donut( cfg, fmt, items ) {
		var data = ( cfg.series[ 0 ] || {} ).data || [];
		var labels = cfg.labels || [];
		var total = 0;
		data.forEach( function ( v ) {
			total += Math.max( 0, num( v ) );
		} );
		var svg = el( 'svg', { viewBox: '0 0 220 220', focusable: 'false', 'class': 'sfls-chart__svg sfls-chart__svg--donut' } );
		var angle = -Math.PI / 2;
		labels.forEach( function ( label, i ) {
			var v = Math.max( 0, num( data[ i ] ) );
			var share = total ? v / total : 0;
			items.push( { name: label, colour: colour( i ), count: fmt( v ), share: ( share * 100 ).toFixed( 1 ) + '%' } );
			if ( ! v ) {
				return;
			}
			var node;
			if ( share >= 0.9999 ) {
				node = el( 'path', { d: arc( 110, 110, 70, 104, 0, Math.PI ) + ' ' + arc( 110, 110, 70, 104, Math.PI, Math.PI * 2 ), fill: colour( i ) } );
			} else {
				node = el( 'path', { d: arc( 110, 110, 70, 104, angle, angle + share * 2 * Math.PI ), fill: colour( i ), stroke: '#fff', 'stroke-width': '2' } );
			}
			node.setAttribute( 'class', 'sfls-chart__slice' );
			node.setAttribute( 'tabindex', '0' );
			node.setAttribute( 'aria-label', label + ': ' + fmt( v ) );
			node.setAttribute( 'data-tip', label + '\n' + ( cfg.unit || '' ) + '\t' + fmt( v ) + ' (' + ( share * 100 ).toFixed( 1 ) + '%)\t' + colour( i ) );
			node.appendChild( el( 'title', {}, label + ': ' + fmt( v ) + ' (' + ( share * 100 ).toFixed( 1 ) + '%)' ) );
			svg.appendChild( node );
			angle += share * 2 * Math.PI;
		} );
		svg.appendChild( el( 'text', { x: 110, y: 112, 'text-anchor': 'middle', 'class': 'sfls-chart__total' }, fmt( total ) ) );
		if ( cfg.unit ) {
			svg.appendChild( el( 'text', { x: 110, y: 132, 'text-anchor': 'middle' }, cfg.unit ) );
		}
		return svg;
	}

	function render( host, cfg ) {
		if ( ! host || ! cfg ) {
			return;
		}
		cfg.series = Array.isArray( cfg.series ) ? cfg.series : [];
		var fmt = formatter( cfg );
		var type = cfg.type === 'pie' ? 'donut' : ( cfg.type || 'bar' );
		host.textContent = '';
		host.classList.add( 'sfls-chart--' + type );
		var hasData = cfg.series.some( function ( s ) {
			return ( s.data || [] ).some( function ( v ) {
				return num( v ) !== 0;
			} );
		} );
		if ( ! hasData ) {
			host.appendChild( html( 'p', 'sfls-empty', cfg.empty || '-' ) );
			return;
		}
		var items = [];
		var width = Math.max( 280, Math.round( host.clientWidth ) || 720 );
		var svg = type === 'donut' ? donut( cfg, fmt, items ) : ( type === 'spark' ? spark( cfg, fmt, width ) : bars( cfg, fmt, width ) );
		svg.setAttribute( 'role', 'img' );
		svg.setAttribute( 'aria-label', ( cfg.title ? cfg.title + '. ' : '' ) + ( type === 'donut' ?
			items.map( function ( it ) {
				return it.name + ' ' + it.count;
			} ).join( ', ' ) :
			cfg.series.map( function ( s ) {
				return s.name;
			} ).join( ', ' ) ) );
		host.appendChild( svg );
		if ( type === 'donut' ) {
			host.appendChild( legend( items, true ) );
		} else if ( type === 'bar' ) {
			host.appendChild( legend( cfg.series.map( function ( s, i ) {
				return { name: s.name || '', colour: s.color || colour( i ), line: s.kind === 'line' };
			} ) ) );
		}
		host.appendChild( table( cfg, fmt ) );
		tooltips( host, svg );
	}

	function controls() {
		document.querySelectorAll( '.sfls-dash-filter' ).forEach( function ( form ) {
			var submit = function () {
				if ( form.requestSubmit ) {
					form.requestSubmit();
				} else {
					form.submit();
				}
			};
			form.querySelectorAll( 'input[name="range"]' ).forEach( function ( radio ) {
				radio.addEventListener( 'change', function () {
					if ( radio.value === 'custom' ) {
						form.classList.add( 'is-custom' );
						var first = form.querySelector( 'input[type="date"]' );
						if ( first ) {
							first.focus();
						}
					} else {
						submit();
					}
				} );
			} );
			var plan = form.querySelector( 'select[name="plan"]' );
			if ( plan ) {
				plan.addEventListener( 'change', submit );
			}
		} );
	}

	function drawAll() {
		document.querySelectorAll( '.sfls-chart[data-chart]' ).forEach( function ( node ) {
			try {
				render( node, JSON.parse( node.getAttribute( 'data-chart' ) ) );
			} catch ( e ) {
				node.textContent = '';
			}
		} );
	}

	function renderAll() {
		controls();
		drawAll();
		var last = window.innerWidth, timer;
		window.addEventListener( 'resize', function () {
			clearTimeout( timer );
			timer = setTimeout( function () {
				if ( window.innerWidth !== last ) {
					last = window.innerWidth;
					drawAll();
				}
			}, 200 );
		} );
	}

	window.SFLSCharts = { render: render };
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', renderAll );
	} else {
		renderAll();
	}
}() );
