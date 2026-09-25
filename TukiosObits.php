<?php

/*

Plugin Name: Tukios Obituaries Plugin

Plugin URI: https://tukios.com

Description: Plugin to help display obituaries on your website.

Version: 2.3.9

Author: Tukios Websites    

License: GPLv2 or later

Text Domain: tukios.com

*/

add_action('init', 'add_get_val');
function add_get_val() 
{
    global $wp;
    $wp->add_query_var('q');
    $wp->add_query_var('pg');
    $wp->add_query_var('loc'); // Location ID chosen from the listings dropdown
}

/**
 * 
 * 
 *  SLIDER SHORTCODE
 * 
 */
function create_slider_shortcode($atts, $content = null)
{
    $options = get_option('tukios_plugin_settings'); // get the stored info from settings
    $api_key = esc_attr($options['api_key']);
    $api_version = esc_attr($options['api_version']);
    $environment = esc_attr($options['environment']);
    $per_page = esc_attr($options['slider_per_page']);
    $location = $atts['location_id'] ?? null;

    $data = [
        'organization_uuid' => esc_attr($options['organization_id']),
        'location' => $location,
        'per_page' => $per_page
    ];

    $response = api_request('obituaries', $api_version, $environment, $api_key, $data);

    $obituaries = json_decode($response);

    return get_slider_html($obituaries);
}


/**
 * Parse the listings shortcode's "locations" attribute into an [id => label] array.
 *
 * Expected format: "951378:Dundalk,951377:Towson"
 *  - Pairs are comma-separated; ID and label are split on the first colon only,
 *    so labels may contain colons.
 *  - A bare ID with no label ("951378") uses the ID as its label.
 *  - Empty entries are skipped.
 *
 * Returns an empty array when the attribute is missing, which disables the dropdown.
 */

function tukios_parse_locations_attr($value)
{
    $locations = [];
    if (empty($value)) {
        return $locations;
    }
    foreach (explode(',', $value) as $pair) {
        $parts = explode(':', $pair, 2);
        $id = trim($parts[0]);
        if ($id === '') {
            continue;
        }
        $label = isset($parts[1]) ? trim($parts[1]) : $id;
        $locations[$id] = $label;
    }
    return $locations;
}

/**
 * 
 *  LISTINGS PAGE SHORTCODE
 *  - Creates a listings page. Query params that are used are:
 *      - q (the search query)
 *      - pg (the page number)
 *      - api_key (the provided api key)
 *      - api_version (v1, etc)
 *      - enviornment (PRODUCTION, STAGING, DEVELOPMENT)
 * 		- location (ID of the location in TWP)
 */

function create_obit_page_shortcode($atts, $content = null)
{
    global $wp;

    $options = get_option('tukios_plugin_settings'); // get the stored info from settings

    $sq = wp_unslash(get_query_var('q'));
    $pg = !empty(get_query_var('pg')) ? get_query_var('pg') : 1;
    $api_key = esc_attr($options['api_key']);
    $api_version = esc_attr($options['api_version']);
    $environment = esc_attr($options['environment']);

    // Location dropdown support.
    // A visitor's ?loc= value is only honored if it matches an ID listed in the
    // shortcode's "locations" attribute; anything else is ignored so the URL can't
    // be used to request arbitrary locations. When no valid choice is made, the
    // fixed location_id attribute (or all locations, if unset) applies as before.

    $location = $atts['location_id'] ?? null;
    $locations = tukios_parse_locations_attr($atts['locations'] ?? '');
    $selected_loc = sanitize_text_field(wp_unslash(get_query_var('loc')));
    if ($selected_loc !== '' && isset($locations[$selected_loc])) {
        $location = $selected_loc;
    } else {
        $selected_loc = '';
    }

    $data = [
        'organization_uuid' => esc_attr($options['organization_id']),
        'location' => $location,
        "pg" => $pg,
        "page_name" => "pg",
        "url" => home_url(add_query_arg(array(), $wp->request))
    ];

    if (!empty($sq)) {
        $data['q'] = $sq;
    }

    $response = api_request('obituaries', $api_version, $environment, $api_key, $data);

    $paginator = json_decode($response);

    // Pass dropdown options and the current selection through to the search bar and pagination  
    return get_listings_html($paginator, $locations, $selected_loc);
}


