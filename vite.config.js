/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: CC0-1.0
 */
import { defineConfig } from 'vite'
import { createAppConfig } from '@nextcloud/vite-config'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

// Polyfill __dirname for ES modules
const __dirname = dirname(fileURLToPath(import.meta.url))

export default defineConfig((env) => {
	const isProduction = env.mode === 'production'

	const appConfig = createAppConfig({
		// Entry points
		personalSettings: resolve(__dirname, 'src/personalSettings.ts'),
		adminSettings: resolve(__dirname, 'src/adminSettings.ts'),
		popupSuccess: resolve(__dirname, 'src/popupSuccess.ts'),
	}, {
		emptyOutputDirectory: {
			additionalDirectories: ['css']
		},

		config: {
			// Enable sourcemaps in development only
			sourcemap: !isProduction,

			// Minify for production builds
			minify: isProduction ? 'terser' : false,

			// Terser options for better minification
			...(isProduction && {
				/** @type {import('vite').Terser.MinifyOptions} */
				terserOptions: {
					compress: {
						drop_console: true, // jshint ignore:line
						drop_debugger: true, // jshint ignore:line
					},
				},
			}),

			rollupOptions: {
				output: {
					// Prevent vendor splitting - keep all code in entry files
					manualChunks: undefined,
					entryFileNames: '[name].mjs',
					chunkFileNames: '[name].chunk.mjs',
				},
			},

			// Keep CSS in single files per entry point
			cssCodeSplit: false,

			// Inline assets smaller than 4KB as base64
			assetsInlineLimit: 4096,
		},
	})

	return appConfig(env)
})
