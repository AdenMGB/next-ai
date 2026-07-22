import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'node:path'

export default createAppConfig(
	{
		main: resolve(join('src', 'main.ts')),
		admin: resolve(join('src', 'admin.ts')),
		panel: resolve(join('src', 'panel.ts')),
	},
	{
		createEmptyCSSEntryPoints: true,
		extractLicenseInformation: true,
		thirdPartyLicense: false,
	},
)