function subscribe_obit_notifications_shortcode($atts, $content = null)
{
    wp_enqueue_style('twp', plugins_url('twp.css',  __FILE__));
    $options = get_option('tukios_plugin_settings');
    $api_key = esc_attr($options['api_key']);
    $api_version = esc_attr($options['api_version']);
    $environment = esc_attr($options['environment']);
    $organization_id = esc_attr($options['organization_id']);
    $endpoint = "subscriptions";
    $type = "obituary";


    switch (strtoupper($environment)) {
        case 'PRODUCTION':
            $domain = 'websites.tukios.com';
            break;
        case 'STAGING':
            $domain = 'stg.tukioswebsites.com';
            break;
        case 'DEVELOPMENT':
            $domain = 'dev.tukioswebsites.com';
            break;
    }

    $obituarySubscribeUrl = "https://$domain/api/$api_version/$endpoint";

    $html = <<<HTML
        <div class="tukios_obituary_subscribe_container twp-relative twp-px-4 twp-py-5 twp-rounded-xl twp-bg-gray-200 twp-m-1">
            <form class="tukios_obituary_subscribe_form">
                <h3 class="tukios_obituary_subscribe_title twp-text-lg twp-font-bold twp-text-gray-900 twp-text-center">Subscribe</h3>
                <p class="tukios_obituary_subscribe_text twp-text-sm twp-text-gray-500 twp-text-center twp-mb-1">Get alerts when new obituaries are posted.</p>
                <input class="tukios_obituary_subscribe_email twp-text-sm twp-w-full" type="email" name="email" placeholder="Email address" />
                <input type="hidden" name="organization_uuid" value="$organization_id" />
                <input type="hidden" name="type" value="$type" />
                <button class="tukios_obituary_subscribe_submit twp-text-white twp-bg-blue-500 twp-p-1 twp-w-full twp-mt-1" type="submit">Subscribe</button>
            </form>

            <div class="tukios_obituary_subscribe_error twp-bg-gray-200 twp-bg-opacity-75 twp-rounded-xl twp-p-1 twp-absolute twp-top-0 twp-bottom-0 twp-left-0 twp-right-0 twp-grid place-items-center twp-hidden">
                <div class="tukios_obituary_subscribe_error_wrapper twp-bg-red-300 twp-rounded-sm twp-shadow-lg twp-relative twp-m-auto twp-p-3">
                    <h3 class="tukios_obituary_subscribe_error_title twp-text-lg twp-text-center">Error with subscribing.</h3>
                    <p class="tukios_obituary_subscribe_error_text twp-text-sm twp-text-center">Please try again.</p>
                </div>
            </div>

            <script>
                tukiosObituarySubscribeInit();
                function tukiosObituarySubscribeInit() {
                    const container_el = document.querySelector(".tukios_obituary_subscribe_container");
                    const form_el = container_el.querySelector(".tukios_obituary_subscribe_form");
                    const subscribeEmail_el = form_el.querySelector(".tukios_obituary_subscribe_email");
                    const subscribeSubmit_el = form_el.querySelector(".tukios_obituary_subscribe_submit");
                    const subscribeError_el = container_el.querySelector(".tukios_obituary_subscribe_error");
                    const subscribeError_text_el = subscribeError_el.querySelector(".tukios_obituary_subscribe_error_text");

                    form_el.onsubmit = (ev) => {
                        ev.preventDefault();

                        if(!isValidEmail(subscribeEmail_el.value)) {
                            showError("Not a valid email.");
                            return;
                        }

                        const formData = new FormData(form_el);
                        subscribeEmail_el.disabled = true;
                        subscribeSubmit_el.disabled = true;
                        subscribeSubmit_el.innerText = "Subscribing...";
                        
                        const xhr = new XMLHttpRequest();
                        xhr.open("POST", '$obituarySubscribeUrl', true);
                        xhr.setRequestHeader("Authorization", 'Bearer $api_key');
                        xhr.withCredentials = false;

                        xhr.onload = () => {
                            if(xhr.status >= 200 || xhr.status < 300) {
                                subscribeSubmit_el.innerText = "Successfully Subscribed";
                            }
                            else {
                                showError();
                                subscribeEmail_el.disabled = false;
                                subscribeSubmit_el.disabled = false;
                                subscribeSubmit_el.innerText = "Subscribe";
                            }
                        }

                        xhr.onerror = () => {
                            showError();
                        }

                        xhr.send(formData);
                    }

                    let showErrorTimeout;
                    function showError(message) {
                        if(showErrorTimeout) { return; }

                        if(message) {
                            subscribeError_text_el.innerText = message;
                        }
                        else {
                            subscribeError_text_el.innerText = "Please try again.";
                        }
                        subscribeError_el.classList.remove("twp-hidden");
                        showErrorTimeout = setTimeout(() => {
                            subscribeError_el.classList.add("twp-hidden");
                            showErrorTimeout = null;
                        }, 2000);
                    }

                    function isValidEmail(email) {
                        const reg = /^[A-Z0-9._%+-]+@([A-Z0-9-]+\.)+[A-Z]{2,4}$/i;
                        if(reg.test(email)){
                            return true;
                        }
                        return false;
                    }
                }
            </script>
        </div>
HTML;
    return $html;
}

