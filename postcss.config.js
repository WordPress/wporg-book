// postcss-load-config drops CLI options like --no-map unless the config passes them through.
module.exports = ( ctx ) => ( {
	map: ctx.options.map,
	plugins: [ require( '@csstools/postcss-sass' ), require( 'autoprefixer' ) ],
} );
