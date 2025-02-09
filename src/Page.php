<?php /** @noinspection SpellCheckingInspection */

namespace WpRouter;

use WP_Post;
use WP_Query;

class Page extends Utility
{
    const POST_TYPE = 'wp_router_page';

    protected static $rewrite_slug = 'WP_Router';
    protected static $post_id = 0; // The ID of the post this plugin uses

    protected $contents = '';
    protected $title = '';
    protected $template = '';
    protected $meta = array();

    public static function init() {
        self::register_post_type();
    }

    /**
     * Register a post type to use when displaying pages
     * @static
     * @return void
     */
    private static function register_post_type() {
        // a very quiet post type
        $args = array(
          'public' => FALSE,
          'show_ui' => FALSE,
          'exclude_from_search' => TRUE,
          'publicly_queryable' => TRUE,
          'show_in_menu' => FALSE,
          'show_in_nav_menus' => FALSE,
          'supports' => array('title'),
          'has_archive' => TRUE,
          'rewrite' => array(
            'slug' => self::$rewrite_slug,
            'with_front' => FALSE,
            'feeds' => FALSE,
            'pages' => FALSE,
          )
        );
        register_post_type(self::POST_TYPE, $args);
    }

    /**
     * Get the ID of the placeholder post
     *
     * @static
     * @return int
     */
    protected static function get_post_id() {
        if ( !self::$post_id ) {
            $posts = get_posts(array(
              'post_type' => self::POST_TYPE,
              'post_status' => 'publish',
              'posts_per_page' => 1,
            ));
            if ( $posts ) {
                self::$post_id = $posts[0]->ID;
            } else {
                self::$post_id = self::make_post();
            }
        }
        return self::$post_id;
    }

    /**
     * Make a new placeholder post
     *
     * @static
     * @return int The ID of the new post
     */
    private static function make_post() {
        $post = array(
          'post_title' => __('WP Router Placeholder Page', 'wp-router'),
          'post_status' => 'publish',
          'post_type' => self::POST_TYPE,
        );
        $id = wp_insert_post($post);
        if ( is_wp_error($id) ) {
            return 0;
        }
        return $id;
    }

    public function __construct( $contents, $title, $template = '' ) {
        $this->contents = $contents;
        $this->title = $title;
        $this->template = $template;
        $this->add_hooks();
    }

    protected function add_hooks() {
        add_action('pre_get_posts', array($this, 'edit_query'), 10, 1);
        add_action('the_post', array($this, 'set_post_contents'), 10, 1);
        add_filter('the_title', array($this, 'get_title'), 10, 2);
        add_filter('single_post_title', array($this, 'get_single_post_title'), 10, 2);
        add_filter('redirect_canonical', array($this, 'override_redirect'), 10, 2);
        add_filter('get_post_metadata', array($this, 'set_post_meta'), 10, 4);
        add_filter('post_type_link', array($this, 'override_permalink'), 10, 4);
        if ( $this->template ) {
            add_filter('template_include', array($this, 'override_template'), 10, 1);
        }
    }

    /**
     * Edit WordPress's query so it finds our placeholder page
     *
     * @param WP_Query $query
     * @return void
     */
    public function edit_query( WP_Query $query ) {
        if ( isset($query->query_vars[self::QUERY_VAR]) ) {
            // make sure we get the right post
            $query->query_vars['post_type'] = self::POST_TYPE;
            $query->query_vars['p'] = self::get_post_id();

            // override any vars WordPress set based on the original query
            $query->is_single = TRUE;
            $query->is_singular = TRUE;
            $query->is_404 = FALSE;
            $query->is_home = FALSE;
        }
    }

    /**
     * Override the global $pages array to yield our content
     *
     * @param object $post
     * @return void
     * @noinspection PhpUnusedParameterInspection
     */
    public function set_post_contents( object $post ) {
        global $pages;
        $pages = array($this->contents);
        // TODO: add a facility for multi-page documents?
    }

    /**
     * Set the title for the placeholder page
     *
     * @param string $title
     * @param int $post_id
     * @return string
     */
    public function get_title( string $title, int $post_id ) {
        if ( $post_id == self::get_post_id() ) {
            $title = $this->title;
        }
        return $title;
    }

    /**
     * Set the title for the placeholder page (again)
     *
     * @param string $title
     * @param object|null $post
     * @return string
     */
    public function get_single_post_title( string $title, object $post = NULL ) {
        // in WP 3.0.x, $post might be NULL. Not true in WP 3.1
        if ( !$post ) {
            $post = $GLOBALS['post'];
        }
        return $this->get_title($title, $post->ID);
    }

    /**
     * Use the specified template file
     *
     * @param string $template
     * @return string
     */
    public function override_template( string $template ) {
        if ( $this->template && file_exists($template) ) { // these checks shouldn't be necessary, but no harm
            return $this->template;
        }
        return $template;
    }

    /**
     * If asking for a paged router page, or if %category% is in
     * the permastruct, WordPress will try to redirect to the URL
     * for the dummy page. This should stop that from happening.
     *
     * @wordpress-filter redirect_canonical
     * @param string $redirect_url
     * @param string $requested_url
     * @return bool
     * @noinspection PhpUnusedParameterInspection
     */
    public function override_redirect( string $redirect_url, string $requested_url ) {
        if ( $redirect_url && get_query_var('WP_Route') ) {
            return FALSE;
        }
        if ( $redirect_url && get_permalink(self::get_post_id()) == $redirect_url ) {
            return FALSE;
        }
        return $redirect_url;
    }

    /**
     * Some plugins expect certain data in post meta. It obviously
     * won't be there for the placeholder page. Let's pretend
     * it is there.
     *
     * @param array|null $meta
     * @param int $post_id
     * @param string $meta_key
     * @param bool $single
     * @return array
     * @noinspection PhpUnusedParameterInspection
     */
    public function set_post_meta( ?array $meta, int $post_id, string $meta_key, bool $single ) {
        if ( $post_id == self::get_post_id() ) {
            if ( empty($this->meta) ) {
                $this->meta = array(
                  '_yoast_wpseo_title' => array($this->get_title('', $post_id)),
                );
                $this->meta = apply_filters('wp_router_placeholder_postmeta', $this->meta);
            }
            if ( $meta_key ) {
                if ( empty($this->meta[$meta_key]) ) {
                    return NULL;
                }
                return $this->meta[$meta_key];
            }
            return $this->meta;
        }
        return $meta;
    }

    /**
     * @param string $post_link
     * @param WP_Post $post
     * @param string $leavename
     * @param string $sample
     * @return string|void
     * @noinspection PhpUnusedParameterInspection
     */
    public function override_permalink( string $post_link, WP_Post $post, string $leavename, string $sample ) {
        if ( $post->ID == self::get_post_id() ) {
            if ( $post->ID == get_queried_object_id() ) {
                global $wp;
                return home_url($wp->request);
            }
        }
        return $post_link;
    }
}