<?php
/**
 * TechMachaw: Amazon keyword suggestions relay
 *
 * Amazon's search-suggestion feed does not allow browsers on other sites to read it,
 * so the Amazon Keyword Tools page asks your own WordPress site, and this snippet
 * fetches the suggestions server side.
 *
 * INSTALL (once, about 2 minutes)
 * 1. Plugins > Add New > search "Code Snippets" (free) > Install > Activate.
 * 2. Snippets > Add New. Title: "Amazon keyword relay". Paste everything below the
 *    opening "<?php" line (Code Snippets adds that line itself).
 * 3. Choose "Run snippet everywhere" and Save Changes and Activate.
 * 4. Test: open https://techmachaw.com/wp-json/tmc/v1/amazon-suggest?mkt=us&q=yoga%20mat
 *    You should see a list of keywords.
 *
 * Safety: read only, only talks to Amazon's suggestion host for the listed
 * marketplaces, caches answers for 24 hours, and limits each visitor to
 * 300 lookups an hour so nobody can use your site as a free scraper.
 */

add_action( 'rest_api_init', function () {
	register_rest_route( 'tmc/v1', '/amazon-suggest', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'tmc_amazon_suggest',
	) );
} );

function tmc_amazon_suggest( WP_REST_Request $request ) {
	$markets = array(
		'us' => array( 'com',    'ATVPDKIKX0DER',  'en_US' ),
		'uk' => array( 'co.uk',  'A1F83G8C2ARO7P', 'en_GB' ),
		'ca' => array( 'ca',     'A2EUQ1WTGCTBG2', 'en_CA' ),
		'in' => array( 'in',     'A21TJRUUN4KGV',  'en_IN' ),
		'ae' => array( 'ae',     'A2VIGQ35RCS4UG', 'en_AE' ),
		'au' => array( 'com.au', 'A39IBJ37TRP1C6', 'en_AU' ),
		'de' => array( 'de',     'A1PA6795UKMFR9', 'de_DE' ),
	);
	$mkt = strtolower( (string) $request->get_param( 'mkt' ) );
	$q   = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
	$q   = function_exists( 'mb_substr' ) ? mb_substr( $q, 0, 80 ) : substr( $q, 0, 80 );

	if ( ! isset( $markets[ $mkt ] ) || '' === $q ) {
		return new WP_REST_Response( array( 'error' => 'Give a marketplace (mkt) and a keyword (q).' ), 400 );
	}

	$cache_key = 'tmc_az_' . md5( $mkt . '|' . strtolower( $q ) );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return tmc_amazon_response( $cached, true );
	}

	// Rate limit per visitor IP.
	$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
	$rl_key  = 'tmc_az_rl_' . md5( $ip );
	$count   = (int) get_transient( $rl_key );
	if ( $count >= 300 ) {
		return new WP_REST_Response( array( 'error' => 'Too many lookups. Try again in an hour.' ), 429 );
	}
	set_transient( $rl_key, $count + 1, HOUR_IN_SECONDS );

	list( $host, $mid, $lop ) = $markets[ $mkt ];
	$url = add_query_arg(
		array(
			'lop'             => $lop,
			'site-variant'    => 'desktop',
			'client-info'     => 'amazon-search-ui',
			'mid'             => $mid,
			'alias'           => 'aps',
			'prefix'          => rawurlencode( $q ),
			'event'           => 'onKeyPress',
			'limit'           => 11,
			'suggestion-type' => 'KEYWORD',
		),
		'https://completion.amazon.' . $host . '/api/2017/suggestions'
	);
	$res = wp_remote_get( $url, array(
		'timeout'    => 8,
		'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
	) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return new WP_REST_Response( array( 'error' => 'Amazon did not answer. Try again.' ), 502 );
	}
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	$out  = array();
	if ( isset( $body['suggestions'] ) && is_array( $body['suggestions'] ) ) {
		foreach ( $body['suggestions'] as $s ) {
			if ( isset( $s['value'] ) ) {
				$out[] = (string) $s['value'];
			}
		}
	}
	set_transient( $cache_key, $out, DAY_IN_SECONDS );
	return tmc_amazon_response( $out, false );
}

function tmc_amazon_response( $list, $cached ) {
	$r = new WP_REST_Response( array( 'suggestions' => array_values( $list ), 'cached' => $cached ), 200 );
	$r->header( 'Cache-Control', 'public, max-age=3600' );
	return $r;
}
