<?php
/**
 * Class Google\Site_Kit\Modules\Search_Console
 *
 * @package   Google\Site_Kit
 * @copyright 2019 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://sitekit.withgoogle.com
 */

namespace Google\Site_Kit\Modules;

use Google\Site_Kit\Core\Modules\Module;
use Google\Site_Kit\Core\Modules\Module_With_Screen;
use Google\Site_Kit\Core\Modules\Module_With_Screen_Trait;
use Google\Site_Kit\Core\Modules\Module_With_Scopes;
use Google\Site_Kit\Core\Modules\Module_With_Scopes_Trait;
use Google_Client;
use Google_Service_Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\RequestInterface;
use WP_Error;

/**
 * Class representing the Search Console module.
 *
 * @since 1.0.0
 * @access private
 * @ignore
 */
final class Search_Console extends Module implements Module_With_Screen, Module_With_Scopes {
	use Module_With_Screen_Trait, Module_With_Scopes_Trait;

	const PROPERTY_OPTION = 'googlesitekit_search_console_property';

	/**
	 * Registers functionality through WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	public function register() {
		$this->register_scopes_hook();

		$this->register_screen_hook();

		// Ensure that a Search Console property must be set at all times.
		add_filter(
			'googlesitekit_setup_complete',
			function( $complete ) {
				if ( ! $complete ) {
					return $complete;
				}

				$sc_property = $this->options->get( self::PROPERTY_OPTION );
				return ! empty( $sc_property );
			}
		);

		// Filter the reference site URL to use Search Console property if available.
		add_filter(
			'googlesitekit_site_url',
			function( $url ) {
				$sc_property = $this->options->get( self::PROPERTY_OPTION );
				if ( ! empty( $sc_property ) ) {
					return $sc_property;
				}
				return $url;
			},
			-9999
		);

		// Provide Search Console property information to JavaScript.
		add_filter(
			'googlesitekit_setup_data',
			function ( $data ) {
				$sc_property = $this->options->get( self::PROPERTY_OPTION );

				$data['hasSearchConsoleProperty'] = ! empty( $sc_property );

				return $data;
			},
			11
		);

		add_filter(
			'googlesitekit_show_admin_bar_menu',
			function( $display, $post_id ) {
				$sc_property = $this->options->get( self::PROPERTY_OPTION );
				if ( empty( $sc_property ) ) {
					return false;
				}

				if ( ! $this->has_data_for_post( $post_id ) ) {
					return false;
				}

				return $display;
			},
			10,
			2
		);
	}

	/**
	 * Gets required Google OAuth scopes for the module.
	 *
	 * @since 1.0.0
	 *
	 * @return array List of Google OAuth scopes.
	 */
	public function get_scopes() {
		return array(
			'https://www.googleapis.com/auth/webmasters',
		);
	}

	/**
	 * Returns the mapping between available datapoints and their services.
	 *
	 * @since 1.0.0
	 *
	 * @return array Associative array of $datapoint => $service_identifier pairs.
	 */
	protected function get_datapoint_services() {
		return array(
			// GET.
			'sites'           => 'webmasters',
			'matched-sites'   => 'webmasters',
			'searchanalytics' => 'webmasters',

			// POST.
			'site'            => '',
		);
	}

