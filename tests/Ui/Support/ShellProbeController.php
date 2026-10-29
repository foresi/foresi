<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Support;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Test-only page that renders the app layout with a given title and breadcrumbs.
 */
final class ShellProbeController extends AbstractController
{
    #[Route('/_test/shell', name: 'test_shell_probe', methods: ['GET'])]
    public function __invoke(Request $request, Environment $twig): Response
    {
        $template = $twig->createTemplate(<<<'TWIG'
            {% extends 'layout/app.html.twig' %}
            {% set crumbs = [{label: 'Contacts', href: '/contacts'}, {label: title}] %}
            {% block title %}{{ title }}{% endblock %}
            {% block content %}<p>probe</p>{% endblock %}
            TWIG);

        return new Response($template->render(['title' => $request->query->getString('title')]));
    }
}