function tukios_subscribe_grief_steps_shortcode($atts, $content = null)
{
    wp_enqueue_style('twp', plugins_url('twp.css',  __FILE__));
    $options = get_option('tukios_plugin_settings');
    $api_key = esc_attr($options['api_key']);
    $api_version = esc_attr($options['api_version']);
    $environment = esc_attr($options['environment']);
    $organization_id = esc_attr($options['organization_id']);
    $endpoint = "subscriptions";
    $type = "daily-grief";
    $griefStepsLogoUrl = plugin_dir_url(__FILE__) . 'GriefStepsLogo.webp';

    switch (strtoupper($environment)) {
        case 'PRODUCTION':
            $domain = 'websites.tukios.com';
            break;
        case 'STAGING':
            $domain = 'stg.tukioswebsites.com';
            break;
        case 'DEVELOPMENT':
            $domain = 'dev.tukioswebsites.com';
            break;
    }

    $griefStepsSubscribeUrl = "https://$domain/api/$api_version/$endpoint";

    $html = <<<HTML
        <div class="tukios_grief_steps_subscribe_container twp-relative twp-px-4 twp-py-5 twp-rounded-xl twp-bg-gray-200 twp-m-1">
            <img src="$griefStepsLogoUrl" alt="Grief Steps" class="tukios_grief_steps_logo twp-mx-auto twp-mb-2" />
            <form class="tukios_grief_steps_subscribe_form">
                <h3 class="tukios_grief_steps_subscribe_title twp-text-lg twp-font-bold twp-text-gray-900 twp-text-center">A YEAR OF GRIEF SUPPORT</h3>
                <p class="tukios_grief_steps_subscribe_text twp-text-sm twp-text-gray-500 twp-text-center twp-mb-1">Sign up for one year of weekly grief messages designed to provide strength and comfort during this challenging time.</p>
                <input class="tukios_grief_steps_subscribe_email twp-text-sm twp-w-full" type="email" name="email" placeholder="Email address" />
                <input type="hidden" name="organization_uuid" value="$organization_id" />
                <input type="hidden" name="type" value="$type" />
                <button class="tukios_grief_steps_subscribe_submit twp-text-white twp-bg-blue-500 twp-p-1 twp-w-full twp-mt-1" type="submit">Subscribe</button>
            </form>

            <div class="tukios_grief_steps_subscribe_error twp-bg-gray-200 twp-bg-opacity-75 twp-rounded-xl twp-p-1 twp-absolute twp-top-0 twp-bottom-0 twp-left-0 twp-right-0 twp-grid place-items-center twp-hidden">
                <div class="tukios_grief_steps_subscribe_error_wrapper twp-bg-red-300 twp-rounded-sm twp-shadow-lg twp-relative twp-m-auto twp-p-3">
                    <h3 class="tukios_grief_steps_subscribe_error_title twp-text-lg twp-text-center">Error with subscribing.</h3>
                    <p class="tukios_grief_steps_subscribe_error_text twp-text-sm twp-text-center">Please try again.</p>
                </div>
            </div>

            <script>
                tukiosGriefStepsSubscribeInit();
                function tukiosGriefStepsSubscribeInit() {
                    const container_el = document.querySelector(".tukios_grief_steps_subscribe_container");
                    const form_el = container_el.querySelector(".tukios_grief_steps_subscribe_form");
                    const subscribeEmail_el = form_el.querySelector(".tukios_grief_steps_subscribe_email");
                    const subscribeSubmit_el = form_el.querySelector(".tukios_grief_steps_subscribe_submit");
                    const subscribeError_el = container_el.querySelector(".tukios_grief_steps_subscribe_error");
                    const subscribeError_text_el = subscribeError_el.querySelector(".tukios_grief_steps_subscribe_error_text");

                    form_el.onsubmit = (ev) => {
                        ev.preventDefault();

                        if(!isValidEmail(subscribeEmail_el.value)) {
                            showError("Not a valid email.");
                            return;
                        }

                        const formData = new FormData(form_el);
                        subscribeEmail_el.disabled = true;
                        subscribeSubmit_el.disabled = true;
                        subscribeSubmit_el.innerText = "Subscribing...";
                        
                        const xhr = new XMLHttpRequest();
                        xhr.open("POST", '$griefStepsSubscribeUrl', true);
                        xhr.setRequestHeader("Authorization", 'Bearer $api_key');
                        xhr.withCredentials = false;

                        xhr.onload = () => {
                            if(xhr.status >= 200 || xhr.status < 300) {
                                subscribeSubmit_el.innerText = "Successfully Subscribed";
                            }
                            else {
                                showError();
                                subscribeEmail_el.disabled = false;
                                subscribeSubmit_el.disabled = false;
                                subscribeSubmit_el.innerText = "Subscribe";
                            }
                        }

                        xhr.onerror = () => {
                            showError();
                        }

                        xhr.send(formData);
                    }

                    let showErrorTimeout;
                    function showError(message) {
                        if(showErrorTimeout) { return; }

                        if(message) {
                            subscribeError_text_el.innerText = message;
                        }
                        else {
                            subscribeError_text_el.innerText = "Please try again.";
                        }
                        subscribeError_el.classList.remove("twp-hidden");
                        showErrorTimeout = setTimeout(() => {
                            subscribeError_el.classList.add("twp-hidden");
                            showErrorTimeout = null;
                        }, 2000);
                    }

                    function isValidEmail(email) {
                        const reg = /^[A-Z0-9._%+-]+@([A-Z0-9-]+\.)+[A-Z]{2,4}$/i;
                        if(reg.test(email)){
                            return true;
                        }
                        return false;
                    }
                }
             </script>
        </div>
HTML;
    return $html;
}


/**
 * REGISTER SHORT CODES: (name, method)
 */
add_shortcode('tukios_obituaries_slider', 'create_slider_shortcode');
add_shortcode('tukios_obituaries_listings', 'create_obit_page_shortcode');
add_shortcode('tukios_subscribe_obit_notifications', 'subscribe_obit_notifications_shortcode');
add_shortcode('tukios_subscribe_grief_steps', 'tukios_subscribe_grief_steps_shortcode');



