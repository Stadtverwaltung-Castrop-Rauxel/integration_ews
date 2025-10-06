import {generateUrl} from '@nextcloud/router'
import {APP_ID} from './constants.ts'

export const BASE_PATH = '/apps/' + APP_ID

export function joinPath(basePath: string, ...segments: string[]): string {
	const cleanBase: string = basePath.replace(/\/+$/, '') // Remove trailing slashes
	const cleanSegments: string[] = segments.map(segment => segment.replace(/^\/+|\/+$/g, '')) // Remove leading/trailing slashes
		.filter(segment => segment.length > 0) // Remove empty segments
	return cleanBase + '/' + cleanSegments.join('/')
}

export function generateAppUrl(path: string): string {
	return generateUrl(joinPath(BASE_PATH, path))
}
