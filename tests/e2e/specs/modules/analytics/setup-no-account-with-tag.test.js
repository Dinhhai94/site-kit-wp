/**
 * WordPress dependencies
 */
import { activatePlugin, visitAdminPage } from '@wordpress/e2e-test-utils';

/**
 * Internal dependencies
 */
import {
	deactivateAllOtherPlugins,
	resetSiteKit,
	setAnalyticsExistingPropertyId,
	setAuthToken,
	setClientConfig,
	setSearchConsoleProperty,
	setSiteVerification,
	useRequestInterception,
} from '../../../utils';

async function proceedToSetUpAnalytics() {
	await Promise.all( [
		expect( page ).toClick( '.googlesitekit-cta-link', { text: /set up analytics/i } ),
		page.waitForSelector( '.googlesitekit-setup-module--analytics' ),
		page.waitForResponse( ( res ) => res.url().match( 'analytics/data' ) ),
	] );
}

const EXISTING_PROPERTY_ID = 'UA-00000001-1';

describe( 'setting up the Analytics module with no existing account and with an existing tag', () => {
	beforeAll( async () => {
		await page.setRequestInterception( true );
		useRequestInterception( ( request ) => {
			if ( request.url().match( 'modules/analytics/data/tag-permission' ) ) {
				request.respond( {
					status: 403,
					body: JSON.stringify( {
						code: 'google_analytics_existing_tag_permission',
						message: 'google_analytics_existing_tag_permission',
					} ),
				} );
			} else if ( request.url().match( '/wp-json/google-site-kit/v1/data/' ) ) {
				request.respond( {
					status: 200,
				} );
			} else {
				request.continue();
			}
		} );
	} );

	beforeEach( async () => {
		await activatePlugin( 'e2e-tests-auth-plugin' );
		await activatePlugin( 'e2e-tests-analytics-existing-tag' );
		await activatePlugin( 'e2e-tests-module-setup-analytics-api-mock-no-account' );

		await setClientConfig();
		await setAuthToken();
		await setSiteVerification();
		await setSearchConsoleProperty();

		await visitAdminPage( 'admin.php', 'page=googlesitekit-settings' );
		await page.waitForSelector( '.mdc-tab-bar' );
		await expect( page ).toClick( '.mdc-tab', { text: /connect more services/i } );
		await page.waitForSelector( '.googlesitekit-settings-connect-module--analytics' );
	} );

	afterEach( async () => {
		await deactivateAllOtherPlugins();
		await resetSiteKit();
	} );

	it( 'does not allow Analytics to be set up with an existing tag that does not match a property of the user', async () => {
		await setAnalyticsExistingPropertyId( EXISTING_PROPERTY_ID );

		await proceedToSetUpAnalytics();

		// The specific message comes from the datapoint which we've mocked to return the error code instead.
		await expect( page ).toMatchElement( '.googlesitekit-setup-module--analytics p', { text: /google_analytics_existing_tag_permission/i } );
		// Buttons to proceed are not displayed; the user is blocked from completing setup.
		await expect( page ).not.toMatchElement( '.googlesitekit-setup-module--analytics button', { text: /create an account/i } );
		await expect( page ).not.toMatchElement( '.googlesitekit-setup-module--analytics button', { text: /re-fetch my account/i } );
	} );
} );