function get_slider_html($obituaries)
{
    $options = get_option('tukios_plugin_settings');
    wp_enqueue_style('twp', plugins_url('twp.css',  __FILE__));

    $html = '<div class="tukios_obituary_slider_container twp-w-full">';

    if (esc_attr($options['slider_search']) == 'ON') {
        $html .= get_search_bar_html();
    }

    /** For inclusion in css */
    // twp-grid-cols-1 twp-grid-cols-2 twp-grid-cols-3 twp-grid-cols-4 twp-grid-cols-5 twp-grid-cols-6
    // twp-grid-cols-7 twp-grid-cols-8 twp-grid-cols-9 twp-grid-cols-10 twp-grid-cols-11 twp-grid-cols-12
    // sm:twp-grid-cols-1 sm:twp-grid-cols-2 sm:twp-grid-cols-3 sm:twp-grid-cols-4 sm:twp-grid-cols-5 sm:twp-grid-cols-6
    // sm:twp-grid-cols-7 sm:twp-grid-cols-8 sm:twp-grid-cols-9 sm:twp-grid-cols-10 sm:twp-grid-cols-11 sm:twp-grid-cols-12
    // md:twp-grid-cols-1 md:twp-grid-cols-2 md:twp-grid-cols-3 md:twp-grid-cols-4 md:twp-grid-cols-5 md:twp-grid-cols-6
    // md:twp-grid-cols-7 md:twp-grid-cols-8 md:twp-grid-cols-9 md:twp-grid-cols-10 md:twp-grid-cols-11 md:twp-grid-cols-12
    // lg:twp-grid-cols-1 lg:twp-grid-cols-2 lg:twp-grid-cols-3 lg:twp-grid-cols-4 lg:twp-grid-cols-5 lg:twp-grid-cols-6
    // lg:twp-grid-cols-7 lg:twp-grid-cols-8 lg:twp-grid-cols-9 lg:twp-grid-cols-10 lg:twp-grid-cols-11 lg:twp-grid-cols-12

    $phone = 'twp-grid-cols-' . $options['slider_column_count_phone'];
    $tablet = 'md:twp-grid-cols-' . $options['slider_column_count_tablet'];
    $desktop = 'lg:twp-grid-cols-' . $options['slider_column_count_desktop'];

    $html .= '<div class="tukios_obituary_slider_wrapper twp-w-full twp-grid ' . $phone . ' ' . $tablet . ' ' . $desktop . ' twp-gap-4">';

    foreach ($obituaries as $obituary) {
        $html .= get_obituary_template_html($obituary);
    }
    $html .= '</div>'; // Obit Wrapper EOF

    $html .= '</div>'; // Wrapper EOF
    return $html;
}

/**
 * $locations and $selected_loc are optional so existing callers keep working;
 * when omitted, the search bar renders without a location dropdown.
 */

function get_listings_html($paginator, $locations = [], $selected_loc = '')
{
    wp_enqueue_style('twp', plugins_url('twp.css',  __FILE__));

    $html = '<div class="tukios_obituary_container twp-w-full">';

    $sq = wp_unslash(get_query_var('q'));

    $html .= get_search_bar_html($locations, $selected_loc);

    foreach ($paginator->data as $obituary) {
        $html .= get_obituary_listing_template_html($obituary);
    }

    $html .= get_pagination_html($paginator, $selected_loc);

    $html .= '</div>'; // Wrapper EOF
    return $html;
}

/**
 * Append the search/location query string to a pagination URL.
 * The API returns null for links that don't exist (previous on page 1,
 * next on the last page, "..." separators). Appending to null would produce
 * a relative href like "&loc=123", which the browser resolves to a 404.
 */
function tukios_pagination_href($url, $query_string)
{
    return empty($url) ? '' : $url . $query_string;
}

