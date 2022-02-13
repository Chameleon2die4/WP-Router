<?php

namespace WpRouter;

if (function_exists('add_action')) {
    // Load all supporting files and hook into wordpress
    add_action('init', [ 'WpRouter\Utility', 'init' ], -100, 0);
    add_action(Utility::INIT_HOOK, [ 'WpRouter\Page', 'init' ], 0, 0);
    add_action(Utility::INIT_HOOK, [ 'WpRouter\Router', 'init' ], 1, 0);

    // Sample page
    add_action(Utility::INIT_HOOK, [ 'WpRouter\Sample', 'init' ], 1, 0);
}