	/**
	 * Creates a request object for the given datapoint.
	 *
	 * @since 1.0.0
	 *
	 * @param string $method    Request method. Either 'GET' or 'POST'.
	 * @param string $datapoint Datapoint to get request object for.
	 * @param array  $data      Optional. Contextual data to provide or set. Default empty array.
	 * @return RequestInterface|callable|WP_Error Request object or callable on success, or WP_Error on failure.
	 */
	protected function create_data_request( $method, $datapoint, array $data = array() ) {
		if ( 'GET' === $method ) {
			switch ( $datapoint ) {
				case 'sites':
				case 'matched-sites':
					return $this->get_webmasters_service()->sites->listSites();
				case 'searchanalytics':
					$data = array_merge(
						array(
							'compareDateRanges' => false,
							'dateRange'         => 'last-28-days',
							'dimensions'        => '',
							'url'               => '',
						),
						$data
					);

					list ( $start_date, $end_date ) = $this->parse_date_range(
						$data['dateRange'],
						$data['compareDateRanges'] ? 2 : 1,
						3
					);

					$data_request = array(
						'page'       => $data['url'],
						'start_date' => $start_date,
						'end_date'   => $end_date,
						'dimensions' => explode( ',', $data['dimensions'] ),
					);

					if ( isset( $data['limit'] ) ) {
						$data_request['row_limit'] = $data['limit'];
					}

					return $this->create_search_analytics_data_request( $data_request );
			}
		} elseif ( 'POST' === $method ) {
			switch ( $datapoint ) {
				case 'site':
					if ( empty( $data['siteUrl'] ) ) {
						return new WP_Error(
							'missing_required_param',
							/* translators: %s: Missing parameter name */
							sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'siteUrl' ),
							array( 'status' => 400 )
						);
					}

					$site_url = trailingslashit( $data['siteUrl'] );

					return function () use ( $site_url ) {
						$orig_defer = $this->get_client()->shouldDefer();
						$this->get_client()->setDefer( false );

						try {
							// If the site does not exist in the account, an exception will be thrown.
							$site = $this->get_webmasters_service()->sites->get( $site_url );
						} catch ( Google_Service_Exception $exception ) {
							// If we got here, the site does not exist in the account, so we will add it.
							/* @var ResponseInterface $response Response object. */
							$response = $this->get_webmasters_service()->sites->add( $site_url );

							if ( 204 !== $response->getStatusCode() ) {
								return new WP_Error(
									'failed_to_add_site_to_search_console',
									__( 'Error adding the site to Search Console.', 'google-site-kit' ),
									array( 'status' => 500 )
								);
							}

							// Fetch the site again now that it exists.
							$site = $this->get_webmasters_service()->sites->get( $site_url );
						}

						$this->get_client()->setDefer( $orig_defer );
						$this->options->set( self::PROPERTY_OPTION, $site_url );

						return array(
							'siteUrl'         => $site->getSiteUrl(),
							'permissionLevel' => $site->getPermissionLevel(),
						);
					};
			}
		}

