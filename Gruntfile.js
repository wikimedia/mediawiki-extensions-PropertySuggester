/* eslint-env node, es6 */
module.exports = function ( grunt ) {
	const conf = grunt.file.readJSON( 'extension.json' ),
		bananaConfig = conf.MessagesDirs;
	bananaConfig.options = {
		disallowDuplicateTranslations: false,
		disallowUnusedTranslations: false
	};
	grunt.loadNpmTasks( 'grunt-banana-checker' );
	grunt.loadNpmTasks( 'grunt-eslint' );

	grunt.initConfig( {
		eslint: {
			options: {
				cache: true
			},
			all: [
				'**/*.js{,on}',
				'!{vendor,node_modules}/**'
			]
		},
		banana: bananaConfig
	} );

	grunt.registerTask( 'test', [ 'eslint', 'banana' ] );
	grunt.registerTask( 'default', 'test' );
};
