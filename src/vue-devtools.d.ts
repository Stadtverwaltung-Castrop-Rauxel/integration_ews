import 'vue'

declare module '@vue/runtime-core' {
	interface AppConfig {
		/**
		 * Enable Vue devtools programmatically
		 */
		devtools?: boolean
	}
}