function get_pagination_html($paginator, $selected_loc = '')
{
    $sq = wp_unslash(get_query_var('q'));
    $query_string = !empty($sq) ? '&q=' . urlencode($sq) : '';

    // Carry the selected location through page links so paging doesn't reset the filter
    if ($selected_loc !== '') {
        $query_string .= '&loc=' . urlencode($selected_loc);
    }

    $html = '<nav class="tukios_paginiation_container twp-border-t twp-border-gray-200 twp-px-4 twp-flex twp-items-center twp-justify-between twp-sm:px-0 twp-my-8">
        <div class="tukios_pagination_previous_wrapper twp--mt-px twp-w-0 twp-flex-1 twp-flex">
            <a href="' . tukios_pagination_href($paginator->prev_page_url . $query_string) . '" class="tukios_pagination_previous twp-border-t-2 twp-border-transparent twp-pt-4 twp-pr-1 twp-inline-flex twp-items-center twp-text-sm twp-font-medium twp-text-gray-500 hover:twp-text-gray-700 hover:twp-border-gray-300">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" class="tukios_pagination_svg twp-mr-3 twp-h-5 twp-w-5 twp-text-gray-400">
                    <path fill-rule="evenodd" d="M7.707 14.707a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l2.293 2.293a1 1 0 010 1.414z" clip-rule="evenodd"></path>
                </svg>
                Previous
            </a>
        </div>
        <div class="tukios_pagination_next_container twp-hidden md:twp--mt-px md:twp-flex">';

    $links = $paginator->links;
    array_pop($links);
    array_shift($links);

    foreach ($links as $link) {
        if ($link->active) {
            $html .= '<a href="' . tukios_pagination_href($link->url . $query_string) . '" class="tukios_pagination_link twp-border-blue-500 twp-text-blue-600 twp-border-t-2 twp-pt-4 twp-px-4 twp-inline-flex twp-items-center twp-text-sm twp-font-medium">' . $link->label . '</a>';
        } else {
            $html .= '<a href="' . tukios_pagination_href($link->url . $query_string) . '" class="tukios_pagination_link twp-border-transparent twp-text-gray-500 hover:twp-text-gray-700 hover:twp-border-gray-300 twp-border-t-2 twp-pt-4 twp-px-4 twp-inline-flex twp-items-center twp-text-sm twp-font-medium">' . $link->label . '</a>';
        }
    }

    $html .= '</div>
        <div class="tukios_pagination_next_wrapper twp--mt-px twp-w-0 twp-flex-1 twp-flex twp-justify-end">
            <a href="' . tukios_pagination_href($paginator->next_page_url . $query_string) . '" class="tukios_pagination_next twp-border-t-2 twp-border-transparent twp-pt-4 twp-pl-1 twp-inline-flex twp-items-center twp-text-sm twp-font-medium twp-text-gray-500 hover:twp-text-gray-700 hover:twp-border-gray-300">Next
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" class="twp-ml-3 twp-h-5 twp-w-5 twp-text-gray-400">
                    <path fill-rule="evenodd" d="M12.293 5.293a1 1 0 011.414 0l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                </svg>
            </a>
        </div>
    </nav>';

    return $html;
}

function get_search_bar_html($locations = [], $selected_loc = '')
{
    $options = get_option('tukios_plugin_settings');

    if (!empty(esc_attr($options['listings_page_slug']))) {
        $action = get_permalink($options['listings_page_slug']);
    } else {
        $action = '/listings';
    }

    $sq = wp_unslash(get_query_var('q'));

    // Build the location dropdown only when locations were supplied.
    // The slider's search bar calls this function with no arguments and gets no dropdown.
    // onchange submits immediately so visitors don't need to press Search after picking.
    $location_select = '';
    if (!empty($locations)) {
        $location_select = '<select name="loc" class="tukios_location_select twp-border-0 twp-border-blue-800 twp-border-b-2 twp-mr-4 twp-p-4 twp-bg-white" aria-label="Filter by location" onchange="this.form.submit()">';
        $location_select .= '<option value="">All Locations</option>';
        foreach ($locations as $id => $label) {
            $location_select .= '<option value="' . esc_attr($id) . '"'
                . selected($selected_loc, (string) $id, false) . '>'
                . esc_html($label) . '</option>';
        }
        $location_select .= '</select>';
    }
    return '<div class="tukios_search_container twp-mb-4">
        <form class="tukios_search_form twp-rounded-xl twp-shadow-lg twp-p-6 twp-flex twp-bg-white twp-items-center" action="' . $action . '">
            <span class="tukios_search_title twp-font-serif twp-text-xl">Search Obituaries</span>
            <input type="text" name="q" class="tukios_search_input twp-border-0 twp-border-blue-800 twp-border-b-2 twp-flex-1 twp-mx-8 twp-p-4" placeholder="Find a loved one..." value="' . esc_attr($sq) . '"> 
            ' . $location_select . '
            <button type="submit" class="tukios_search_button twp-bg-blue-800 twp-rounded twp-text-white twp-px-8 twp-py-4">Search</button>
        </form>
    </div>';
}

function get_obituary_template_html($obituary)
{
    $options = get_option('tukios_plugin_settings');

    $showAge = !empty(esc_attr($options['slider_show_age'])) && esc_attr($options['slider_show_age']) == 1 ? true : false;
    $showCity = !empty(esc_attr($options['slider_show_city'])) && esc_attr($options['slider_show_city']) == 1 ? true : false;
    $openNewTab = (esc_attr($options['slider_open_new_tab']) == 'ON' ? true : false);

    $html = '<a href="' . $obituary->public_url . '" target=' . ($openNewTab ? "_blank" : "_self") . ' class="twp-p-2">
        <div class="tukios_obituary_profile_image_container twp-flex twp-justify-center twp-mb-3">
            <div class="tukios_obituary_profile_image_wrapper twp-w-48 twp-h-60 twp-rounded-lg twp-overflow-hidden twp-bg-white">
                <img src="' . $obituary->default_image . '" class="tukios_obituary_profile_image twp-object-cover twp-w-48 twp-h-64">
            </div>
        </div>
        <div>
            <h3 class="tukios_obituary_profile_name twp-text-base twp-font-bold twp-text-center">' . $obituary->display_name . '</h3>
            <h4 class="tukios_obituary_profile_dates twp-text-sm twp-text-center">' . getDates($obituary) . '</h4>';

    if ($showAge) {
        $html .= '<h4 class="tukios_obituary_profile_age twp-text-sm twp-text-center">Age: ' . $obituary->age . '</h4>';
    }

    if ($showCity) {
        $html .= '<h4 class="tukios_obituary_profile_city twp-text-sm twp-text-center">' . $obituary->city . '</h4>';
    }

    $html .= '</div>
    </a>';

    return $html;
}

