<?php

namespace WpRouter;

//function load() {
//    if ( Utility::prerequisites_met(phpversion(), \get_bloginfo('version')) ) {
if (function_exists('add_action')) {
    // we can continue. Load all supporting files and hook into wordpress
    add_action('init', [ 'WpRouter/Utility', 'init' ], -100, 0);
    add_action(Utility::INIT_HOOK, [ 'WpRouter/Page', 'init' ], 0, 0);
    add_action(Utility::INIT_HOOK, [ 'WpRouter/WP_Router', 'init' ], 1, 0);

    // Sample page
    add_action(Utility::INIT_HOOK, [ 'WpRouter/Sample', 'init' ], 1, 0);
}
//    } else {
//        // let the user know prerequisites weren't met
//        add_action('admin_head', [ 'WpRouter/Utility', 'failed_to_load_notices' ], 0, 0);
//    }
//}