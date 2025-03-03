/**
 * WordPress dependencies
 */
import { activatePlugin, createURL, visitAdminPage } from '@wordpress/e2e-test-utils';

/**
 * Internal dependencies
 */
import {
	deactivateAllOtherPlugins,
	pasteText,
	resetSiteKit,
	testClientConfig,
	useRequestInterception,
	wpApiFetch,
} from '../utils';

describe( 'Site Kit set up flow for the first time with site verification', () => {
	beforeAll( async () => {
		await page.setRequestInterception( true );
		useRequestInterception( ( request ) => {
			if ( request.url().startsWith( 'https://accounts.google.com/o/oauth2/auth' ) ) {
				request.respond( {
					status: 302,
					headers: {
						location: createURL( '/', 'oauth2callback=1&code=valid-test-code' ),
					},
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
		await activatePlugin( 'e2e-tests-oauth-callback-plugin' );
		await activatePlugin( 'e2e-tests-site-verification-api-mock' );
	} );

	afterEach( async () => {
		await deactivateAllOtherPlugins();
		await resetSiteKit();
	} );

	afterAll( async () => {
		await page.setRequestInterception( false );
	} );

	it( 'prompts for confirmation if user is not verified for the site', async () => {
		await visitAdminPage( 'admin.php', 'page=googlesitekit-splash' );
		await page.waitForSelector( '#client-configuration' );

		await pasteText( '#client-configuration', JSON.stringify( testClientConfig ) );
		await expect( page ).toClick( '#wizard-step-one-proceed' );
		await page.waitForSelector( '.googlesitekit-wizard-step--two button' );

		await expect( page ).toClick( '.googlesitekit-wizard-step--two button', { text: /sign in with Google/i } );
		await page.waitForNavigation();

		await expect( page ).toMatchElement( '.googlesitekit-wizard-step__title', { text: /Verify URL/i } );

		await page.waitForSelector( '.googlesitekit-wizard-step__inputs [name="siteProperty"]' );

		await expect( page ).toClick( '.googlesitekit-wizard-step__action button', { text: /Continue/i } );

		await page.waitForSelector( '.googlesitekit-wizard-step__action button' );

		await expect( page ).toClick( '.googlesitekit-wizard-step__action button', { text: /Go to Dashboard/i } );

		await page.waitForNavigation();

		await expect( page ).toMatchElement( '#js-googlesitekit-dashboard' );
		await expect( page ).toMatchElement( '.googlesitekit-publisher-win__title', { text: /Congrats on completing the setup for Site Kit!/i } );
	} );

	it( 'does not prompt for verification if the user is already verified for the site', async () => {
		// Simulate that the user is already verified.
		await wpApiFetch( {
			path: 'google-site-kit/v1/e2e/verify-site',
			method: 'post',
		} );

		await visitAdminPage( 'admin.php', 'page=googlesitekit-splash' );
		await page.waitForSelector( '#client-configuration' );

		await pasteText( '#client-configuration', JSON.stringify( testClientConfig ) );
		await expect( page ).toClick( '#wizard-step-one-proceed' );
		await page.waitForSelector( '.googlesitekit-wizard-step--two button' );

		await expect( page ).toClick( '.googlesitekit-wizard-step--two button', { text: /sign in with Google/i } );
		await page.waitForNavigation();

		await page.waitForSelector( '.googlesitekit-wizard-step__action button' );
		await expect( page ).toClick( '.googlesitekit-wizard-step__action button', { text: /Go to Dashboard/i } );

		await page.waitForNavigation();

		await expect( page ).toMatchElement( '#js-googlesitekit-dashboard' );
		await expect( page ).toMatchElement( '.googlesitekit-publisher-win__title', { text: /Congrats on completing the setup for Site Kit!/i } );
	} );
} );