function get_obituary_listing_template_html($obituary)
{
    $options = get_option('tukios_plugin_settings');
    $openNewTab = (esc_attr($options['slider_open_new_tab']) == 'ON' ? true : false);

    $html = '<div class="tukios_obituary_profile_container twp-p-2 twp-flex">
        <a href="' . $obituary->public_url . '" target=' . ($openNewTab ? "_blank" : "_self") . '>
            <div class="tukios_obituary_listing_profile_image_container twp-flex twp-justify-center twp-mb-3 twp-w-48 twp-mr-8">
                <div class="tukios_obituary_listing_profile_image_wrapper twp-w-48 twp-h-60 twp-rounded-lg twp-overflow-hidden twp-bg-white">
                    <img src="' . $obituary->default_image . '" class="tukios_obituary_listing_profile_image twp-object-cover twp-w-48 twp-h-64">
                </div>
            </div>
        <a>
        <div class="tukios_obituary_listing_profile_info_wrapper twp-flex-1"> <!-- Right Side -->
            <a href="' . $obituary->public_url . '" target=' . ($openNewTab ? "_blank" : "_self") . '>
                <h3 class="tukios_obituary_listing_profile_name twp-text-lg twp-font-bold">' . $obituary->display_name . '</h3>
            </a>
            <a href="' . $obituary->public_url . '" target=' . ($openNewTab ? "_blank" : "_self") . '>
                <h2 class="tukios_obituary_listing_profile_dates twp-text-base twp-mb-4">' . getDates($obituary) . '</h2>
            </a>
             <p class="tukios_obituary_listing_profile_text twp-mb-8">' . wp_trim_words(strip_tags($obituary->obituary_text), 55) . '</p>
            <div class="tukios_obituary_listing_profile_buttons_container twp-flex"> <!-- Buttons -->
                <a href="' . $obituary->public_url . '" target=' . ($openNewTab ? "_blank" : "_self") . ' class="tukios_obituaries_visit_button twp-rounded twp-text-white twp-px-8 twp-bg-blue-800 twp-py-2 twp-mr-4">Visit Obituary</a>';

    if ($obituary->flower_sales_url !== null) {
        $html .= '<a href="' . $obituary->flower_sales_url . '" class="tukios_obituaries_flowers_button twp-rounded twp-text-blue-800 twp-px-8 twp-border-blue-800 twp-border twp-py-2">Order Flowers</a>';
    }

    if ($obituary->livestream_url !== null) {
        $html .= '<a href="' . $obituary->livestream_url . '" class="tukios_obituaries_livestream_button twp-rounded twp-text-white twp-px-8 twp-bg-blue-800 twp-py-2 twp-mr-4" target="_blank">View Livestream</a>';
    }

    $html .= '</div> <!-- BUTTONS EOF -->';
    $html .= '</div> <!-- Right Side EOF -->';
    $html .= '</div> <!-- LISTINGS EOF -->';
    return $html;
}

function tukios_render_settings_page()
{
    echo '<h2>Tukios Obituaries Settings</h2>';
    echo '<form action="options.php" method="post">';
    settings_fields('tukios_plugin_settings');
    do_settings_sections('tukios_obits_plugin');
    echo '<button type="submit" name="submit" class="button button-primary">Save</button>';
    echo '</form>';
}

function tukios_register_settings()
{
    register_setting(
        'tukios_plugin_settings',
        'tukios_plugin_settings',
        'tukios_validate_plugin_settings' // call back to santize inputs
    );

    add_settings_section(
        'section_one',
        '', // Title on the settings page
        'tukios_section_one_text', // call back for the field info
        'tukios_obits_plugin'
    );

    add_settings_field(
        'listings_page_slug',
        'Page where listings are shown',
        'tukios_render_listings_page_slug',
        'tukios_obits_plugin',
        'section_one'
    );

    add_settings_field(
        'api_key',
        'Tukios API Key',
        'tukios_render_api_key',
        'tukios_obits_plugin',
        'section_one'
    );

    add_settings_field(
        'environment',
        'Tukios Environment',
        'tukios_render_environment',
        'tukios_obits_plugin',
        'section_one'
    );

    add_settings_field(
        'api_version',
        'Tukios API version',
        'tukios_render_api_version',
        'tukios_obits_plugin',
        'section_one'
    );

    add_settings_field(
        'organization_id',
        'Organization ID',
        'tukios_render_organization_id',
        'tukios_obits_plugin',
        'section_one'
    );

    add_settings_section(
        'slider_section',
        '', // Title on the settings page
        'tukios_slider_section_text', // call back for the field info
        'tukios_obits_plugin'
    );

    add_settings_field(
        'slider_per_page',
        'Number of Obituaries',
        'tukios_render_slider_per_page',
        'tukios_obits_plugin',
        'slider_section'
    );
    add_settings_field(
        'slider_column_count_desktop',
        'Desktop: Number of Columns',
        'tukios_render_slider_column_count_desktop',
        'tukios_obits_plugin',
        'slider_section'
    );
    add_settings_field(
        'slider_column_count_tablet',
        'Tablet: Number of Columns',
        'tukios_render_slider_column_count_tablet',
        'tukios_obits_plugin',
        'slider_section'
    );
    add_settings_field(
        'slider_column_count_phone',
        'Phone: Number of Columns',
        'tukios_render_slider_column_count_phone',
        'tukios_obits_plugin',
        'slider_section'
    );

    add_settings_field(
        'slider_search',
        'Search Toggle: On/Off',
        'tukios_render_slider_search',
        'tukios_obits_plugin',
        'slider_section'
    );

    add_settings_field(
        'slider_show_age',
        'Show Age',
        'tukios_render_slider_show_age',
        'tukios_obits_plugin',
        'slider_section'
    );
    add_settings_field(
        'slider_show_date_of_birth',
        'Show Birth Date',
        'tukios_render_slider_show_date_of_birth',
        'tukios_obits_plugin',
        'slider_section'
    );
    add_settings_field(
        'slider_open_new_tab',
        'Open Obituary in New Tab',
        'tukios_render_slider_open_new_tab',
        'tukios_obits_plugin',
        'slider_section'
    );
    add_settings_field(
        'slider_show_city',
        'Show City',
        'tukios_render_slider_show_city',
        'tukios_obits_plugin',
        'slider_section'
    );
}
add_action('admin_init', 'tukios_register_settings');


