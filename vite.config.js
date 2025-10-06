/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: CC0-1.0
 */
import { defineConfig } from 'vite'

import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'node:path'
import VueDevTools from 'vite-plugin-vue-devtools'

export default defineConfig((env) => {
	const isProduction = env.mode === 'production'
	const isDevelopment = env.mode === 'development'

	const appConfig = createAppConfig({
		// entry points: {name: script}
		personalSettings: resolve(join('src', 'personalSettings.ts')),
		adminSettings: resolve(join('src', 'adminSettings.ts')),
		popupSuccess: resolve(join('src', 'popupSuccess.ts')),
	}, {
		emptyOutputDirectory: {
			additionalDirectories: ['css']
		},
		config: {
			build: {
				minify: isProduction,
			},
			plugins: [
				isDevelopment && VueDevTools(),
			].filter(Boolean),
		},
	})

	return appConfig(env)
})
