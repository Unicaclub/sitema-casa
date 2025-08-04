<?php

declare(strict_types=1);

namespace ERP\Core\Container;

use Closure;

/**
 * Contextual Binding Builder
 * 
 * Allows for contextual dependency injection bindings
 * 
 * @package ERP\Core\Container
 */
final class ContextualBindingBuilder
{
    public function __construct(
        private Container $container,
        private string $concrete
    ) {}
    
    /**
     * Define the abstract target that depends on the context
     */
    public function needs(string $abstract): self
    {
        $this->needs = $abstract;
        return $this;
    }
    
    /**
     * Define the implementation that should be used
     */
    public function give(Closure|string $implementation): void
    {
        $this->container->addContextualBinding(
            $this->concrete,
            $this->needs,
            $implementation
        );
    }
    
    /**
     * Define a tagged service that should be used
     */
    public function giveTagged(string $tag): void
    {
        $this->give(fn($container) => $container->tagged($tag));
    }
    
    /**
     * Specify the configuration item to wrap in a closure
     */
    public function giveConfig(string $key): void
    {
        $this->give(fn($container) => $container->get('config')->get($key));
    }
}