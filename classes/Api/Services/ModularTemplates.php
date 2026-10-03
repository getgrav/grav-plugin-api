<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;

/**
 * Answers whether a module's template exists on this site (getgrav/grav-plugin-api#55).
 *
 * A module whose template Twig cannot find makes the page it belongs to render
 * core's red "template not found" heading, where an ordinary page just falls
 * back to the theme's `default.html.twig`. That is why only modules are asked
 * about: a headless site may use page types no theme or blueprint knows, and
 * those keep working.
 *
 * The API lets such a module be written, and tells the caller instead of
 * refusing it: a developer may be part way through building the template.
 * Answers are remembered per template name, so a page tree full of modules is
 * one lookup per distinct template, not one per row.
 */
final class ModularTemplates
{
    /** @var array<string, bool> template name to "it does not exist" */
    private array $missing = [];

    private bool $typesAsked = false;

    /** @var list<string>|null */
    private ?array $types = null;

    public function __construct(private readonly Grav $grav) {}

    /**
     * The registered modular types: what the theme's and plugins' blueprints
     * and `templates/modular/` folders declare, and what the admin offers.
     *
     * @return list<string>|null null when the registry cannot be asked
     */
    public function types(): ?array
    {
        if (!$this->typesAsked) {
            $this->types = $this->askTypes();
            $this->typesAsked = true;
        }

        return $this->types;
    }

    /**
     * The registered spelling of `$template` (`modular/hero` for `Hero`), or
     * null when it is not a registered type. Case does not matter, so `Hero`
     * does not become a `Hero.md` that only renders on a case-insensitive
     * filesystem.
     */
    public function registered(string $template): ?string
    {
        foreach ($this->types() ?? [] as $type) {
            if (strcasecmp($type, $template) === 0) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Whether a module rendering with `$template` (as `Page::template()` reports
     * it, so `modular/<name>` unless a `template` header says otherwise) would
     * hit core's "template not found" heading.
     *
     * False when the registry or Twig cannot be asked: a warning that might be
     * wrong is worse than none.
     */
    public function isMissing(string $template): bool
    {
        $template = trim($template);

        return $this->missing[$template] ??= $this->lookUp($template);
    }

    /** @param list<string> $types */
    public function hint(array $types): string
    {
        return $types
            ? 'Modular types on this site: ' . implode(', ', $types) . '.'
            : 'This site has no modular types: its theme has no templates/modular folder.';
    }

    /**
     * The warning for a module that will render with a template this site does
     * not have, or null when it exists (or cannot be checked).
     *
     * @param string $field `template`, or `header.template` when a header sets it
     * @return array{field: string, code: string, message: string}|null
     */
    public function warning(string $template, string $field = 'template'): ?array
    {
        $template = trim($template);
        if (!$this->isMissing($template)) {
            return null;
        }

        $where = $field === 'header.template' ? "The 'template' header names '{$template}', which" : "Template '{$template}'";

        return [
            'field' => $field,
            'code' => 'template_missing',
            'message' => $where . " doesn't exist on this site, so the page this module belongs to will show a 'template not found' error until it does. "
                . $this->hint($this->types() ?? []),
        ];
    }

    private function lookUp(string $template): bool
    {
        $types = $this->types();
        if ($types === null || $template === '') {
            return false;
        }

        if (in_array($template, $types, true)) {
            return false;
        }

        return $this->twigHas($template) === false;
    }

    /** @return list<string>|null */
    private function askTypes(): ?array
    {
        $pages = $this->grav['pages'];
        if (!method_exists($pages, 'types') || !method_exists($pages, 'modularTypes')) {
            return null;
        }

        try {
            // Core always registers `default`, so an empty list of page types
            // means the registry was never built (the theme was not ready).
            if (!$pages::types()) {
                return null;
            }

            return array_map('strval', array_keys($pages::modularTypes()));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether Twig can find `<template>.html.twig`, the test core applies when
     * it renders a module. Null when Twig cannot be asked.
     *
     * A plugin that only adds a Twig path never registers its templates as
     * types (lightbox-gallery's `modular/lightbox`), so the type list alone
     * would flag modules that render fine.
     *
     * `modular/default` is the exception. Core ships that template itself, and
     * it IS the "template not found" heading, so Twig always finds it. It only
     * counts when a theme has its own, which registers it as a type.
     */
    private function twigHas(string $template): ?bool
    {
        if ($template === 'modular/default') {
            return false;
        }

        try {
            if (!isset($this->grav['twig'])) {
                return null;
            }

            // The API answers ahead of TwigProcessor, so Twig may not be built
            // yet. init() does nothing once it has run.
            $twig = $this->grav['twig'];
            $twig->init();

            return (bool) $twig->twig()->getLoader()->exists($template . '.html.twig');
        } catch (\Throwable) {
            return null;
        }
    }
}
