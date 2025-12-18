<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Rendering;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Support\Stats;
use Twig\Environment;

/**
 * Handles view rendering with scope-based caching.
 *
 * Manages Twig template rendering and caching strategies
 * based on context scope (TAB, ROUTE, SESSION, GLOBAL, custom).
 */
class ViewRenderer {
    public function __construct(
        private Environment $twig,
        private ViewCache $cache,
        private Stats $stats,
        private Logger $logger
    ) {}

    /**
     * Render a view function with scope-based caching.
     *
     * @param callable    $viewFn   View function to execute
     * @param bool        $isUpdate Whether this is an update render
     * @param string      $scope    Primary scope for caching
     * @param Context     $context  Context for logging
     * @param null|string $route    Route for logging
     *
     * @return string Rendered HTML
     */
    public function renderView(
        callable $viewFn,
        bool $isUpdate,
        string $scope,
        Context $context,
        ?string $route = null
    ): string {
        // Check if this scope supports caching (non-TAB scopes)
        $shouldCache = $scope !== Scope::TAB;

        if ($shouldCache) {
            // Try to get cached view
            $cached = $this->cache->get($scope);
            if ($cached !== null && !$isUpdate) {
                $this->logger->debug("Using cached view for scope: {$scope}", $context);

                return $cached;
            }

            $this->logger->debug("Rendering view for scope: {$scope} (cache miss or update)", $context);

            $startTime = microtime(true);
            $result = $viewFn($isUpdate);
            $duration = microtime(true) - $startTime;
            $this->stats->trackRender($duration);

            $this->cache->set($scope, $result);

            return $result;
        }

        // TAB SCOPE: Render per context, no caching
        $this->logger->debug("Rendering TAB-scoped view for {$route} (no cache)", $context);

        $startTime = microtime(true);
        $result = $viewFn($isUpdate);
        $duration = microtime(true) - $startTime;
        $this->stats->trackRender($duration);

        return $result;
    }

    /**
     * Render a Twig template.
     *
     * @param string               $template Template name
     * @param array<string, mixed> $data     Data to pass to template
     * @param null|string          $block    Optional block name to render
     *
     * @return string Rendered HTML
     */
    public function renderTemplate(string $template, array $data = [], ?string $block = null): string {
        if ($block !== null) {
            // Render only the specified block
            $twigTemplate = $this->twig->load($template);

            return $twigTemplate->renderBlock($block, $data);
        }

        return $this->twig->render($template, $data);
    }

    /**
     * Render a Twig template from string.
     *
     * @param string               $template Template string
     * @param array<string, mixed> $data     Data to pass to template
     *
     * @return string Rendered HTML
     */
    public function renderString(string $template, array $data = []): string {
        return $this->twig->createTemplate($template)->render($data);
    }

    /**
     * Get the Twig environment.
     */
    public function getTwig(): Environment {
        return $this->twig;
    }
}
