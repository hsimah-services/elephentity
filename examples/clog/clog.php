<?php

declare(strict_types=1);

/*
 * Plugin Name: Clog
 * Description: The Elephentity example, as a WordPress plugin.
 * Requires PHP: 8.3
 */

namespace Clog;

use Eleph\WordPress\Database\WpdbDatabase;
use wpdb;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

/**
 * The whole plugin.
 *
 * Elephentity ships as a Composer library rather than a plugin, so this file is the
 * adaptor between WordPress's lifecycle and an ordinary PHP object graph — which is
 * why there is so little of it.
 *
 * Three hooks, and the order matters:
 *
 *   activation         create or migrate the tables, before anything can query them
 *   init               register the post types, which WordPress requires on this hook
 *   before_delete_post clean up rows whose post row went behind the framework's back
 *   plugins_loaded     assemble the runtime and hand it to whatever speaks a protocol
 */
function bootstrap(): Bootstrap
{
    /** @var Bootstrap|null $bootstrap */
    static $bootstrap = null;

    if (null !== $bootstrap) {
        return $bootstrap;
    }

    global $wpdb;

    assert($wpdb instanceof wpdb);

    return $bootstrap = new Bootstrap(new WpdbDatabase($wpdb));
}

register_activation_hook(__FILE__, static function (): void {
    $plan = bootstrap()->install();

    // A refusal means the plan was not applied at all. Failing activation is the
    // honest response: a schema two states from the spec is worse than no plugin.
    if (!$plan->isSafe()) {
        wp_die(esc_html(sprintf(
            "Clog could not migrate its tables:\n\n%s",
            implode("\n", array_map(static fn ($refusal): string => $refusal->describe(), $plan->refusals)),
        )));
    }
});

add_action('init', static function (): void {
    // WordPress requires post type registration on this hook and no earlier.
    bootstrap()->postTypes()->register();
});

add_action('before_delete_post', static function (int $postId): void {
    // The out-of-band case framework enforcement cannot see: someone emptying the
    // trash, or another plugin calling wp_delete_post().
    bootstrap()->orphanGuard()->onPostDeleted($postId);
});

add_action('plugins_loaded', static function (): void {
    // BootCheck runs here and throws by name if anything under generated/*/Contract/
    // has no implementation, so the failure is a startup failure rather than a
    // surprise on the first request that happens to need the missing class.
    bootstrap()->graphql()->boot();
});
