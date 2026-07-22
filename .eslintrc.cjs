module.exports = {
	extends: [
		'@nextcloud',
	],
	settings: {
		'import/resolver': {
			node: {
				extensions: ['.js', '.ts', '.vue'],
			},
			typescript: true,
		},
	},
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
		'vue/max-attributes-per-line': 'off',
		'vue/singleline-html-element-content-newline': 'off',
		// Vue 3 ESM exports confuse eslint-plugin-import resolution under pnpm.
		'import/named': 'off',
		'import/extensions': 'off',
		'import/no-unresolved': 'off',
		'n/no-missing-import': 'off',
	},
}
