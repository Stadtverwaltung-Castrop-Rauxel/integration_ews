/**
 * @copyright Copyright (c) 2023 Sebastian Krupinski <krupinski01@gmail.com>
 *
 * @author Sebastian Krupinski <krupinski01@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

import {createApp} from 'vue'
import AdminSettings from './components/AdminSettings.vue'
import {translate as t, translatePlural as n} from '@nextcloud/l10n'

const app = createApp(AdminSettings)
app.mixin({methods: {t, n}})
if (import.meta.env.DEV || import.meta.env.MODE === 'development') {
	app.config.devtools = true
}
app.mount('#ews_settings')