function tukios_validate_plugin_settings($input)
{
    $output['listings_page_slug'] = sanitize_text_field($input['listings_page_slug']);
    $output['api_key'] = sanitize_text_field($input['api_key']);
    $output['organization_id'] = sanitize_text_field($input['organization_id']);
    $output['location_id'] = sanitize_text_field($input['location_id']);
    $output['environment'] = sanitize_text_field($input['environment']);
    $output['api_version'] = sanitize_text_field($input['api_version']);
    $output['slider_per_page'] = sanitize_text_field($input['slider_per_page']);
    $output['slider_column_count_desktop'] = sanitize_text_field($input['slider_column_count_desktop']);
    $output['slider_column_count_tablet'] = sanitize_text_field($input['slider_column_count_tablet']);
    $output['slider_column_count_phone'] = sanitize_text_field($input['slider_column_count_phone']);
    $output['slider_search'] = sanitize_text_field($input['slider_search']);
    $output['slider_show_age'] = sanitize_text_field($input['slider_show_age']);
    $output['slider_show_date_of_birth'] = sanitize_text_field($input['slider_show_date_of_birth']);
    $output['slider_show_city'] = sanitize_text_field($input['slider_show_city']);
    $output['slider_open_new_tab'] = sanitize_text_field($input['slider_open_new_tab']);

    return $output;
}

function tukios_section_one_text()
{
    echo '<p>If you do not know your API key, Please contact Tukios support.</p>
          <p>Use the following shortcode to place the obituaries slider on any page <pre>[tukios_obituaries_slider]</pre></p>
          <p>Use the following shortcode to place the obituaries listing page on any page <pre>[tukios_obituaries_listings]</pre></p>
          <p>Use the following shortcode to place the "Obituaries Sbuscribe" page on any page <pre>[tukios_subscribe_obit_notifications]</pre></p>
          <p>Use the following shortcode to place the "Grief Steps Subscribe" page on any page <pre>[tukios_subscribe_grief_steps]</pre></p>';
}

function tukios_slider_section_text()
{
    echo '<h2>Obituary Slider Settings</h2>
    <p>The following settings are for the obituary slider that shows the latest obituaries.</p>';
}

function tukios_render_listings_page_slug()
{
    $options = get_option('tukios_plugin_settings');

    echo '<select name="tukios_plugin_settings[listings_page_slug]">';
    echo '<option value=""></option>';

    if ($pages = get_pages()) {
        foreach ($pages as $page) {
            echo '<option value="' . $page->ID . '" ' . selected($page->ID, $options['listings_page_slug'], false) . '>' . $page->post_title . '</option>';
        }
    }
    echo '</select>';
}

function tukios_render_api_key()
{
    $options = get_option('tukios_plugin_settings');
    printf(
        '<input type="text" name="%s" value="%s" />',
        esc_attr('tukios_plugin_settings[api_key]'),
        esc_attr($options['api_key'])
    );
}

function tukios_render_environment()
{
    $options = get_option('tukios_plugin_settings');

    $current = !empty(esc_attr($options['environment'])) ? esc_attr($options['environment']) : 'PRODUCTION';

    $environments = ['DEVELOPMENT', 'STAGING', 'PRODUCTION'];

    $options = get_option('tukios_plugin_settings');

    echo '<select name="tukios_plugin_settings[environment]">';

    foreach ($environments as $environment) {
        echo '<option value="' . $environment . '" ' . selected($environment, $current, false) . '>' . $environment . '</option>';
    }

    echo '</select>';
}

function tukios_render_api_version()
{
    $options = get_option('tukios_plugin_settings');
    printf(
        '<input type="text" name="%s" value="%s" />',
        esc_attr('tukios_plugin_settings[api_version]'),
        esc_attr($options['api_version'])
    );
}

function tukios_render_organization_id()
{
    $options = get_option('tukios_plugin_settings');
    printf(
        '<input type="text" name="%s" value="%s" />',
        esc_attr('tukios_plugin_settings[organization_id]'),
        esc_attr($options['organization_id'])
    );
}