		return new WP_Error( 'invalid_datapoint', __( 'Invalid datapoint.', 'google-site-kit' ) );
	}

	/**
	 * Parses a response for the given datapoint.
	 *
	 * @since 1.0.0
	 *
	 * @param string $method    Request method. Either 'GET' or 'POST'.
	 * @param string $datapoint Datapoint to resolve response for.
	 * @param mixed  $response  Response object or array.
	 * @return mixed Parsed response data on success, or WP_Error on failure.
	 */
	protected function parse_data_response( $method, $datapoint, $response ) {
		if ( 'GET' === $method ) {
			switch ( $datapoint ) {
				case 'sites':
					/* @var \Google_Service_Webmasters_SitesListResponse $response Response object. */
					return $this->map_sites( (array) $response->getSiteEntry() );
				case 'matched-sites':
					/* @var \Google_Service_Webmasters_SitesListResponse $response Response object. */
					$sites            = $this->map_sites( (array) $response->getSiteEntry() );
					$current_url      = $this->context->get_reference_site_url();
					$current_host     = wp_parse_url( $current_url, PHP_URL_HOST );
					$property_matches = array_filter(
						$sites,
						function ( array $site ) use ( $current_host ) {
							$site_host = wp_parse_url( $site['siteUrl'], PHP_URL_HOST );

							// Ensure host names overlap, from right to left.
							return 0 === strpos( strrev( $current_host ), strrev( $site_host ) );
						}
					);

					$exact_match = array_reduce(
						$property_matches,
						function ( $match, array $site ) use ( $current_url ) {
							if ( ! $match && trailingslashit( $current_url ) === trailingslashit( $site['siteUrl'] ) ) {
								return $site;
							}
							return $match;
						},
						null
					);

					return array(
						'exactMatch'      => $exact_match, // (array) single site object, or null if no match.
						'propertyMatches' => $property_matches, // (array) of site objects, or empty array if none.
					);
				case 'searchanalytics':
					return $response->getRows();
			}
		}

		return $response;
	}

	/**
	 * Map Site model objects to primitives used for API responses.
	 *
	 * @param \Google_Service_Webmasters_WmxSite[] $sites Site objects.
	 *
	 * @return array
	 */
	private function map_sites( $sites ) {
		return array_map(
			function ( \Google_Service_Webmasters_WmxSite $site ) {
				return array(
					'siteUrl'         => $site->getSiteUrl(),
					'permissionLevel' => $site->getPermissionLevel(),
				);
			},
			$sites
		);
	}

	/**
	 * Creates a new Search Console analytics request for the current site and given arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args {
	 *     Optional. Additional arguments.
	 *
	 *     @type array  $dimensions List of request dimensions. Default empty array.
	 *     @type string $start_date Start date in 'Y-m-d' format. Default empty string.
	 *     @type string $end_date   End date in 'Y-m-d' format. Default empty string.
	 *     @type string $page       Specific page URL to filter by. Default empty string.
	 *     @type int    $row_limit  Limit of rows to return. Default 500.
	 * }
	 * @return RequestInterface Search Console analytics request instance.
	 */
	protected function create_search_analytics_data_request( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'dimensions' => array(),
				'start_date' => '',
				'end_date'   => '',
				'page'       => '',
				'row_limit'  => 500,
			)
		);

		$request = new \Google_Service_Webmasters_SearchAnalyticsQueryRequest();
		if ( ! empty( $args['dimensions'] ) ) {
			$request->setDimensions( (array) $args['dimensions'] );
		}
		if ( ! empty( $args['start_date'] ) ) {
			$request->setStartDate( $args['start_date'] );
		}
		if ( ! empty( $args['end_date'] ) ) {
			$request->setEndDate( $args['end_date'] );
		}
		if ( ! empty( $args['page'] ) ) {
			$filter = new \Google_Service_Webmasters_ApiDimensionFilter();
			$filter->setDimension( 'page' );
			$filter->setExpression( esc_url_raw( $args['page'] ) );
			$filters = new \Google_Service_Webmasters_ApiDimensionFilterGroup();
			$filters->setFilters( array( $filter ) );
			$request->setDimensionFilterGroups( array( $filters ) );
		}
		if ( ! empty( $args['row_limit'] ) ) {
			$request->setRowLimit( $args['row_limit'] );
		}

		return $this->get_webmasters_service()
			->searchanalytics
			->query( $this->context->get_reference_site_url(), $request );
	}

	/**
	 * Checks whether Search Console data exists for the given post.
	 *
	 * The result of this query is stored in a transient which is refreshed every 2 hours.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if Search Console data exists, false otherwise.
	 */
	protected function has_data_for_post( $post_id ) {
		if ( ! $post_id ) {
			return false;
		}

		$transient_key = 'googlesitekit_sc_has_data_for_post_' . $post_id;
		$has_data      = get_transient( $transient_key );
		if ( false === $has_data ) {
			$post_url = esc_url_raw( $this->context->get_reference_permalink( $post_id ) );

			if ( false === $post_url ) {
				return false;
			}

			$datasets = array(
				array(
					'identifier' => $this->slug,
					'key'        => 'sc-site-analytics',
					'datapoint'  => 'searchanalytics',
					'data'       => array(
						'url'               => $post_url,
						'dateRange'         => 'last-7-days',
						'dimensions'        => 'date',
						'compareDateRanges' => true,
					),
				),
				array(
					'identifier' => $this->slug,
					'key'        => 'search-keywords',
					'datapoint'  => 'searchanalytics',
					'data'       => array(
						'url'        => $post_url,
						'dateRange'  => 'last-7-days',
						'dimensions' => 'query',
						'limit'      => 10,
					),
				),
			);

			$responses = $this->get_batch_data(
				array_map(
					function( $dataset ) {
						return (object) $dataset;
					},
					$datasets
				)
			);

			$has_data = false;
			foreach ( $responses as $key => $response ) {
				if ( is_wp_error( $response ) || ! is_array( $response ) || empty( $response ) || ! isset( $response[0] ) ) {
					continue;
				}

				if ( $response[0]->clicks > 0 || $response[0]->impressions > 0 ) {
					$has_data = true;
					break;
				}
			}

			set_transient( $transient_key, (int) $has_data, 2 * HOUR_IN_SECONDS );
		}

		return (bool) $has_data;
	}

	/**
	 * Sets up information about the module.
	 *
	 * @since 1.0.0
	 *
	 * @return array Associative array of module info.
	 */
	protected function setup_info() {
		return array(
			'slug'         => 'search-console',
			'name'         => __( 'Search Console', 'google-site-kit' ),
			'description'  => __( 'Google Search Console and helps you understand how Google views your site and optimize its performance in search results.', 'google-site-kit' ),
			'cta'          => __( 'Connect your site to Google Search Console.', 'google-site-kit' ),
			'order'        => 1,
			'homepage'     => __( 'https://search.google.com/search-console', 'google-site-kit' ),
			'learn_more'   => __( 'https://www.google.com/webmasters/tools/home', 'google-site-kit' ),
			'force_active' => true,
		);
	}

	/**
	 * Get the configured Webmasters service instance.
	 *
	 * @return \Google_Service_Webmasters The Search Console API service.
	 */
	private function get_webmasters_service() {
		return $this->get_service( 'webmasters' );
	}

	/**
	 * Sets up the Google services the module should use.
	 *
	 * This method is invoked once by {@see Module::get_service()} to lazily set up the services when one is requested
	 * for the first time.
	 *
	 * @since 1.0.0
	 *
	 * @param Google_Client $client Google client instance.
	 * @return array Google services as $identifier => $service_instance pairs. Every $service_instance must be an
	 *               instance of Google_Service.
	 */
	protected function setup_services( Google_Client $client ) {
		return array(
			'webmasters' => new \Google_Service_Webmasters( $client ),
		);
	}
}
