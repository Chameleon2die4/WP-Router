<?php

namespace WpRouter;

use Exception;
use WP;
use WP_Query;

class Router extends Utility
{
    const ROUTE_CACHE_OPTION = 'WP_Router_route_hash';
    private $routes = array();

    /**
     * @var Router The one instance of this singleton class
     */
    private static $instance;

    /**
     * Exist!
     *
     * @static
     * @return void
     */
    public static function init() {
        self::$instance = self::get_instance();
    }

    /****************************************************
     * PUBLIC API
     ****************************************************/

    /**
     * Add a new route
     *
     * @param string $id
     * @param array $properties
     * @return null|Route
     */
    public function add_route( string $id, array $properties ) {
        if ( $route = $this->create_route($id, $properties) ) {
            $this->routes[$id] = $route;
        }
        return $route;
    }

    /**
     * Get a previously registered route
     *
     * @param string $id
     * @return null|Route
     */
    public function get_route( string $id ) {
        return $this->routes[ $id ] ?? NULL;
    }

    /**
     * Update each property included in $changes for the given route
     *
     * @param string $id
     * @param array $changes
     * @return void
     */
    public function edit_route( string $id, array $changes ) {
        if ( !isset($this->routes[$id]) ) {
            return;
        }
        foreach ( $changes as $key => $value ) {
            if ( $key != 'id' ) {
                try {
                    $this->routes[$id]->set($key, $value);
                } catch ( Exception $e ) {
                    // Error setting the property. Failing silently
                }
            }
        }
    }

    /**
     * Get rid of the route with the given $id
     *
     * @param string $id
     * @return void
     */
    public function remove_route( string $id ) {
        if ( isset($this->routes[$id]) ) {
            unset($this->routes[$id]);
        }
    }

    /**
     * Get the URL to access the given route with the given arguments
     *
     * @param string $route_id
     * @param array $arguments
     * @return string The url to the route, or the home URL if the route doesn't exist
     */
    public function get_url( string $route_id, array $arguments = [] ) {
        $route = $this->get_route($route_id);
        if ( !$route ) {
            return home_url();
        } else {
            return $route->url($arguments);
        }
    }

    /****************************************************
     * PLUMBING
     ****************************************************/

    /*
     * Singleton Design Pattern
     * ------------------------------------------------- */

    public static function get_instance() {
        if ( !self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Hook into WordPress
     */
    private function __construct() {
        add_action('init', array($this, 'generate_routes'), 1000, 0);
        add_action('parse_request', array($this, 'parse_request'), 10, 1);
        add_filter('rewrite_rules_array', array($this, 'add_rewrite_rules'), 10, 1);
        add_filter('query_vars', array($this, 'add_query_vars'), 10, 1);
    }

    public function __clone() {
        // cannot be cloned
        trigger_error(__CLASS__.' may not be cloned', E_USER_ERROR);
    }

    public function __sleep() {
        // cannot be serialized
        trigger_error(__CLASS__.' may not be serialized', E_USER_ERROR);
    }

    /**
     * WordPress hook callbacks
     * ------------------------------------------------- */

    /**
     * Announce to other plugins that it's time to create rules
     * Action: init
     *
     * @uses do_action() Calls 'wp_router_generate_routes'
     * @uses do_action() Calls 'wp_router_alter_routes'
     * @return void
     */
    public function generate_routes() {
        do_action('wp_router_generate_routes', $this);
        do_action('wp_router_alter_routes', $this);
        $rules = $this->rewrite_rules();
        if ( $this->hash($rules) != get_option(self::ROUTE_CACHE_OPTION) ) {
            $this->flush_rewrite_rules();
        }
    }

    /**
     * Update WordPress's rewrite rules array with registered routes
     * Filter: rewrite_rules_array
     *
     * @param array $rules
     * @return array
     */
    public function add_rewrite_rules( array $rules ) {
        $new_rules = $this->rewrite_rules();
        update_option(self::ROUTE_CACHE_OPTION, $this->hash($new_rules));
        return $new_rules + $rules;
    }

    /**
     * Add all query vars from registered routes to WP recognized query vars
     *
     * @param array $vars
     * @return array
     */
    public function add_query_vars( array $vars ) {
        $route_vars = $this->query_vars();
        return array_merge($vars, $route_vars);
    }

    /**
     * If a callback is in order, call it.
     * Action: parse_request
     *
     * @param WP $query
     * @return void
     */
    public function parse_request( WP $query ) {
        $this->redirect_placeholder($query);
        if ( $id = $this->identify_route($query) ) {
            $this->routes[$id]->execute($query);
        }
    }

    /**
     * Redirect the placeholder page back to the front page
     *
     * @param WP|WP_Query $query
     */
    protected function redirect_placeholder( $query ) {
        // we'll only get a 'wp_router_page' query var when visiting
        // the page for a WP Router post, and there's only one of those
        if ( !empty( $query->query_vars[Page::POST_TYPE]) ) {
            wp_redirect( home_url(), 303 );
            exit();
        }
    }

    /**
     * Identify the route based on the request's query variables
     *
     * @param WP|WP_Query $query
     * @return string|NULL
     */
    protected function identify_route( $query ) {
        if ( !isset($query->query_vars[self::QUERY_VAR]) ) {
            return NULL;
        }
        $id = $query->query_vars[self::QUERY_VAR];
        if ( !isset($this->routes[$id]) || ! $this->routes[$id] instanceof Route ) {
            return NULL;
        }
        return $id;
    }

    /**
     * Create a new WP_Route with the given id and properties
     *
     * protected so it can be mocked in testing
     *
     * @param string $id
     * @param array $properties
     * @return null|Route
     */
    protected function create_route( string $id, array $properties ) {
        try {
            $route = new Route($id, $properties);
        } catch ( Exception $e ) {
            // invalid route $properties
            return NULL;
        }
        return $route;
    }

    /**
     * Get the array of rewrite rules from all registered routes
     *
     * @return array
     */
    protected function rewrite_rules() {
        $rules = array();
        foreach ( $this->routes as $route ) {
            /** @var Route $route */
            $rules = array_merge($rules, $route->rewrite_rules());
        }
        return $rules;
    }

    /**
     * Get an array of all query vars used by registered routes
     *
     * @return array
     */
    protected function query_vars() {
        $vars = array();
        foreach ( $this->routes as $route ) {
            $vars = array_merge($vars, $route->get_query_vars());
        }
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /**
     * Create a hash of the registered rewrite rules
     *
     * @param array $rules
     * @return string
     */
    protected function hash( array $rules ) {
        return md5(serialize($rules));
    }

    /**
     * Tell WordPress to flush its rewrite rules
     *
     * @return void
     */
    protected function flush_rewrite_rules() {
        flush_rewrite_rules();
    }
}