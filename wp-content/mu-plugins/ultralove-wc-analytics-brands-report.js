( function () {
	if ( ! window.wcAdminFeatures ) {
		window.wcAdminFeatures = {};
	}

	if (
		! window.wp ||
		! window.wp.hooks ||
		! window.wp.element ||
		! window.wp.apiFetch ||
		! window.wp.i18n
	) {
		return;
	}

	var addFilter = window.wp.hooks.addFilter;
	var el = window.wp.element.createElement;
	var useEffect = window.wp.element.useEffect;
	var useState = window.wp.element.useState;
	var __ = window.wp.i18n.__;
	var apiFetch = window.wp.apiFetch;
	var addQueryArgs = window.wp.url && window.wp.url.addQueryArgs;
	var components = window.wp.components || {};
	var Spinner = components.Spinner || function () { return null; };
	var Notice = components.Notice || function ( props ) { return el( 'div', null, props.children ); };
	var locale = ( window.wcSettings && window.wcSettings.admin && window.wcSettings.admin.locale && window.wcSettings.admin.locale.userLocale ) || 'en-CA';
	var currencyCode = ( window.wcSettings && window.wcSettings.currency && window.wcSettings.currency.code ) || 'CAD';

	function toInt( value, fallback ) {
		var parsed = parseInt( value, 10 );
		return Number.isFinite( parsed ) ? parsed : fallback;
	}

	function formatNumber( value ) {
		var number = Number( value || 0 );
		try {
			return number.toLocaleString( locale );
		} catch ( e ) {
			return String( number );
		}
	}

	function formatCurrency( value ) {
		var amount = Number( value || 0 );
		try {
			return new Intl.NumberFormat( locale, {
				style: 'currency',
				currency: currencyCode,
			} ).format( amount );
		} catch ( e ) {
			return '$' + amount.toFixed( 2 );
		}
	}

	function clamp( value, min, max ) {
		return Math.min( Math.max( value, min ), max );
	}

	function buildReportPath( query ) {
		var isSingleBrand = query && query.filter === 'single_brand' && query.brands;
		var params = {
			page: toInt( query && query.paged, 1 ),
			per_page: toInt( query && query.per_page, 150 ),
			orderby: ( query && query.orderby ) || 'items_sold',
			order: ( query && query.order ) || 'desc',
		};

		if ( query && query.after ) {
			params.after = query.after;
		}

		if ( query && query.before ) {
			params.before = query.before;
		}

		if ( isSingleBrand ) {
			params.brands = query.brands;
		}

		if ( addQueryArgs ) {
			return addQueryArgs(
				isSingleBrand ? '/wc-analytics/reports/brands/products' : '/wc-analytics/reports/brands',
				params
			);
		}

		return isSingleBrand ? '/wc-analytics/reports/brands/products' : '/wc-analytics/reports/brands';
	}

	function buildStatsPath( query ) {
		var params = {
			interval: ( query && query.interval ) || 'day',
		};

		if ( query && query.after ) {
			params.after = query.after;
		}

		if ( query && query.before ) {
			params.before = query.before;
		}

		if ( query && query.filter === 'single_brand' && query.brands ) {
			params.brands = query.brands;
		}

		if ( addQueryArgs ) {
			return addQueryArgs( '/wc-analytics/reports/brands/stats', params );
		}

		return '/wc-analytics/reports/brands/stats';
	}

	function buildBrandsAdminPath( query, overrides ) {
		var navigation = window.wc && window.wc.navigation;
		var nextQuery = {};
		var key;

		for ( key in ( query || {} ) ) {
			if ( Object.prototype.hasOwnProperty.call( query || {}, key ) ) {
				nextQuery[ key ] = query[ key ];
			}
		}

		for ( key in ( overrides || {} ) ) {
			if ( Object.prototype.hasOwnProperty.call( overrides || {}, key ) ) {
				if ( overrides[ key ] === null ) {
					delete nextQuery[ key ];
				} else {
					nextQuery[ key ] = overrides[ key ];
				}
			}
		}

		if ( navigation && navigation.getNewPath && navigation.getPersistedQuery ) {
			return navigation.getNewPath(
				navigation.getPersistedQuery( nextQuery ),
				'/analytics/brands',
				nextQuery
			);
		}

		if ( addQueryArgs ) {
			return addQueryArgs( 'admin.php?page=wc-admin&path=/analytics/brands', nextQuery );
		}

		return 'admin.php?page=wc-admin&path=%2Fanalytics%2Fbrands';
	}

	function buildSingleBrandAdminPath( query, brandId ) {
		return buildBrandsAdminPath( query, {
			filter: 'single_brand',
			brands: brandId,
		} );
	}

	function formatDateLabel( value ) {
		if ( ! value ) {
			return '';
		}

		try {
			return new Date( value ).toLocaleDateString( locale, {
				month: 'short',
				day: 'numeric',
			} );
		} catch ( e ) {
			return String( value ).slice( 0, 10 );
		}
	}

	function toDateInputValue( value ) {
		return value ? String( value ).slice( 0, 10 ) : '';
	}

	function formatDateForQuery( date, endOfDay ) {
		var year = date.getFullYear();
		var month = String( date.getMonth() + 1 ).padStart( 2, '0' );
		var day = String( date.getDate() ).padStart( 2, '0' );
		return year + '-' + month + '-' + day + ( endOfDay ? ' 23:59:59' : ' 00:00:00' );
	}

	function getPresetRange( preset ) {
		var today = new Date();
		var start = new Date( today.getFullYear(), today.getMonth(), today.getDate() );
		var end = new Date( today.getFullYear(), today.getMonth(), today.getDate() );

		if ( 'all' === preset ) {
			return {
				after: null,
				before: null,
				interval: 'month',
			};
		}

		if ( 'mtd' === preset ) {
			start = new Date( today.getFullYear(), today.getMonth(), 1 );
			return {
				after: formatDateForQuery( start, false ),
				before: formatDateForQuery( end, true ),
				interval: 'day',
			};
		}

		var days = preset;
		start.setDate( start.getDate() - ( days - 1 ) );
		return {
			after: formatDateForQuery( start, false ),
			before: formatDateForQuery( end, true ),
			interval: days > 31 ? 'week' : 'day',
		};
	}

	function buildChartPoints( intervals, field ) {
		var width = 320;
		var height = 120;
		var padding = 12;
		var values = ( intervals || [] ).map( function ( item ) {
			return Number( item && item[ field ] ? item[ field ] : 0 );
		} );
		var max = Math.max.apply( null, values.concat( [ 1 ] ) );
		var stepX = values.length > 1 ? ( width - padding * 2 ) / ( values.length - 1 ) : 0;

		return values.map( function ( value, index ) {
			var x = padding + stepX * index;
			var y = height - padding - ( ( value / max ) * ( height - padding * 2 ) );
			return x + ',' + y;
		} ).join( ' ' );
	}

	function renderChartCard( title, total, intervals, field, formatter ) {
		var values = ( intervals || [] ).map( function ( item ) {
			return Number( item && item[ field ] ? item[ field ] : 0 );
		} );
		var latestValue = values.length ? values[ values.length - 1 ] : 0;
		var previousValue = values.length > 1 ? values[ values.length - 2 ] : latestValue;
		var delta = previousValue ? ( ( latestValue - previousValue ) / previousValue ) * 100 : 0;
		var deltaColor = delta >= 0 ? '#0a7f46' : '#b32d2e';
		var points = buildChartPoints( intervals, field );
		var firstLabel = intervals && intervals.length ? formatDateLabel( intervals[ 0 ].date_start ) : '';
		var lastLabel = intervals && intervals.length ? formatDateLabel( intervals[ intervals.length - 1 ].date_end ) : '';

		return el(
			'div',
			{
				style: {
					background: 'linear-gradient(180deg, #ffffff 0%, #f5f8fb 100%)',
					border: '1px solid #d7dee6',
					borderRadius: '16px',
					padding: '18px',
					boxShadow: '0 10px 25px rgba(20, 38, 55, 0.05)',
				},
			},
			el(
				'div',
				{ style: { display: 'flex', justifyContent: 'space-between', gap: '12px', alignItems: 'flex-start', marginBottom: '12px' } },
				el( 'div', null,
					el( 'div', { style: { fontSize: '12px', letterSpacing: '0.08em', textTransform: 'uppercase', color: '#6b7280', marginBottom: '6px' } }, title ),
					el( 'div', { style: { fontSize: '28px', fontWeight: '700', color: '#111827', lineHeight: '1.1' } }, formatter( total ) )
				),
				el( 'div', { style: { fontSize: '12px', fontWeight: '600', color: deltaColor, background: '#fff', border: '1px solid #e5e7eb', borderRadius: '999px', padding: '6px 10px', whiteSpace: 'nowrap' } },
					( delta >= 0 ? '+' : '' ) + clamp( delta, -999, 999 ).toFixed( 1 ) + '%'
				)
			),
			el(
				'svg',
				{ viewBox: '0 0 320 120', style: { width: '100%', height: '120px', display: 'block', marginBottom: '10px' } },
				el( 'defs', null,
					el( 'linearGradient', { id: title.replace( /\s+/g, '-' ).toLowerCase() + '-stroke', x1: '0%', y1: '0%', x2: '100%', y2: '0%' },
						el( 'stop', { offset: '0%', stopColor: '#14532d' } ),
						el( 'stop', { offset: '100%', stopColor: '#0ea5e9' } )
					)
				),
				el( 'polyline', {
					fill: 'none',
					stroke: '#dbe4ec',
					strokeWidth: '1',
					points: '12,108 308,108',
				} ),
				el( 'polyline', {
					fill: 'none',
					stroke: 'url(#' + title.replace( /\s+/g, '-' ).toLowerCase() + '-stroke)',
					strokeWidth: '3',
					strokeLinecap: 'round',
					strokeLinejoin: 'round',
					points: points,
				} )
			),
			el(
				'div',
				{ style: { display: 'flex', justifyContent: 'space-between', fontSize: '12px', color: '#6b7280' } },
				el( 'span', null, firstLabel ),
				el( 'span', null, lastLabel )
			)
		);
	}

	function getSelectedBrandLabel( stats, singleBrandId ) {
		if ( stats && stats.selected_brand && stats.selected_brand.name ) {
			if ( stats.selected_brand.id ) {
				return stats.selected_brand.name + ' (#' + String( stats.selected_brand.id ) + ')';
			}
			return stats.selected_brand.name;
		}

		return singleBrandId ? '#' + String( singleBrandId ) : '';
	}

	function getActivePreset( query ) {
		var after = query && query.after ? String( query.after ).slice( 0, 10 ) : '';
		var before = query && query.before ? String( query.before ).slice( 0, 10 ) : '';
		var interval = query && query.interval ? query.interval : 'day';
		var today = new Date();
		var end = formatDateForQuery( new Date( today.getFullYear(), today.getMonth(), today.getDate() ), true ).slice( 0, 10 );

		if ( ! after && ! before && interval === 'month' ) {
			return 'all';
		}

		if ( before !== end ) {
			return '';
		}

		var presets = [ 7, 30, 90, 'mtd' ];
		for ( var i = 0; i < presets.length; i++ ) {
			var preset = presets[ i ];
			var range = getPresetRange( preset );
			if ( String( range.after || '' ).slice( 0, 10 ) === after && String( range.before || '' ).slice( 0, 10 ) === before && range.interval === interval ) {
				return String( preset );
			}
		}

		return '';
	}

	function renderSummaryCard( label, value ) {
		return el(
			'div',
			{
				style: {
					background: '#f8fafc',
					border: '1px solid #e2e8f0',
					borderRadius: '14px',
					padding: '14px 16px',
				},
			},
			el( 'div', { style: { fontSize: '12px', color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: '6px' } }, label ),
			el( 'div', { style: { fontSize: '24px', fontWeight: '700', color: '#0f172a' } }, value )
		);
	}

	function parseSingleBrandId( query ) {
		if ( ! query || query.filter !== 'single_brand' || ! query.brands ) {
			return null;
		}

		var raw = String( query.brands ).split( ',' )[ 0 ];
		var id = parseInt( raw, 10 );
		return Number.isFinite( id ) && id > 0 ? id : null;
	}

	function BrandsReport( props ) {
		var query = props && props.query ? props.query : {};
		var singleBrandId = parseSingleBrandId( query );
		var isSingleBrandView = !! singleBrandId;
		var _useState = useState( true );
		var isLoading = _useState[ 0 ];
		var setIsLoading = _useState[ 1 ];
		var _useState2 = useState( null );
		var error = _useState2[ 0 ];
		var setError = _useState2[ 1 ];
		var _useState3 = useState( [] );
		var rows = _useState3[ 0 ];
		var setRows = _useState3[ 1 ];
		var _useState4 = useState( null );
		var stats = _useState4[ 0 ];
		var setStats = _useState4[ 1 ];
		var _useState5 = useState( '' );
		var customAfter = _useState5[ 0 ];
		var setCustomAfter = _useState5[ 1 ];
		var _useState6 = useState( '' );
		var customBefore = _useState6[ 0 ];
		var setCustomBefore = _useState6[ 1 ];

		useEffect(
			function () {
				var alive = true;
				setIsLoading( true );
				setError( null );
				setCustomAfter( toDateInputValue( query.after ) );
				setCustomBefore( toDateInputValue( query.before ) );

				Promise.all( [
					apiFetch( { path: buildReportPath( query ), method: 'GET' } ),
					apiFetch( { path: buildStatsPath( query ), method: 'GET' } ),
				] )
					.then( function ( responses ) {
						if ( ! alive ) {
							return;
						}
						setRows( Array.isArray( responses[ 0 ] ) ? responses[ 0 ] : [] );
						setStats( responses[ 1 ] && typeof responses[ 1 ] === 'object' ? responses[ 1 ] : null );
						setIsLoading( false );
					} )
					.catch( function () {
						if ( ! alive ) {
							return;
						}
						setError( __( 'Unable to load brands analytics data.', 'ultralove' ) );
						setIsLoading( false );
					} );

				return function () {
					alive = false;
				};
			},
			[
				query.after,
				query.before,
				query.interval,
				query.filter,
				query.brands,
				query.order,
				query.orderby,
				query.paged,
				query.per_page,
			]
		);

		if ( isLoading ) {
			return el(
				'div',
				{ className: 'woocommerce-layout__loading' },
				el( Spinner, null )
			);
		}

		if ( error ) {
			return el(
				Notice,
				{ status: 'error', isDismissible: false },
				error
			);
		}

		if ( ! rows.length ) {
			return el(
				Notice,
				{ status: 'info', isDismissible: false },
				singleBrandId
					? __( 'No analytics data found for this brand in the selected period.', 'ultralove' )
					: __( 'No brand analytics data found for the selected period.', 'ultralove' )
			);
		}

		var summary = rows.reduce(
			function (carry, row) {
				carry.items_sold += Number( row.items_sold || 0 );
				carry.net_revenue += Number( row.net_revenue || 0 );
				carry.orders_count += Number( row.orders_count || 0 );
				carry.products_count += Number( row.products_count || 0 );
				return carry;
			},
			{ items_sold: 0, net_revenue: 0, orders_count: 0, products_count: 0 }
		);

		if ( stats && stats.totals ) {
			summary.items_sold = Number( stats.totals.items_sold || 0 );
			summary.net_revenue = Number( stats.totals.net_revenue || 0 );
			summary.orders_count = Number( stats.totals.orders_count || 0 );
		}

		if ( isSingleBrandView ) {
			summary.products_count = rows.length;
		}

		function applyDateOverrides( overrides ) {
			window.location.href = buildBrandsAdminPath( query, overrides );
		}

		var activePreset = getActivePreset( query );
		var selectedBrandLabel = getSelectedBrandLabel( stats, singleBrandId );

		var heading = singleBrandId
			? selectedBrandLabel
			: __( 'Brands breakdown', 'ultralove' );
		var subheading = singleBrandId
			? __( 'Product performance for the selected brand across the chosen date range.', 'ultralove' )
			: __( 'Metrics grouped by brand for the selected reporting window.', 'ultralove' );

		try {
			return el(
				'div',
				{
					className: 'ultralove-brands-report',
					style: {
						padding: '24px',
						background: 'linear-gradient(180deg, #ffffff 0%, #f8fafc 100%)',
						border: '1px solid #d7dee6',
						borderRadius: '20px',
						boxShadow: '0 18px 45px rgba(15, 23, 42, 0.06)',
					},
				},
				el(
					'div',
					{ style: { display: 'flex', justifyContent: 'space-between', gap: '16px', alignItems: 'flex-start', flexWrap: 'wrap', marginBottom: '20px' } },
					el(
						'div',
						{ style: { minWidth: '240px', flex: '1 1 320px' } },
						el( 'div', { style: { fontSize: '12px', fontWeight: '700', letterSpacing: '0.12em', textTransform: 'uppercase', color: '#0f766e', marginBottom: '10px' } }, singleBrandId ? __( 'Brand drilldown', 'ultralove' ) : __( 'Custom report', 'ultralove' ) ),
						el( 'h2', { style: { marginTop: 0, marginBottom: '8px', fontSize: '32px', lineHeight: '1.1', color: '#0f172a' } }, heading ),
						el( 'p', { style: { margin: 0, maxWidth: '720px', color: '#475569', fontSize: '14px', lineHeight: '1.6' } }, subheading ),
						singleBrandId
							? el(
								'div',
								{ style: { display: 'flex', gap: '10px', alignItems: 'center', flexWrap: 'wrap', marginTop: '14px' } },
								el( 'span', { style: { display: 'inline-flex', alignItems: 'center', borderRadius: '999px', padding: '8px 12px', background: '#dcfce7', color: '#166534', fontWeight: '700', fontSize: '12px', textTransform: 'uppercase', letterSpacing: '0.06em' } }, selectedBrandLabel ),
								el( 'a', { href: buildBrandsAdminPath( query, { filter: null, brands: null } ), style: { color: '#0369a1', fontWeight: '600', textDecoration: 'none' } }, __( 'Back to all brands', 'ultralove' ) )
							)
							: null
					),
					el(
						'div',
						{ style: { display: 'grid', gridTemplateColumns: 'repeat(2, minmax(120px, 1fr))', gap: '10px', minWidth: '240px', flex: '0 1 320px' } },
						renderSummaryCard( __( 'Items sold', 'ultralove' ), formatNumber( summary.items_sold ) ),
						renderSummaryCard( __( 'Net sales', 'ultralove' ), formatCurrency( summary.net_revenue ) ),
						renderSummaryCard( __( 'Orders', 'ultralove' ), formatNumber( summary.orders_count ) ),
						renderSummaryCard( __( 'Products', 'ultralove' ), formatNumber( summary.products_count ) )
					)
				),
				el(
					'div',
					{ style: { display: 'flex', flexWrap: 'wrap', gap: '8px', marginBottom: '18px', alignItems: 'center' } },
					[ 7, 30, 90 ].map( function ( days ) {
						var isActive = activePreset === String( days );
						return el(
							'button',
							{
								type: 'button',
								onClick: function () { applyDateOverrides( getPresetRange( days ) ); },
								style: { padding: '8px 12px', cursor: 'pointer', borderRadius: '999px', border: isActive ? '1px solid #0f766e' : '1px solid #cbd5e1', background: isActive ? '#ccfbf1' : '#fff', color: isActive ? '#115e59' : '#334155', fontWeight: '600' },
								key: String( days ),
							},
							days + 'd'
						);
					} ).concat( [
						el( 'button', { type: 'button', onClick: function () { applyDateOverrides( getPresetRange( 'mtd' ) ); }, style: { padding: '8px 12px', cursor: 'pointer', borderRadius: '999px', border: activePreset === 'mtd' ? '1px solid #0f766e' : '1px solid #cbd5e1', background: activePreset === 'mtd' ? '#ccfbf1' : '#fff', color: activePreset === 'mtd' ? '#115e59' : '#334155', fontWeight: '600' }, key: 'mtd' }, __( 'MTD', 'ultralove' ) ),
						el( 'button', { type: 'button', onClick: function () { applyDateOverrides( getPresetRange( 'all' ) ); }, style: { padding: '8px 12px', cursor: 'pointer', borderRadius: '999px', border: activePreset === 'all' ? '1px solid #0f766e' : '1px solid #cbd5e1', background: activePreset === 'all' ? '#ccfbf1' : '#fff', color: activePreset === 'all' ? '#115e59' : '#334155', fontWeight: '600' }, key: 'all' }, __( 'All time', 'ultralove' ) ),
						el( 'input', { type: 'date', value: customAfter, onChange: function ( event ) { setCustomAfter( event.target.value ); }, style: { border: '1px solid #cbd5e1', borderRadius: '10px', padding: '8px 10px', background: '#fff' }, key: 'after' } ),
						el( 'input', { type: 'date', value: customBefore, onChange: function ( event ) { setCustomBefore( event.target.value ); }, style: { border: '1px solid #cbd5e1', borderRadius: '10px', padding: '8px 10px', background: '#fff' }, key: 'before' } ),
						el( 'button', {
							type: 'button',
							onClick: function () {
								applyDateOverrides( {
									after: customAfter ? customAfter + ' 00:00:00' : null,
									before: customBefore ? customBefore + ' 23:59:59' : null,
									interval: 'day',
								} );
							},
							style: { padding: '8px 14px', cursor: 'pointer', borderRadius: '10px', border: '1px solid #0f766e', background: '#115e59', color: '#fff', fontWeight: '600' },
							key: 'apply',
						}, __( 'Apply', 'ultralove' ) ),
					] )
				),
				stats && stats.intervals && stats.intervals.length
					? el(
						'div',
						{
							style: {
								display: 'grid',
								gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
								gap: '16px',
								marginBottom: '20px',
							},
						},
						renderChartCard( __( 'Items sold', 'ultralove' ), summary.items_sold, stats.intervals, 'items_sold', formatNumber ),
						renderChartCard( __( 'Net sales', 'ultralove' ), summary.net_revenue, stats.intervals, 'net_revenue', formatCurrency ),
						renderChartCard( __( 'Orders', 'ultralove' ), summary.orders_count, stats.intervals, 'orders_count', formatNumber )
					)
					: null,
				el(
					'table',
					{ className: 'widefat striped', style: { border: '1px solid #e2e8f0', borderRadius: '14px', overflow: 'hidden', background: '#fff' } },
					el(
						'thead',
						null,
						el(
							'tr',
							null,
							el( 'th', null, isSingleBrandView ? __( 'Product', 'ultralove' ) : __( 'Brand', 'ultralove' ) ),
							el( 'th', null, __( 'Items sold', 'ultralove' ) ),
							el( 'th', null, __( 'Net sales', 'ultralove' ) ),
							el( 'th', null, __( 'Orders', 'ultralove' ) ),
							el( 'th', null, isSingleBrandView ? __( 'Product ID', 'ultralove' ) : __( 'Products', 'ultralove' ) )
						)
					),
					el(
						'tbody',
						null,
						rows.map( function ( row ) {
							var primaryName = row && row.extended_info && row.extended_info.name
								? row.extended_info.name
								: ( isSingleBrandView
									? '#' + String( row && row.product_id ? row.product_id : '' )
									: '#' + String( row && row.brand_id ? row.brand_id : '' ) );
							var primaryCell = primaryName;

							if ( ! isSingleBrandView && row && row.brand_id ) {
								primaryCell = el(
									'a',
									{ href: buildSingleBrandAdminPath( query, row.brand_id ) },
									primaryName
								);
							}

							return el(
								'tr',
								{ key: String( isSingleBrandView ? ( row && row.product_id ? row.product_id : primaryName ) : ( row && row.brand_id ? row.brand_id : primaryName ) ) },
								el( 'td', null, primaryCell ),
								el( 'td', null, formatNumber( row && row.items_sold ) ),
								el( 'td', null, formatCurrency( row && row.net_revenue ) ),
								el( 'td', null, formatNumber( row && row.orders_count ) ),
								el( 'td', null, formatNumber( isSingleBrandView ? ( row && row.product_id ) : ( row && row.products_count ) ) )
							);
						} )
					)
				)
			);
		} catch ( renderError ) {
			if ( window.console && window.console.error ) {
				window.console.error( 'UltraLove Brands report render failed', renderError );
			}

			return el(
				'div',
				{
					className: 'notice notice-error',
					style: { margin: '24px 0', padding: '12px' },
				},
				__( 'The Brands report failed to render.', 'ultralove' )
			);
		}
	}

	addFilter(
		'woocommerce_admin_reports_list',
		'ultralove/brands-report',
		function ( reports ) {
			if ( ! Array.isArray( reports ) ) {
				return reports;
			}

			var exists = reports.some( function ( report ) {
				return report && report.report === 'brands';
			} );

			if ( exists ) {
				return reports;
			}

			var custom = {
				report: 'brands',
				title: __( 'Brands', 'ultralove' ),
				component: BrandsReport,
				navArgs: { id: 'woocommerce-analytics-brands' },
			};

			var categoriesIndex = reports.findIndex( function ( report ) {
				return report && report.report === 'categories';
			} );

			if ( categoriesIndex >= 0 ) {
				reports.splice( categoriesIndex + 1, 0, custom );
				return reports;
			}

			reports.push( custom );
			return reports;
		}
	);
} )();