function tukios_render_slider_per_page()
{
    $options = get_option('tukios_plugin_settings');
    printf(
        '<input type="text" name="%s" value="%s" />',
        esc_attr('tukios_plugin_settings[slider_per_page]'),
        esc_attr($options['slider_per_page'])
    );
    printf('<small>How many obituaries to retrieve for the home page slider</small>');
}

function tukios_render_slider_column_count_desktop()
{
    render_column_count('desktop', 5);
}

function tukios_render_slider_column_count_tablet()
{
    render_column_count('tablet', 3);
}

function tukios_render_slider_column_count_phone()
{
    render_column_count('phone', 2);
}

function render_column_count($type, $default)
{
    $options = get_option('tukios_plugin_settings');
    $current_value = esc_attr($options['slider_column_count_' . $type]);
    $value = !empty($current_value) ? $current_value : $default;
    $name = esc_attr('tukios_plugin_settings[slider_column_count_' . $type . ']');

    printf(
        '<input type="text" name="%s" value="%s" />',
        $name,
        $value
    );
}

function tukios_render_slider_search()
{
    $options = get_option('tukios_plugin_settings');
    $current = esc_attr($options['slider_search']);

    echo '<input type="radio" name="tukios_plugin_settings[slider_search]" value="ON"' . checked('ON', $current, false) . '> ON <input type="radio" name="tukios_plugin_settings[slider_search]" value="OFF" ' . checked('OFF', $current, false) . '> OFF';
}

function tukios_render_slider_show_age()
{
    $options = get_option('tukios_plugin_settings');
    printf(
        '<input type="checkbox" name="%s" value="1" %s />',
        esc_attr('tukios_plugin_settings[slider_show_age]'),
        esc_attr($options['slider_show_age']) == 1 ? 'checked="checked"' : ''
    );
}

function tukios_render_slider_show_city()
{
    $options = get_option('tukios_plugin_settings');
    printf(
        '<input type="checkbox" name="%s" value="1" %s />',
        esc_attr('tukios_plugin_settings[slider_show_city]'),
        esc_attr($options['slider_show_city']) == 1 ? 'checked="checked"' : ''
    );
}

function tukios_render_slider_show_date_of_birth()
{
    $options = get_option('tukios_plugin_settings');
    printf(
        '<input type="checkbox" name="%s" value="1" %s />',
        esc_attr('tukios_plugin_settings[slider_show_date_of_birth]'),
        esc_attr($options['slider_show_date_of_birth']) == 1 ? 'checked="checked"' : ''
    );
}

function tukios_render_slider_open_new_tab()

{
    $options = get_option('tukios_plugin_settings');
    $current = esc_attr($options['slider_open_new_tab']);
    echo '<input type="radio" name="tukios_plugin_settings[slider_open_new_tab]" value="ON"' . checked('ON', $current, false) . '> ON <input type="radio" name="tukios_plugin_settings[slider_open_new_tab]" value="OFF" ' . checked('OFF', $current, false) . '> OFF';
}

function tukios_add_settings_page()
{
    add_options_page(
        'Tukios Obituaries Settings',
        'Tukios Obituaries',
        'manage_options',
        'tukios-obits-plugin',
        'tukios_render_settings_page'
    );
}

add_action('admin_menu', 'tukios_add_settings_page');


/**
 * $endpoint /get_obits etc     
 * $api_key - $api_key to access the tukios api (stored here)
 * $data = $data to be sent with the request
 * 
 * @param mixed $endpoint
 * @param mixed $type
 * @param mixed $api_key
 * @param mixed $data
 */
function api_request($endpoint, $version, $environment, $api_key, $data = null, $method = 'GET')
{
    switch (strtoupper($environment)) {
        case 'PRODUCTION':
            $domain = 'websites.tukios.com';
            break;
        case 'STAGING':
            $domain = 'stg.tukioswebsites.com';
            break;
        case 'DEVELOPMENT':
            $domain = 'dev.tukioswebsites.com';
            break;
    }

    $service_url = "https://$domain/api/$version/$endpoint";

    if ($method === 'GET') {
        $service_url .= '?' . http_build_query($data);
    }

    $curl = curl_init($service_url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array("Authorization: Bearer $api_key"));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

    if ($method === 'POST') {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    $response = curl_exec($curl);

    switch (curl_getinfo($curl, CURLINFO_HTTP_CODE)) {
        case 200:  # OK
            return $response;
            break;
        default:
            echo 'An unexpected error occurred while attempting to retrieve data' . "\n";
            echo "<!--CONNECTION SETTINGS: service_url: $service_url !>";
            break;
    }
}

function getDates($obituary)
{
    $options = get_option('tukios_plugin_settings');

    $showDateOfBirth = !empty(esc_attr($options['slider_show_date_of_birth'])) && esc_attr($options['slider_show_date_of_birth']) == 1
        && $obituary->show_date_of_birth
        && $obituary->formatted_date_of_birth
        ? true : false;

    $showDateOfDeath = $obituary->show_date_of_death && $obituary->formatted_date_of_death;

    return ($showDateOfBirth ? $obituary->formatted_date_of_birth : '')
        . ($showDateOfBirth && $showDateOfDeath ? ' - ' : '')
        . ($showDateOfDeath ? $obituary->formatted_date_of_death : '');
}
